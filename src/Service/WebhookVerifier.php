<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies that an inbound webhook actually came from Zelty.
 *
 * Zelty signs each webhook with HMAC-SHA256 using the secret_key we
 * registered via POST /webhooks. The signature is sent in the
 * X-Zelty-Signature header (or similar — adjust constant if Zelty uses
 * a different header name).
 *
 * Without this check, anyone who knows our /on-order URL could POST
 * fake orders and mint unlimited loyalty points.
 */
class WebhookVerifier
{
    /**
     * Adjust this if Zelty uses a different header name.
     * Common options: X-Zelty-Signature, X-Signature, X-Hub-Signature-256
     */

    public function __construct(
        readonly private MerchantSecretStore $secretStore,
    ) {
    }

    /**
     * Verify an inbound webhook request.
     *
     * @param Request $request The incoming webhook request
     * @param string  $restaurantId The restaurant_id from the webhook payload
     * @return bool true if signature is valid and matches the stored secret
     */
public function verify(Request $request, string $restaurantId): bool
{
    $providedSignature = $this->extractSignature($request);

    if (!$providedSignature) {
        return false;
    }

    $secret = $this->secretStore->get($restaurantId);
    if (!$secret) {
        return false;
    }

    $rawBody = $request->getContent();
    $expected = hash_hmac('sha256', $rawBody, $secret);

    $provided = preg_replace('/^sha256=/', '', $providedSignature);

    return hash_equals($expected, $provided);
}

private function extractSignature(Request $request): ?string
{
    foreach ([
        'X-Zelty-Signature',
        'X-Signature',
        'X-Hub-Signature-256',
    ] as $header) {
        $value = $request->headers->get($header);
        if ($value) {
            return $value;
        }
    }

    foreach ($request->headers->all() as $name => $values) {
        if (str_contains(strtolower($name), 'signature') && !empty($values[0])) {
            return $values[0];
        }
    }

    return null;
}
}

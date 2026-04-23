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
    private const SIGNATURE_HEADER = 'X-Zelty-Signature';

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
        $providedSignature = $request->headers->get(self::SIGNATURE_HEADER);
        if (!$providedSignature) {
            return false;
        }

        $secret = $this->secretStore->get($restaurantId);
        if (!$secret) {
            return false;
        }

        $rawBody = $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $secret);

        // Strip any prefix like "sha256=" that some vendors include
        $provided = preg_replace('/^sha256=/', '', $providedSignature);

        // Constant-time comparison to prevent timing attacks
        return hash_equals($expected, $provided);
    }
}

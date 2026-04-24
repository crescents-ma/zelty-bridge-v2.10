<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

class WebhookVerifier
{
    public function __construct(
        readonly private MerchantSecretStore $secretStore,
    ) {
    }

public function verify(Request $request, string $restaurantId): bool
{
    $providedSignature = $this->extractSignature($request);
    $secret = $this->secretStore->get($restaurantId);

    if (!$providedSignature || !$secret) {
        error_log(json_encode([
            'webhook_verify' => 'missing_signature_or_secret',
            'restaurant_id' => $restaurantId,
            'has_signature' => (bool) $providedSignature,
            'has_secret' => (bool) $secret,
            'headers' => $request->headers->all(),
        ]));
        return false;
    }

    $rawBody = $request->getContent();
    $expected = hash_hmac('sha256', $rawBody, $secret);
    $provided = preg_replace('/^sha256=/', '', trim($providedSignature));

    error_log(json_encode([
        'webhook_verify' => 'compare',
        'restaurant_id' => $restaurantId,
        'provided' => $provided,
        'expected' => $expected,
        'match' => hash_equals($expected, $provided),
    ]));

    return hash_equals($expected, $provided);
}

    private function extractSignature(Request $request): ?string
    {
        foreach ([
            'X-Zelty-Hmac-Sha256',
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
            if (str_contains(strtolower($name), 'hmac') && !empty($values[0])) {
                return $values[0];
            }
        }

        return null;
    }
}

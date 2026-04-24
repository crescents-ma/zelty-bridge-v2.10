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
    $rawBody = $request->getContent();

    if (!$providedSignature || !$secret) {
        return false;
    }

    $expected = hash_hmac('sha256', $rawBody, $secret);
    $provided = preg_replace('/^sha256=/', '', trim($providedSignature));

    file_put_contents(
        '/var/www/html/var/log/webhook-debug.log',
        json_encode([
            'restaurant_id' => $restaurantId,
            'provided_raw' => $providedSignature,
            'provided_normalized' => $provided,
            'expected' => $expected,
            'headers' => $request->headers->all(),
            'body' => $rawBody,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

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

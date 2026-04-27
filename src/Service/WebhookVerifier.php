<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class WebhookVerifier
{
    private string $globalSecret;

    public function __construct(
        readonly private MerchantSecretStore $secretStore,
        readonly private LoggerInterface $logger,
    ) {
        $this->globalSecret = (string) ($_SERVER['ZELTY_WEBHOOK_SECRET'] ?? $_ENV['ZELTY_WEBHOOK_SECRET'] ?? '');
    }

    public function verify(Request $request, string $restaurantId): bool
    {
        $secret = $this->secretStore->get($restaurantId) ?: $this->globalSecret;
        if ($secret === '') {
            $this->logger->warning('[webhook_verifier] missing secret', [
                'restaurant_id' => $restaurantId,
            ]);

            return false;
        }

        $providedSignature = $this->extractSignature($request);
        if (!$providedSignature) {
            return false;
        }

        $rawBody = $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $provided = preg_replace('/^sha256=/', '', trim($providedSignature));

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
            if ((str_contains(strtolower($name), 'signature') || str_contains(strtolower($name), 'hmac')) && !empty($values[0])) {
                return $values[0];
            }
        }

        return null;
    }
}

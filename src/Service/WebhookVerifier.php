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
        $merchantSecret = $this->secretStore->get($restaurantId);
        $secret = $merchantSecret ?: $this->globalSecret;

        $this->logger->info('[webhook_verifier] secret source', [
            'restaurant_id' => $restaurantId,
            'has_merchant_secret' => $merchantSecret !== null && $merchantSecret !== '',
            'has_global_secret' => $this->globalSecret !== '',
            'using' => $merchantSecret ? 'merchant' : 'global',
        ]);

        if ($secret === '') {
            $this->logger->warning('[webhook_verifier] missing secret', [
                'restaurant_id' => $restaurantId,
            ]);
            return false;
        }

        $providedSignature = $this->extractSignature($request);

        $this->logger->info('[webhook_verifier] signature presence', [
            'restaurant_id' => $restaurantId,
            'has_signature' => (bool) $providedSignature,
        ]);

        if (!$providedSignature) {
            return false;
        }

        $rawBody = $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $provided = preg_replace('/^sha256=/', '', trim($providedSignature));

        $isValid = hash_equals($expected, $provided);

        $this->logger->info('[webhook_verifier] verification result', [
            'restaurant_id' => $restaurantId,
            'valid' => $isValid,
            'expected_prefix' => substr($expected, 0, 12),
            'provided_prefix' => substr($provided, 0, 12),
        ]);

        return $isValid;
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
                $this->logger->info('[webhook_verifier] signature header found', [
                    'header' => $header,
                ]);
                return $value;
            }
        }

        foreach ($request->headers->all() as $name => $values) {
            if ((str_contains(strtolower($name), 'signature') || str_contains(strtolower($name), 'hmac')) && !empty($values[0])) {
                $this->logger->info('[webhook_verifier] signature header found by scan', [
                    'header' => $name,
                ]);
                return $values[0];
            }
        }

        return null;
    }
}

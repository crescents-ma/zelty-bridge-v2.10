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
        $storedToken = $this->secretStore->get($restaurantId);
        if (!$storedToken) {
            return false;
        }

        $providedToken = (string) $request->query->get('token', '');
        if ($providedToken === '') {
            return false;
        }

        return hash_equals($storedToken, $providedToken);
    }
}

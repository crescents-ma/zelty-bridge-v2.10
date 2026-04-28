<?php

namespace App\Client;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Zelty POS API client — built from the official OpenAPI spec.
 *
 * Auth: Bearer token (securitySchemes.bearer)
 * Base URL: configurable via ZELTY_API_BASE_URI env var
 *
 * All monetary values are in CENTS (integer, no comma).
 * All responses are wrapped: { "resource": ..., "errno": 0 }
 */
class ZeltyClient
{
    private string $baseUrl;

    public function __construct(
        readonly private HttpClientInterface $httpClient,
        readonly private LoggerInterface $logger,
        ParameterBagInterface $params,
    ) {
        $this->baseUrl = rtrim($params->get('zelty_api_base_url'), '/');
    }

    public function ping(string $apiKey): bool
    {
        try {
            $data = $this->get($apiKey, '/customers', ['limit' => 1]);
            return isset($data['errno']) && $data['errno'] === 0;
        } catch (\Exception $e) {
            $this->logger->warning('[zelty] ping failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getTags(
        string $apiKey,
        bool $showAll = false,
        bool $allRestaurants = false
    ): ?array {
        $query = [];
        if ($showAll) {
            $query['show_all'] = 'true';
        }
        if ($allRestaurants) {
            $query['all_restaurants'] = 'true';
        }

        $data = $this->get($apiKey, '/catalog/tags', $query);
        return $data['tags'] ?? null;
    }

    public function getDishes(
        string $apiKey,
        bool $showAll = false,
        bool $allRestaurants = false
    ): ?array {
        $query = [];
        if ($showAll) {
            $query['show_all'] = 'true';
        }
        if ($allRestaurants) {
            $query['all_restaurants'] = 'true';
        }
        $data = $this->get($apiKey, '/catalog/dishes', $query);
        return $data['dishes'] ?? null;
    }

    public function listOrders(
        string $apiKey,
        string $from,
        string $to,
        ?string $expand = 'items,customer',
    ): ?array {
        $query = [
            'from' => $from,
            'to' => $to,
        ];
        if ($expand) {
            $query['expand'] = $expand;
        }
        $data = $this->get($apiKey, '/orders', $query);
        return $data['orders'] ?? null;
    }

    public function getOrder(string $apiKey, int|string $orderId, ?string $expand = 'items,customer'): ?array
    {
        $query = $expand ? ['expand' => $expand] : [];
        $data = $this->get($apiKey, '/orders/' . $orderId, $query);
        return $data['order'] ?? null;
    }

    public function getCustomers(string $apiKey, array $filters = []): ?array
    {
        $data = $this->get($apiKey, '/customers', $filters);
        return $data['customers'] ?? null;
    }

    public function getCustomer(string $apiKey, int|string $id): ?array
    {
        $data = $this->get($apiKey, '/customers/' . $id);
        return $data['customer'] ?? null;
    }

    public function addLoyaltyPoints(string $apiKey, int|string $customerId, int $points): bool
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->baseUrl . '/customers/' . $customerId . '/add_loyalty',
                [
                    'headers' => $this->headers($apiKey),
                    'json' => ['points' => $points],
                ]
            );
            $data = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);
            return isset($data['errno']) && $data['errno'] === 0;
        } catch (ExceptionInterface|\JsonException $e) {
            $this->logger->error('[zelty] add_loyalty failed', [
                'customer_id' => $customerId,
                'points' => $points,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function upsertWebhooks(string $apiKey, array $webhooks, string $secretKey): ?array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->baseUrl . '/webhooks',
                [
                    'headers' => $this->headers($apiKey),
                    'json' => [
                        'webhooks' => $webhooks,
                        'secret_key' => $secretKey,
                    ],
                ]
            );

            return json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            $this->logger->error('[zelty] upsertWebhooks failed', [
                'base_url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getWebhooks(string $apiKey): ?array
    {
        $data = $this->get($apiKey, '/webhooks');
        return is_array($data) ? $data : null;
    }

    public function getRestaurants(string $apiKey): ?array
    {
        $data = $this->get($apiKey, '/restaurants');
        return $data['restaurants'] ?? null;
    }

    private function get(string $apiKey, string $path, array $query = []): ?array
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                $this->baseUrl . $path,
                [
                    'headers' => $this->headers($apiKey),
                    'query' => $query,
                ]
            );
            return json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            $this->logger->error('[zelty][debug] GET failed', [
                'path' => $path,
                'query' => $query,
                'base_url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function headers(string $apiKey): array
    {
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }
}

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

    // ========================================================================
    // Credential verification (used by /check-credentials)
    // ========================================================================

    /**
     * Verify a Bearer API key by making a cheap read.
     * GET /customers?limit=1 — returns { customers: [...], errno: 0 }
     */
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

    // ========================================================================
    // Catalog — Tags (categories) + Dishes
    // ========================================================================
    // Zelty's menu structure:
    //   Tags = categories (with id_parent for nesting)
    //   Dishes = items, each with a "tags" array of tag IDs
    //
    // For TRYB inventory we need both, then build the tree.
    // ========================================================================

    /**
     * GET /catalog/tags → { tags: [...], errno: 0 }
     */
    public function getTags(
        string $apiKey,
        bool $showAll = false,
        bool $allRestaurants = false
    ): ?array
    {
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

    /**
     * GET /catalog/dishes → { dishes: [...], errno: 0 }
     *
     * Dish fields used: id, name, price (cents), tags (int[])
     * show_all returns hidden dishes too, all_restaurants includes all sites.
     */
    public function getDishes(
        string $apiKey,
        bool $showAll = false,
        bool $allRestaurants = false
    ): ?array
    {
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

    // ========================================================================
    // Orders
    // ========================================================================

    /**
     * GET /orders?from=...&to=... → { orders: [...], errno: 0 }
     *
     * Max 31-day window. Default limit 2500.
     * Expand options: items, transactions, customer
     *
     * @param string $from YYYY-MM-DD
     * @param string $to   YYYY-MM-DD
     */
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

    /**
     * GET /orders/{id} → { order: { ... }, errno: 0 }
     */
    public function getOrder(string $apiKey, int|string $orderId, ?string $expand = 'items,customer'): ?array
    {
        $query = $expand ? ['expand' => $expand] : [];
        $data = $this->get($apiKey, '/orders/' . $orderId, $query);
        return $data['order'] ?? null;
    }

    // ========================================================================
    // Customers
    // ========================================================================

    /**
     * GET /customers → { customers: [...], errno: 0 }
     * Filter by: phone, mail, cardnum, search, birthday
     */
    public function getCustomers(string $apiKey, array $filters = []): ?array
    {
        $data = $this->get($apiKey, '/customers', $filters);
        return $data['customers'] ?? null;
    }

    /**
     * GET /customers/{id} → { customer: { ... }, errno: 0 }
     */
    public function getCustomer(string $apiKey, int|string $id): ?array
    {
        $data = $this->get($apiKey, '/customers/' . $id);
        return $data['customer'] ?? null;
    }

    /**
     * POST /customers/{id}/add_loyalty
     * Body: { "points": 100 }
     * Response: { customer: { ... }, errno: 0 }
     *
     * Points are DISPLAY-ONLY on the POS — redemption goes via WebView.
     */
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
            $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
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

    // ========================================================================
    // Webhooks — POST /webhooks
    // ========================================================================

    /**
     * Register webhooks.
     * Body: { webhooks: { "order.ended": { target, version } }, secret_key }
     * Set a webhook to null to delete it.
     */
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
            return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            $this->logger->error('[zelty] upsertWebhooks failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ========================================================================
    // Restaurants
    // ========================================================================

    /**
     * GET /restaurants → used to validate the API key and get restaurant list
     */
    public function getRestaurants(string $apiKey): ?array
    {
        $data = $this->get($apiKey, '/restaurants');
        return $data['restaurants'] ?? null;
    }

    // ========================================================================
    // Internal helpers
    // ========================================================================

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
            return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            $this->logger->error('[zelty] GET ' . $path . ' failed', [
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

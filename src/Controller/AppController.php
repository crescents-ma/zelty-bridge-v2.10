<?php

namespace App\Controller;

use App\Client\ZeltyClient;
use App\Client\MarketplaceClient;
use App\DTO\AccrueInput;
use App\DTO\Check;
use App\DTO\Credential;
use App\DTO\Response\GetInventoryResponse;
use App\DTO\Response\InventoryItem;
use App\DTO\ReverseInput;
use App\DTO\Selection;
use App\Service\IdempotencyStore;
use App\Service\MerchantSecretStore;
use App\Service\WebhookVerifier;
use App\Traits\SerializerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

#[AsController]
class AppController
{
    use SerializerAwareTrait;

    private const DEFAULT_CURRENCY = 'MAD';

    /** Reject request bodies larger than this to prevent resource abuse. */
    private const MAX_PAYLOAD_SIZE = 262144; // 256 KB

    private string $publicBaseUrl;

    public function __construct(
        readonly private ZeltyClient $zeltyClient,
        readonly private MarketplaceClient $marketplaceClient,
        readonly private MerchantSecretStore $secretStore,
        readonly private IdempotencyStore $idempotencyStore,
        readonly private WebhookVerifier $webhookVerifier,
        readonly private LoggerInterface $logger,
        ParameterBagInterface $params,
    ) {
        // Public URL of THIS app — used when registering the Zelty webhook.
        // Configured via APP_PUBLIC_URL env var so it's stable regardless
        // of proxy headers.
        $this->publicBaseUrl = rtrim(
            $params->get('app_public_url')
                ?: ($_SERVER['RAILWAY_PUBLIC_DOMAIN'] ?? $_ENV['RAILWAY_PUBLIC_DOMAIN'] ?? ''),
            '/'
        );
        if ($this->publicBaseUrl !== '' && !str_contains($this->publicBaseUrl, '://')) {
            $this->publicBaseUrl = 'https://' . $this->publicBaseUrl;
        }
    }

    #[Route('/health', methods: 'GET')]
    public function health(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

       #[Route('/', methods: 'GET')]
    #[Route('/index.php', methods: 'GET')]
    public function home(): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'service' => 'TRYB Loyalty x Zelty bridge',
            'endpoints' => [
                'health' => 'GET /health',
                'checkCredentials' => 'POST /check-credentials',
                'getInventory' => 'POST /get-inventory',
                'postback' => 'POST /postback',
                'onOrder' => 'POST /on-order',
            ],
        ]);
    }

    #[Route('/check-credentials', methods: 'GET')]
    #[Route('/get-inventory', methods: 'GET')]
    #[Route('/postback', methods: 'GET')]
    #[Route('/on-order', methods: 'GET')]
    public function endpointInfo(Request $request): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'message' => 'This endpoint is live, but it must be called with POST by TRYB or Zelty.',
            'path' => $request->getPathInfo(),
            'method' => 'POST',
        ]);
    }
    // ========================================================================
    // Zelty order.ended webhook receiver
    // ========================================================================

    #[Route('/on-order', methods: 'POST')]
    public function onOrder(Request $request): JsonResponse
    {
        // 1. Size limit
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], 413);
        }

        // 2. Parse payload
        try {
            $payload = $request->toArray();
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $eventId = (string)($payload['event_id'] ?? '');
        $eventName = (string)($payload['event_name'] ?? '');
        $restaurantId = (string)($payload['restaurant_id'] ?? '');
        $data = $payload['data'] ?? [];
        $orderId = (string)($data['id'] ?? '');

        if (!$orderId || !$restaurantId || !$eventId) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // 3. Verify webhook signature — CRITICAL to prevent spoofing
        if (!$this->webhookVerifier->verify($request, $restaurantId)) {
            $this->logger->warning('[zelty_app] invalid webhook signature', [
                'restaurant_id' => $restaurantId,
                'event_id' => $eventId,
            ]);
            return new JsonResponse(['error' => 'Invalid signature'], 401);
        }

        // 4. Idempotency — skip if we've already processed this event
        if (!$this->idempotencyStore->markProcessed($eventId)) {
            $this->logger->info('[zelty_app] duplicate event, skipping', [
                'event_id' => $eventId,
            ]);
            return new JsonResponse(['orderId' => $orderId, 'result' => ['skipped' => 'duplicate']]);
        }

        // 5. Route by event type
        $this->logger->info('[zelty_app] on-order', [
            'event_name' => $eventName,
            'event_id' => $eventId,
            'order_id' => $orderId,
        ]);

        try {
            $result = match ($eventName) {
                'order.ended'
                    => $this->accrueOrder($data, $restaurantId),
                'order.status.update'
                    => $this->handleStatusUpdate($data, $restaurantId),
                default
                    => throw new \Exception(sprintf('Event "%s" not handled', $eventName)),
            };
        } catch (\Exception $e) {
            $this->logger->error('[zelty_app] on-order error', [
                'order_id' => $orderId,
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
            $result = ['error' => $e->getMessage()];
        }

        // 6. Sync earned points to the POS (display only, non-critical)
        if ($eventName === 'order.ended') {
            $this->trySyncPointsToZelty($data, $restaurantId, $result);
        }

        return new JsonResponse(compact('orderId', 'result'));
    }

    private function accrueOrder(array $data, string $restaurantId): ?array
    {
        $customer = $data['customer'] ?? [];
        $contents = $data['contents'] ?? [];

        $selections = [];
        foreach ($contents as $item) {
            $itemPrice = 0;
            if (is_int($item['price'] ?? null)) {
                $itemPrice = $item['price'];
            } elseif (is_array($item['price'] ?? null)) {
                $itemPrice = (int)($item['price']['final_amount_inc_tax'] ?? 0);
            }

            $selections[] = (new Selection())
                ->setId((string)($item['item_id'] ?? $item['id'] ?? ''))
                ->setDisplayName((string)($item['name'] ?? 'Unknown item'))
                ->setGroupId(null)
                ->setPrice($itemPrice)
                ->setTotalPrice($itemPrice)
                ->setQuantity(max(1, (int)($item['quantity'] ?? 1)));
        }

        $totalCents = max(0, (int)($data['price'] ?? 0));

        $check = (new Check())
            ->setAmount($totalCents)
            ->setCurrency(self::DEFAULT_CURRENCY)
            ->setSelections($selections);

        $input = (new AccrueInput())
            ->setTransactionId((string)$data['id'])
            ->setCredentials([
                // restaurant_id identifies which TRYB merchant owns this order
                Credential::create('zelty_restaurant_id', $restaurantId),
            ])
            ->setPhone($customer['phone'] ?? null)
            ->setEmail($customer['mail'] ?? null)
            ->setFirstName($customer['fname'] ?? null)
            ->setLastName($customer['name'] ?? null)
            ->setCheck($check);

        return $this->marketplaceClient->accrue($input);
    }

    /**
     * Handle order.status.update — if status became "cancelled" after we
     * previously accrued, reverse the points on TRYB.
     */
    private function handleStatusUpdate(array $data, string $restaurantId): ?array
    {
        $status = (string)($data['status'] ?? '');
        $orderId = (string)($data['id'] ?? '');

        if ($status !== 'cancelled') {
            return ['skipped' => 'status=' . $status];
        }

        $input = (new ReverseInput())
            ->setTransactionId($orderId)
            ->setCredentials([
                Credential::create('zelty_restaurant_id', $restaurantId),
            ]);

        return $this->marketplaceClient->reverse($input);
    }

    private function trySyncPointsToZelty(array $data, string $restaurantId, ?array $result): void
    {
        $pointsAwarded = $result['pointsAwarded']
            ?? $result['points_awarded']
            ?? $result['results'][0]['accruedValue']
            ?? null;
        $customerId = $data['customer']['id'] ?? null;

        if (!$pointsAwarded || !$customerId) {
            return;
        }

        try {
            $apiKey = $this->marketplaceClient->resolveCredential(
                'zelty_api_key',
                'zelty_restaurant_id',
                $restaurantId,
            );

            if ($apiKey) {
                $this->zeltyClient->addLoyaltyPoints($apiKey, $customerId, (int)$pointsAwarded);
            }
        } catch (\Exception $e) {
            $this->logger->warning('[zelty_app] sync points to POS failed', [
                'restaurant_id' => $restaurantId,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ========================================================================
    // Check credentials (called by TRYB at install)
    // ========================================================================

    #[Route('/check-credentials', methods: 'POST')]
    public function checkCredentials(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], 413);
        }

        // Note: we do NOT log the credential value. Only log that the endpoint was hit.
        $this->logger->info('[zelty_app] check-credentials');

        $apiKey = $this->getCredential($request, 'zelty_api_key');
        $isValid = $apiKey && $this->zeltyClient->ping($apiKey);

        return new JsonResponse(compact('isValid'));
    }

    // ========================================================================
    // Get inventory (called by TRYB at install & rule setup)
    // ========================================================================

    #[Route('/get-inventory', methods: 'POST')]
    public function getInventory(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], 413);
        }

        $this->logger->info('[zelty_app] get-inventory');

        $apiKey = $this->getCredential($request, 'zelty_api_key');
        if (!$apiKey) {
            throw new AccessDeniedHttpException('Missing zelty_api_key credential');
        }

        $tags = $this->zeltyClient->getTags($apiKey, showAll: true);
        $dishes = $this->zeltyClient->getDishes($apiKey, showAll: true, allRestaurants: true);

        if ($tags === null || $dishes === null) {
            throw new \RuntimeException('Could not fetch Zelty catalog');
        }

        // Index dishes by their tag IDs
        $dishesByTag = [];
        foreach ($dishes as $dish) {
            foreach ($dish['tags'] ?? [] as $tagId) {
                $dishesByTag[$tagId][] = $dish;
            }
        }

        // Build top-level groups
        $items = [];
        $itemsById = []; // for nested sub-tag lookup

        foreach ($tags as $tag) {
            $tagId = $tag['id'] ?? null;
            if ($tagId === null) {
                continue;
            }

            // Fixed: use numeric cast for strict 0 check
            $parentId = (int)($tag['id_parent'] ?? 0);
            if ($parentId !== 0) {
                continue;
            }

            $tagDishes = $dishesByTag[$tagId] ?? [];
            $inventoryItem = (new InventoryItem())
                ->setType(InventoryItem::TYPE_GROUP)
                ->setId((string)$tagId)
                ->setTitle((string)($tag['name'] ?? 'Unnamed category'))
                ->setItems(
                    array_map(
                        fn(array $dish) => (new InventoryItem())
                            ->setType(InventoryItem::TYPE_ITEM)
                            ->setId((string)($dish['id'] ?? ''))
                            ->setTitle((string)($dish['name'] ?? 'Unnamed dish')),
                        $tagDishes,
                    ) ?: null
                );

            $items[] = $inventoryItem;
            $itemsById[(string)$tagId] = $inventoryItem;
        }

        // Nest sub-tags under their parents
        foreach ($tags as $tag) {
            $tagId = $tag['id'] ?? null;
            if ($tagId === null) {
                continue;
            }

            $parentId = (int)($tag['id_parent'] ?? 0);
            if ($parentId === 0) {
                continue;
            }

            $parentItem = $itemsById[(string)$parentId] ?? null;
            if (!$parentItem) {
                continue;
            }

            $subDishes = $dishesByTag[$tagId] ?? [];
            $subGroup = (new InventoryItem())
                ->setType(InventoryItem::TYPE_GROUP)
                ->setId((string)$tagId)
                ->setTitle((string)($tag['name'] ?? 'Unnamed subcategory'))
                ->setItems(
                    array_map(
                        fn(array $dish) => (new InventoryItem())
                            ->setType(InventoryItem::TYPE_ITEM)
                            ->setId((string)($dish['id'] ?? ''))
                            ->setTitle((string)($dish['name'] ?? 'Unnamed dish')),
                        $subDishes,
                    ) ?: null
                );

            $existing = $parentItem->getItems() ?? [];
            $existing[] = $subGroup;
            $parentItem->setItems($existing);
        }

        $response = (new GetInventoryResponse())->setInventoryItems($items);
        $responseContent = $this->getSerializer()->serialize($response, JsonEncoder::FORMAT, [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);

        return new JsonResponse($responseContent, json: true);
    }

    // ========================================================================
    // Postback — auto-register the Zelty webhook after merchant installs
    // ========================================================================

    #[Route('/postback', methods: 'POST')]
    public function postback(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], 413);
        }

        $payload = $request->toArray();

        // Don't log the full payload — it contains the API key
        $this->logger->info('[zelty_app] postback');

        $apiKey = null;
        $restaurantId = null;
        foreach ($payload['credentials'] ?? [] as $cred) {
            if (($cred['name'] ?? '') === 'zelty_api_key') {
                $apiKey = $cred['value'] ?? null;
            }
            if (($cred['name'] ?? '') === 'zelty_restaurant_id') {
                $restaurantId = $cred['value'] ?? null;
            }
        }

        // If restaurant_id wasn't provided in credentials, fetch it from Zelty
        if ($apiKey && !$restaurantId) {
            $restaurants = $this->zeltyClient->getRestaurants($apiKey);
            if (is_array($restaurants) && !empty($restaurants)) {
                $restaurantId = (string)($restaurants[0]['id'] ?? '');
            }
        }

        if (!$apiKey || !$restaurantId) {
            return new JsonResponse(['ok' => false, 'error' => 'Missing credentials'], 400);
        }

        if ($this->publicBaseUrl === '' || !str_starts_with($this->publicBaseUrl, 'https://')) {
            return new JsonResponse(['ok' => false, 'error' => 'APP_PUBLIC_URL must be a valid https URL'], 500);
        }

        // Generate a webhook signing secret, store it, and register with Zelty
        $secret = bin2hex(random_bytes(32)); // 256 bits
        $stored = $this->secretStore->store($restaurantId, $secret);

        if (!$stored) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not store secret'], 500);
        }

        $webhookTarget = $this->publicBaseUrl . '/on-order';
        $result = $this->zeltyClient->upsertWebhooks($apiKey, [
            'order.ended' => [
                'target' => $webhookTarget,
                'version' => 'v2',
            ],
            'order.status.update' => [
                'target' => $webhookTarget,
                'version' => 'v2',
            ],
        ], $secret);

        if ($result === null) {
            // Webhook registration failed; remove the orphan secret.
            $this->secretStore->delete($restaurantId);

            return new JsonResponse([
                'ok' => false,
                'error' => 'Webhook registration failed',
                'details' => method_exists($this->zeltyClient, 'getLastError')
                    ? $this->zeltyClient->getLastError()
                    : null,
            ], 502);
        }

        return new JsonResponse(['ok' => true]);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function getCredential(Request $request, string $name): ?string
    {
        try {
            $body = $request->toArray();
        } catch (\Exception) {
            return null;
        }

        return current(
            array_filter(
                $body['credentials'] ?? [],
                fn($item) => is_array($item) && $name === ($item['name'] ?? null)
            )
        )['value'] ?? null;
    }
}

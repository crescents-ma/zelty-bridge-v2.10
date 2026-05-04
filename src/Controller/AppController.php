<?php

namespace App\Controller;

use App\Client\MarketplaceClient;
use App\Client\ZeltyClient;
use App\DTO\AccrueInput;
use App\DTO\Check;
use App\DTO\Credential;
use App\DTO\Response\GetInventoryResponse;
use App\DTO\Response\InventoryItem;
use App\DTO\ReverseInput;
use App\DTO\Selection;
use App\Service\MerchantCredentialStore;
use App\Service\IdempotencyStore;
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

    private const DEFAULT_CURRENCY = 'EUR';
    private const MAX_PAYLOAD_SIZE = 262144; // 256 KB

    private string $publicBaseUrl;

    public function __construct(
        readonly private ZeltyClient $zeltyClient,
        readonly private MarketplaceClient $marketplaceClient,
        readonly private MerchantCredentialStore $credentialStore,
        readonly private IdempotencyStore $idempotencyStore,
        readonly private WebhookVerifier $webhookVerifier,
        readonly private LoggerInterface $logger,
        ParameterBagInterface $params,
    ) {
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

    #[Route('/on-order', methods: 'POST')]
    public function onOrder(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], 413);
        }

        try {
            $payload = $request->toArray();
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $eventId = (string) ($payload['event_id'] ?? '');
        $eventName = (string) ($payload['event_name'] ?? '');
        $restaurantId = (string) ($payload['restaurant_id'] ?? '');
        $data = $payload['data'] ?? [];
        $orderId = (string) ($data['id'] ?? '');

        if (!$orderId || !$restaurantId || !$eventId) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        if (!$this->webhookVerifier->verify($request, $restaurantId)) {
            $this->logger->warning('[zelty_app] invalid webhook signature', [
                'restaurant_id' => $restaurantId,
                'event_id' => $eventId,
            ]);

            return new JsonResponse(['error' => 'Invalid signature'], 401);
        }

        if (!$this->idempotencyStore->markProcessed($eventId)) {
            $this->logger->info('[zelty_app] duplicate event, skipping', [
                'event_id' => $eventId,
            ]);

            return new JsonResponse([
                'orderId' => $orderId,
                'result' => ['skipped' => 'duplicate'],
            ]);
        }

        $this->logger->info('[zelty_app] on-order', [
            'event_name' => $eventName,
            'event_id' => $eventId,
            'order_id' => $orderId,
        ]);

        try {
            $result = match ($eventName) {
                'order.ended' => $this->accrueOrder($data, $restaurantId),
                'order.status.update' => $this->handleStatusUpdate($data, $restaurantId),
                default => throw new \Exception(sprintf('Event "%s" not handled', $eventName)),
            };
        } catch (\Exception $e) {
            $this->logger->error('[zelty_app] on-order error', [
                'order_id' => $orderId,
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);

            $result = ['error' => $e->getMessage()];
        }

        if ($eventName === 'order.ended') {
            $this->trySyncPointsToZelty($data, $restaurantId, $result);
        }

        return new JsonResponse(compact('orderId', 'result'));
    }

    private function accrueOrder(array $data, string $restaurantId): ?array
    {
        $apiKey = $this->credentialStore->get($restaurantId);
        if (!$apiKey) {
            throw new \RuntimeException(sprintf('No stored zelty_api_key for restaurant %s', $restaurantId));
        }

        $dishGroupMap = $this->buildDishGroupMap($apiKey);

        $customer = $data['customer'] ?? [];
        $orderItems = $this->resolveOrderItems($data);

        $selections = [];
        foreach ($orderItems as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $itemPrice = $this->resolveItemUnitPrice($item);
            $itemTotalPrice = $this->resolveItemTotalPrice($item, $quantity, $itemPrice);

            $selections[] = (new Selection())
                ->setId((string) ($item['item_id'] ?? $item['id'] ?? ''))
                ->setDisplayName((string) ($item['name'] ?? 'Unknown item'))
                ->setGroupId($this->resolveSelectionGroupId($item, $dishGroupMap))
                ->setPrice($itemPrice)
                ->setTotalPrice($itemTotalPrice)
                ->setQuantity($quantity);
        }

        $totalCents = $this->resolveOrderTotalCents($data, $selections);

        $check = (new Check())
            ->setAmount($totalCents)
            ->setCurrency($this->resolveCurrency($data))
            ->setSelections($selections);

        $input = (new AccrueInput())
            ->setTransactionId((string) $data['id'])
            ->setCredentials([
                Credential::create('zelty_api_key', $apiKey),
                Credential::create('zelty_restaurant_id', $restaurantId),
            ])
            ->setPhone($customer['phone'] ?? null)
            ->setEmail($customer['mail'] ?? null)
            ->setFirstName($customer['fname'] ?? null)
            ->setLastName($customer['name'] ?? null)
            ->setCheck($check);

        $result = $this->marketplaceClient->accrue($input);

        $this->logger->info('[zelty_app] marketplace accrue response: ' . json_encode([
            'restaurant_id' => $restaurantId,
            'transaction_id' => (string) $data['id'],
            'response' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($result === null) {
            throw new \RuntimeException('Marketplace accrue request failed');
        }

        $firstResult = $result['results'][0] ?? null;
        if (is_array($firstResult) && array_key_exists('isSuccess', $firstResult) && $firstResult['isSuccess'] === false) {
            throw new \RuntimeException('Marketplace accrue rejected: ' . json_encode($firstResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $result;
    }

    private function handleStatusUpdate(array $data, string $restaurantId): ?array
    {
        $apiKey = $this->credentialStore->get($restaurantId);
        if (!$apiKey) {
            throw new \RuntimeException(sprintf('No stored zelty_api_key for restaurant %s', $restaurantId));
        }

        $status = (string) ($data['status'] ?? '');
        $orderId = (string) ($data['id'] ?? '');

        if ($status !== 'cancelled') {
            return ['skipped' => 'status=' . $status];
        }

        $input = (new ReverseInput())
            ->setTransactionId($orderId)
            ->setCredentials([
                Credential::create('zelty_api_key', $apiKey),
                Credential::create('zelty_restaurant_id', $restaurantId),
            ]);

        $result = $this->marketplaceClient->reverse($input);

        $this->logger->info('[zelty_app] marketplace reverse response: ' . json_encode([
            'restaurant_id' => $restaurantId,
            'transaction_id' => $orderId,
            'response' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($result === null) {
            throw new \RuntimeException('Marketplace reverse request failed');
        }

        $firstResult = $result['results'][0] ?? null;
        if (is_array($firstResult) && array_key_exists('isSuccess', $firstResult) && $firstResult['isSuccess'] === false) {
            throw new \RuntimeException('Marketplace reverse rejected: ' . json_encode($firstResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $result;
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
            $apiKey = $this->credentialStore->get($restaurantId);

            if ($apiKey) {
                $this->zeltyClient->addLoyaltyPoints($apiKey, $customerId, (int) $pointsAwarded);
            }
        } catch (\Exception $e) {
            $this->logger->warning('[zelty_app] sync points to POS failed', [
                'restaurant_id' => $restaurantId,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    #[Route('/check-credentials', methods: 'POST')]
    public function checkCredentials(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], 413);
        }

        $this->logger->info('[zelty_app] check-credentials');

        $apiKey = $this->getCredential($request, 'zelty_api_key');
        $isValid = $apiKey && $this->zeltyClient->ping($apiKey);

        $this->logger->error('[zelty_app][debug] check-credentials called', [
            'has_api_key' => (bool) $apiKey,
            'is_valid' => (bool) $isValid,
        ]);

        return new JsonResponse(compact('isValid'));
    }

    #[Route('/get-inventory', methods: 'POST')]
    public function getInventory(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], 413);
        }

        $this->logger->info('[zelty_app] get-inventory');
        $this->logger->error('[zelty_app][debug] get-inventory called');

        $apiKey = $this->getCredential($request, 'zelty_api_key');

        $this->logger->error('[zelty_app][debug] get-inventory credential check', [
            'has_api_key' => (bool) $apiKey,
        ]);

        if (!$apiKey) {
            throw new AccessDeniedHttpException('Missing zelty_api_key credential');
        }

        $tags = $this->zeltyClient->getTags($apiKey, showAll: true);
        $dishes = $this->zeltyClient->getDishes($apiKey, showAll: true);

        $this->logger->error('[zelty_app][debug] get-inventory zelty response', [
            'tags_is_array' => is_array($tags),
            'tags_count' => is_array($tags) ? count($tags) : null,
            'dishes_is_array' => is_array($dishes),
            'dishes_count' => is_array($dishes) ? count($dishes) : null,
        ]);

        if ($tags === null || $dishes === null) {
            throw new \RuntimeException('Could not fetch Zelty catalog');
        }

        $tagsByParent = [];
        foreach ($tags as $tag) {
            $parentId = (int) ($tag['id_parent'] ?? 0);
            $tagsByParent[$parentId][] = $tag;
        }

        $dishesByTag = [];
        foreach ($dishes as $dish) {
            foreach ($dish['tags'] ?? [] as $tagId) {
                $dishesByTag[(string) $tagId][] = $dish;
            }
        }

        $items = [];
        foreach ($tagsByParent[0] ?? [] as $rootTag) {
            $items[] = $this->buildInventoryGroup($rootTag, $tagsByParent, $dishesByTag);
        }

        $this->logger->error('[zelty_app][debug] get-inventory final tree', [
            'root_groups' => count($tagsByParent[0] ?? []),
            'inventory_items' => count($items),
        ]);

        $response = (new GetInventoryResponse())->setInventoryItems($items);
        $responseContent = $this->getSerializer()->serialize($response, JsonEncoder::FORMAT, [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);

        return new JsonResponse($responseContent, json: true);
    }

    #[Route('/postback', methods: 'POST')]
    public function postback(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], 413);
        }

        try {
            $payload = $request->toArray();
        } catch (\Exception) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON'], 400);
        }

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

        if (!$apiKey || !$restaurantId) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Missing credentials',
                'required' => ['zelty_api_key', 'zelty_restaurant_id'],
            ], 400);
        }

        if ($this->publicBaseUrl === '' || !str_starts_with($this->publicBaseUrl, 'https://')) {
            return new JsonResponse(['ok' => false, 'error' => 'APP_PUBLIC_URL must be a valid https URL'], 500);
        }

        $webhookTarget = $this->publicBaseUrl . '/on-order';

        $existingConfig = $this->zeltyClient->getWebhooks($apiKey);
        $currentSecretKey = $existingConfig['secret_key'] ?? null;

        if (!$currentSecretKey) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Could not read existing Zelty secret_key',
            ], 500);
        }

        $result = $this->zeltyClient->upsertWebhooks($apiKey, [
            'order.ended' => [
                'target' => $webhookTarget,
                'version' => 'v2',
            ],
            'order.status.update' => [
                'target' => $webhookTarget,
                'version' => 'v2',
            ],
        ], $currentSecretKey);

        if ($result === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Webhook registration failed',
            ], 502);
        }

        if (!$this->credentialStore->store($restaurantId, $apiKey)) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Could not store merchant credentials',
            ], 500);
        }

        return new JsonResponse([
            'ok' => true,
            'registered_target' => $webhookTarget,
        ]);
    }

    private function buildInventoryGroup(array $tag, array $tagsByParent, array $dishesByTag): InventoryItem
    {
        $tagId = (string) ($tag['id'] ?? '');
        $childGroups = [];

        foreach ($tagsByParent[(int) ($tag['id'] ?? 0)] ?? [] as $childTag) {
            $childGroups[] = $this->buildInventoryGroup($childTag, $tagsByParent, $dishesByTag);
        }

        $directItems = $this->buildInventoryItems($dishesByTag[$tagId] ?? []);

        $items = $childGroups;
        if ($directItems !== []) {
            if ($childGroups !== []) {
                $items[] = (new InventoryItem())
                    ->setType(InventoryItem::TYPE_GROUP)
                    ->setId($tagId . '__items')
                    ->setTitle('Items')
                    ->setItems($directItems);
            } else {
                $items = $directItems;
            }
        }

        return (new InventoryItem())
            ->setType(InventoryItem::TYPE_GROUP)
            ->setId($tagId)
            ->setTitle((string) ($tag['name'] ?? 'Unnamed category'))
            ->setItems($items);
    }

    private function buildInventoryItems(array $dishes): array
    {
        $items = [];
        $seen = [];

        foreach ($dishes as $dish) {
            $dishId = (string) ($dish['id'] ?? '');
            if ($dishId === '' || isset($seen[$dishId])) {
                continue;
            }

            $seen[$dishId] = true;
            $items[] = (new InventoryItem())
                ->setType(InventoryItem::TYPE_ITEM)
                ->setId($dishId)
                ->setTitle((string) ($dish['name'] ?? 'Unnamed dish'));
        }

        return $items;
    }

    private function buildDishGroupMap(string $apiKey): array
    {
        $dishes = $this->zeltyClient->getDishes($apiKey, showAll: true);
        if (!is_array($dishes)) {
            return [];
        }

        $map = [];
        foreach ($dishes as $dish) {
            $dishId = (string) ($dish['id'] ?? '');
            if ($dishId === '') {
                continue;
            }

            $tags = [];
            foreach ($dish['tags'] ?? [] as $tagId) {
                if (is_scalar($tagId) && (string) $tagId !== '') {
                    $tags[] = (string) $tagId;
                }
            }

            if ($tags !== []) {
                $map[$dishId] = $tags[0];
            }
        }

        return $map;
    }

    private function resolveSelectionGroupId(array $item, array $dishGroupMap): ?string
    {
        foreach ($item['tags'] ?? [] as $tagId) {
            if (is_scalar($tagId) && (string) $tagId !== '') {
                return (string) $tagId;
            }
        }

        $dishId = (string) ($item['item_id'] ?? $item['id'] ?? '');

        return $dishGroupMap[$dishId] ?? null;
    }

    private function resolveOrderItems(array $data): array
    {
        $items = $data['items'] ?? $data['contents'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private function resolveItemUnitPrice(array $item): int
    {
        $rawPrice = $item['price'] ?? null;
        if (is_int($rawPrice) || is_float($rawPrice) || (is_string($rawPrice) && is_numeric($rawPrice))) {
            return max(0, (int) round((float) $rawPrice));
        }

        if (!is_array($rawPrice)) {
            return 0;
        }

        foreach ([
            'discounted_amount_inc_tax',
            'final_amount_inc_tax',
            'original_amount_inc_tax',
            'base_original_amount_inc_tax',
            'amount_inc_tax',
            'amount',
        ] as $field) {
            $value = $rawPrice[$field] ?? null;
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return max(0, (int) round((float) $value));
            }
        }

        return 0;
    }

    private function resolveItemTotalPrice(array $item, int $quantity, int $unitPrice): int
    {
        $rawPrice = $item['price'] ?? null;
        if (is_array($rawPrice)) {
            foreach ([
                'line_discounted_amount_inc_tax',
                'line_final_amount_inc_tax',
                'line_original_amount_inc_tax',
                'total_amount_inc_tax',
            ] as $field) {
                $value = $rawPrice[$field] ?? null;
                if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                    return max(0, (int) round((float) $value));
                }
            }
        }

        return max(0, $unitPrice * max(1, $quantity));
    }

    private function resolveOrderTotalCents(array $data, array $selections): int
    {
        $candidates = [
            $data['price']['final_amount_inc_tax'] ?? null,
            $data['price']['discounted_amount_inc_tax'] ?? null,
            $data['price']['original_amount_inc_tax'] ?? null,
            $data['price']['amount_inc_tax'] ?? null,
            $data['price'] ?? null,
            $data['total_price'] ?? null,
            $data['totalPrice'] ?? null,
            $data['amount_cents'] ?? null,
            $data['total_cents'] ?? null,
            $data['total']['amount_cents'] ?? null,
            $data['total']['cents'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_int($candidate) || is_float($candidate) || (is_string($candidate) && is_numeric($candidate))) {
                return max(0, (int) round((float) $candidate));
            }
        }

        $selectionTotal = 0;
        foreach ($selections as $selection) {
            if ($selection instanceof Selection && $selection->getTotalPrice() !== null) {
                $selectionTotal += max(0, $selection->getTotalPrice());
            }
        }

        return $selectionTotal;
    }

    private function resolveCurrency(array $data): string
    {
        $candidates = [
            $data['currency'] ?? null,
            $data['price_currency'] ?? null,
            $data['price']['currency'] ?? null,
            $data['price']['currency_code'] ?? null,
            $data['total']['currency'] ?? null,
        ];

        foreach ($candidates as $currency) {
            if (is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency)) {
                return strtoupper($currency);
            }
        }

        return self::DEFAULT_CURRENCY;
    }

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

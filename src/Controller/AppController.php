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

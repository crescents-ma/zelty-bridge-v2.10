<?php

namespace App\Client;

use App\DTO\AccrueInput;
use App\DTO\Credential;
use App\DTO\ResolveCredentialsInput;
use App\DTO\ResolveCredentialsOutput;
use App\DTO\ReverseInput;
use App\Traits\SerializerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MarketplaceClient
{
    use SerializerAwareTrait;

    private string $baseUrl;
    private string $appToken;

    private ?int $resolveCredentialsCacheTtl;

    public function __construct(
        readonly private HttpClientInterface $httpClient,
        readonly private LoggerInterface $logger,
        readonly private CacheInterface $cache,
        ParameterBagInterface $params,
    ) {
        $this->baseUrl = $params->get('marketplace_api_base_url');
        $this->appToken = $params->get('marketplace_api_app_token');
        $this->resolveCredentialsCacheTtl = $params->get('marketplace_api_resolve_credentials_cache_ttl');
    }

    public function resolveCredential(string $name, string $fromName, string $fromValue): ?string
    {
        $output = $this->resolveCredentials(
            (new ResolveCredentialsInput())
                ->setNames([$name])
                ->setCredentials([Credential::create($fromName, $fromValue)])
        );

        return $output->getCredentialValue($name);

    }

    public function resolveCredentials(ResolveCredentialsInput $input): ?ResolveCredentialsOutput
    {
        $result = $this->post('/resolve-credentials', $input, cacheTtl: $this->resolveCredentialsCacheTtl);

        return $this->getSerializer()->denormalize($result, ResolveCredentialsOutput::class, JsonEncoder::FORMAT);
    }

    public function accrue(AccrueInput $input): ?array
    {
        return $this->post('/accrue', $input);
    }

    public function reverse(ReverseInput $input): ?array
    {
        return $this->post('/reverse', $input);
    }

    public function get(string $path, ?int $cacheTtl): ?array
    {
        return $this->request('GET', $path, cacheTtl: $cacheTtl);
    }

    public function post(string $path, object|array|null $request = null, ?int $cacheTtl = null): ?array
    {
        return $this->request('POST', $path, $request, $cacheTtl);
    }

    public function request(
        string $method,
        string $path,
        object|array|null $request = null,
        ?int $cacheTtl = null
    ): ?array
    {
        $url = $this->baseUrl . $path;
        $options = [
            'headers' => [
                'X-App-Token' => $this->appToken,
            ],
        ];

        $requestBody = '';
        try {
            if (null !== $request) {
                $requestBody = $this->getSerializer()->serialize($request, JsonEncoder::FORMAT);
                $options['headers']['content-type'] = 'application/json';
                $options['body'] = $requestBody;
            }

            $doRequest = function () use ($method, $url, $options, $requestBody) {

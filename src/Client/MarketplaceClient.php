<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * File-based storage for the Zelty API key associated with a restaurant_id.
 *
 * This lets webhook-driven accruals recover the original merchant API key
 * without depending on TRYB credential resolution by restaurant id.
 */
class MerchantCredentialStore
{
    private string $storagePath;

    public function __construct(
        string $projectDir,
        readonly private LoggerInterface $logger,
    ) {
        $baseStoragePath = $_SERVER['APP_STORAGE_PATH'] ?? $_ENV['APP_STORAGE_PATH'] ?? ($projectDir . '/var');
        $baseStoragePath = rtrim($baseStoragePath, '/\\');
        $this->storagePath = $baseStoragePath . '/credentials';
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0700, true);
        }
    }

    public function store(string $restaurantId, string $apiKey): bool
    {
        $file = $this->filePath($restaurantId);
        $data = json_encode([
            'restaurant_id' => $restaurantId,
            'api_key' => $apiKey,
            'created_at' => date('c'),
        ], JSON_THROW_ON_ERROR);

        $result = file_put_contents($file, $data, LOCK_EX);
        if ($result === false) {
            $this->logger->error('[credential_store] failed to write', ['restaurant_id' => $restaurantId]);
            return false;
        }

        @chmod($file, 0600);

        return true;
    }

    public function get(string $restaurantId): ?string
    {
        $file = $this->filePath($restaurantId);
        if (!is_readable($file)) {
            return null;
        }

        try {
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            return $data['api_key'] ?? null;
        } catch (\JsonException $e) {
            $this->logger->error('[credential_store] corrupt file', [
                'restaurant_id' => $restaurantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function filePath(string $restaurantId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $restaurantId);
        return $this->storagePath . '/merchant_' . $safe . '.json';
    }
}

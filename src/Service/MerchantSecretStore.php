<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * File-based storage for per-merchant webhook secrets.
 *
 * Stores each merchant's Zelty webhook signing secret in a JSON file
 * under var/secrets/. Keyed by restaurant_id. Used to verify incoming
 * Zelty webhooks against their HMAC signature.
 *
 * File-based (not database) so this works on basic shared hosting.
 * The var/ directory is NOT web-accessible (below public/).
 */
class MerchantSecretStore
{
    private string $storagePath;

    public function __construct(
        string $projectDir,
        readonly private LoggerInterface $logger,
    ) {
        $baseStoragePath = $_SERVER['APP_STORAGE_PATH'] ?? $_ENV['APP_STORAGE_PATH'] ?? ($projectDir . '/var');
        $baseStoragePath = rtrim($baseStoragePath, '/\\');
        $this->storagePath = $baseStoragePath . '/secrets';
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0700, true);
        }
    }

    /**
     * Store the webhook signing secret for a merchant.
     */
    public function store(string $restaurantId, string $secret): bool
    {
        $file = $this->filePath($restaurantId);
        $data = json_encode([
            'restaurant_id' => $restaurantId,
            'secret' => $secret,
            'created_at' => date('c'),
        ], JSON_THROW_ON_ERROR);

        $result = file_put_contents($file, $data, LOCK_EX);
        if ($result === false) {
            $this->logger->error('[secret_store] failed to write', ['restaurant_id' => $restaurantId]);
            return false;
        }
        @chmod($file, 0600);
        return true;
    }

    /**
     * Retrieve a merchant's webhook signing secret.
     */
    public function get(string $restaurantId): ?string
    {
        $file = $this->filePath($restaurantId);
        if (!is_readable($file)) {
            return null;
        }

        try {
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            return $data['secret'] ?? null;
        } catch (\JsonException $e) {
            $this->logger->error('[secret_store] corrupt file', [
                'restaurant_id' => $restaurantId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Remove a merchant's secret (on uninstall).
     */
    public function delete(string $restaurantId): bool
    {
        $file = $this->filePath($restaurantId);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }

    private function filePath(string $restaurantId): string
    {
        // Sanitize restaurant_id to prevent path traversal
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $restaurantId);
        return $this->storagePath . '/merchant_' . $safe . '.json';
    }
}

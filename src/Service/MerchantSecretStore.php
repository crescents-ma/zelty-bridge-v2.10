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
    private string $encryptionKey;

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

        $keyMaterial = (string) (
            $_SERVER['APP_ENCRYPTION_KEY']
            ?? $_ENV['APP_ENCRYPTION_KEY']
            ?? $_SERVER['APP_SECRET']
            ?? $_ENV['APP_SECRET']
            ?? ''
        );
        $this->encryptionKey = hash('sha256', $keyMaterial, true);
    }

    /**
     * Store the webhook signing secret for a merchant.
     */
    public function store(string $restaurantId, string $secret): bool
    {
        $file = $this->filePath($restaurantId);
        if ($file === null) {
            $this->logger->error('[secret_store] invalid restaurant id', [
                'restaurant_id' => $restaurantId,
            ]);

            return false;
        }

        $encryptedSecret = $this->encrypt($secret);
        if ($encryptedSecret === null) {
            $this->logger->error('[secret_store] encryption failed', [
                'restaurant_id' => $restaurantId,
            ]);

            return false;
        }

        $data = json_encode([
            'restaurant_id' => $restaurantId,
            'secret' => $encryptedSecret,
            'created_at' => date('c'),
        ], JSON_THROW_ON_ERROR);

        $result = file_put_contents($file, $data, LOCK_EX);
        if ($result === false) {
            $this->logger->error('[secret_store] failed to write', [
                'restaurant_id' => $restaurantId,
            ]);

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
        if ($file === null) {
            return null;
        }

        if (!is_readable($file)) {
            return null;
        }

        try {
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if (!isset($data['secret']) || !is_string($data['secret'])) {
                return null;
            }

            return $this->decrypt($data['secret']);
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
        if ($file === null) {
            return false;
        }

        if (file_exists($file)) {
            return @unlink($file);
        }

        return true;
    }

    private function filePath(string $restaurantId): ?string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $restaurantId);
        if ($safe === '' || $safe !== $restaurantId) {
            return null;
        }

        return $this->storagePath . '/merchant_' . $safe . '.json';
    }

    private function encrypt(string $value): ?string
    {
        if ($this->encryptionKey === '' || !function_exists('openssl_encrypt')) {
            return null;
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $value,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (!is_string($ciphertext) || $tag === '') {
            return null;
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt(string $payload): ?string
    {
        if ($this->encryptionKey === '' || !function_exists('openssl_decrypt')) {
            return null;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 29) {
            return null;
        }

        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return is_string($plaintext) ? $plaintext : null;
    }
}

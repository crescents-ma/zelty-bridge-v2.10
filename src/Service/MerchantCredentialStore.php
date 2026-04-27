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
    private string $encryptionKey;

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

        $keyMaterial = (string) (
            $_SERVER['APP_ENCRYPTION_KEY']
            ?? $_ENV['APP_ENCRYPTION_KEY']
            ?? $_SERVER['APP_SECRET']
            ?? $_ENV['APP_SECRET']
            ?? ''
        );
        $this->encryptionKey = hash('sha256', $keyMaterial, true);
    }

    public function store(string $restaurantId, string $apiKey): bool
    {
        $file = $this->filePath($restaurantId);
        if ($file === null) {
            $this->logger->error('[credential_store] invalid restaurant id', [
                'restaurant_id' => $restaurantId,
            ]);

            return false;
        }

        $encryptedApiKey = $this->encrypt($apiKey);
        if ($encryptedApiKey === null) {
            $this->logger->error('[credential_store] encryption failed', [
                'restaurant_id' => $restaurantId,
            ]);

            return false;
        }

        $data = json_encode([
            'restaurant_id' => $restaurantId,
            'api_key' => $encryptedApiKey,
            'created_at' => date('c'),
        ], JSON_THROW_ON_ERROR);

        $result = file_put_contents($file, $data, LOCK_EX);
        if ($result === false) {
            $this->logger->error('[credential_store] failed to write', [
                'restaurant_id' => $restaurantId,
            ]);

            return false;
        }

        @chmod($file, 0600);

        return true;
    }

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
            if (!isset($data['api_key']) || !is_string($data['api_key'])) {
                return null;
            }

            return $this->decrypt($data['api_key']);
        } catch (\JsonException $e) {
            $this->logger->error('[credential_store] corrupt file', [
                'restaurant_id' => $restaurantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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

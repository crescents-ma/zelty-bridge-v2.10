<?php

namespace App\Service;

/**
 * Tracks processed webhook event_ids to prevent double-accrual on retries.
 *
 * File-based using one file per event id, with automatic pruning of
 * entries older than 7 days. Works on shared hosting without a database.
 */
class IdempotencyStore
{
    private const TTL_SECONDS = 604800; // 7 days

    private string $storagePath;

    public function __construct(string $projectDir)
    {
        $baseStoragePath = $_SERVER['APP_STORAGE_PATH'] ?? $_ENV['APP_STORAGE_PATH'] ?? ($projectDir . '/var');
        $baseStoragePath = rtrim($baseStoragePath, '/\\');
        $this->storagePath = $baseStoragePath . '/idempotency';
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0700, true);
        }
    }

    /**
     * Mark an event_id as processed. Returns true if this was a new event,
     * false if it was already processed or invalid.
     */
    public function markProcessed(string $eventId): bool
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $eventId);
        if ($safe === '' || $safe !== $eventId) {
            return false;
        }

        $file = $this->storagePath . '/' . $safe;

        $fp = @fopen($file, 'x');
        if ($fp === false) {
            return false;
        }

        fwrite($fp, (string) time());
        fclose($fp);
        @chmod($file, 0600);

        if (random_int(1, 100) === 1) {
            $this->prune();
        }

        return true;
    }

    private function prune(): void
    {
        $cutoff = time() - self::TTL_SECONDS;
        foreach (glob($this->storagePath . '/*') as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}

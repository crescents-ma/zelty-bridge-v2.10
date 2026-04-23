<?php

namespace App\Service;

/**
 * Tracks processed webhook event_ids to prevent double-accrual on retries.
 *
 * File-based using a simple append-only log, with automatic pruning of
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
     * false if it was already processed (caller should skip).
     */
    public function markProcessed(string $eventId): bool
    {
        // Sanitize to prevent path traversal
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $eventId);
        if ($safe === '' || $safe !== $eventId) {
            return true; // Treat malformed IDs as new (don't skip processing)
        }

        $file = $this->storagePath . '/' . $safe;

        // Atomic check-and-create using file locking
        $fp = @fopen($file, 'x'); // 'x' fails if file exists
        if ($fp === false) {
            return false; // Already processed
        }

        fwrite($fp, (string)time());
        fclose($fp);
        @chmod($file, 0600);

        // Occasionally prune old entries (1% of requests)
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

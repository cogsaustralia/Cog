<?php
declare(strict_types=1);

/**
 * COGs SimpleCache — lightweight file-based response cache
 * Safe for shared hosting (no APCu/Memcached required)
 * 
 * Usage:
 *   $cache = new SimpleCache('/tmp/cogs_cache');
 *   $data  = $cache->get('community_stats');
 *   if ($data === null) {
 *       $data = expensiveQuery();
 *       $cache->set('community_stats', $data, 300); // 5 min TTL
 *   }
 */
class SimpleCache {

    private string $dir;

    public function __construct(string $cacheDir = '/tmp/cogs_cache') {
        $this->dir = rtrim($cacheDir, '/');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }
    }

    private function path(string $key): string {
        return $this->dir . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.cache';
    }

    /**
     * Get cached value. Returns null if missing or expired.
     */
    public function get(string $key): mixed {
        $path = $this->path($key);
        if (!file_exists($path)) return null;

        $raw = file_get_contents($path);
        if ($raw === false) return null;

        $data = json_decode($raw, true);
        if (!is_array($data)) return null;

        if (time() > ($data['expires'] ?? 0)) {
            @unlink($path);
            return null;
        }

        return $data['value'];
    }

    /**
     * Store a value with TTL in seconds.
     */
    public function set(string $key, mixed $value, int $ttl = 300): bool {
        $path = $this->path($key);
        $payload = json_encode([
            'expires' => time() + $ttl,
            'value'   => $value,
        ]);
        return file_put_contents($path, $payload, LOCK_EX) !== false;
    }

    /**
     * Delete a cached value (call after writes that affect cached data).
     */
    public function delete(string $key): void {
        $path = $this->path($key);
        if (file_exists($path)) @unlink($path);
    }

    /**
     * Clear all cache entries.
     */
    public function flush(): void {
        foreach (glob($this->dir . '/*.cache') ?: [] as $f) {
            @unlink($f);
        }
    }
}

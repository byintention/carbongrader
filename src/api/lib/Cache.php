<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/Fs.php';

/**
 * Simple TTL file cache. Keys are hashed to filenames; each entry stores its
 * expiry so reads self-expire. Clock is injectable for tests. See architecture §10.
 */
final class Cache
{
    /** @var callable():int */
    private $clock;

    /** @param (callable():int)|null $clock */
    public function __construct(private string $dir, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
        Fs::ensureDir($this->dir);
    }

    public function get(string $key): mixed
    {
        $data = Fs::readJson($this->path($key));
        if ($data === null || !array_key_exists('expires', $data)) {
            return null;
        }
        if ($data['expires'] !== null && ($this->clock)() >= $data['expires']) {
            $this->delete($key);
            return null;
        }
        return $data['value'] ?? null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** A TTL of 0 (or less) stores the value without expiry. */
    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $expires = $ttlSeconds > 0 ? ($this->clock)() + $ttlSeconds : null;
        Fs::writeJson($this->path($key), ['expires' => $expires, 'value' => $value]);
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}

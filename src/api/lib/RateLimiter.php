<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/Fs.php';

/**
 * Fixed-window, per-identifier rate limiter backed by files. The identifier
 * (typically an IP) is hashed before storage for privacy (architecture §11).
 * Clock is injectable for tests.
 *
 * Note: the read-modify-write is not strictly atomic under heavy concurrency;
 * acceptable for v1 quota protection. Revisit in the P5.4 security audit.
 */
final class RateLimiter
{
    private const WINDOW_SECONDS = 60;

    /** @var callable():int */
    private $clock;

    /** @param (callable():int)|null $clock */
    public function __construct(
        private string $dir,
        private int $maxPerWindow,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        Fs::ensureDir($this->dir);
    }

    /**
     * Register one request for `$identifier` and report whether it is allowed.
     *
     * @return array{allowed: bool, remaining: int, retryAfter: int}
     */
    public function hit(string $identifier): array
    {
        $now = ($this->clock)();
        $window = intdiv($now, self::WINDOW_SECONDS);
        $path = $this->dir . '/' . hash('sha256', $identifier) . '.json';

        $data = Fs::readJson($path);
        if ($data === null || ($data['window'] ?? null) !== $window) {
            $data = ['window' => $window, 'count' => 0];
        }
        $data['count'] = (int) $data['count'] + 1;
        Fs::writeJson($path, $data);

        $allowed = $data['count'] <= $this->maxPerWindow;
        $remaining = max(0, $this->maxPerWindow - $data['count']);
        $retryAfter = $allowed ? 0 : (self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS));

        return ['allowed' => $allowed, 'remaining' => $remaining, 'retryAfter' => $retryAfter];
    }
}

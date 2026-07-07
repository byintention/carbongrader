<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/Fs.php';

/**
 * File-backed store for async grading jobs plus a per-URL single-flight lock
 * (so concurrent requests for the same uncached URL trigger only one upstream
 * run). See architecture.md §3.1 and §10. Clock/id generator are injectable.
 */
final class JobStore
{
    public const STATUSES = ['queued', 'running', 'done', 'error'];

    /** @var callable():int */
    private $clock;
    /** @var callable():string */
    private $idGenerator;

    /**
     * @param (callable():int)|null $clock
     * @param (callable():string)|null $idGenerator
     */
    public function __construct(
        private string $dir,
        ?callable $clock = null,
        ?callable $idGenerator = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->idGenerator = $idGenerator ?? static fn (): string => bin2hex(random_bytes(16));
        Fs::ensureDir($this->jobsDir());
    }

    /**
     * Create a new job. Returns the generated id.
     *
     * @param array<string,mixed> $data initial fields (e.g. url, status, result)
     */
    public function create(array $data): string
    {
        $id = ($this->idGenerator)();
        $now = ($this->clock)();
        $job = array_merge(
            ['status' => 'queued'],
            $data,
            ['id' => $id, 'createdAt' => $now, 'updatedAt' => $now],
        );
        Fs::writeJson($this->jobPath($id), $job);
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }
        return Fs::readJson($this->jobPath($id));
    }

    /**
     * Merge changes into an existing job and bump `updatedAt`.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed> the updated job
     */
    public function update(string $id, array $changes): array
    {
        $job = $this->get($id);
        if ($job === null) {
            throw new \RuntimeException("Unknown job: {$id}");
        }
        $job = array_merge($job, $changes, ['id' => $id, 'updatedAt' => ($this->clock)()]);
        Fs::writeJson($this->jobPath($id), $job);
        return $job;
    }

    /**
     * Try to acquire the single-flight lock for a canonical URL key.
     * Returns a lock handle (resource) to pass to releaseLock(), or false if the
     * lock is already held by someone else.
     *
     * @return resource|false
     */
    public function acquireLock(string $canonicalKey)
    {
        $dir = $this->dir . '/locks';
        Fs::ensureDir($dir);
        $path = $dir . '/' . hash('sha256', $canonicalKey) . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        return $handle;
    }

    /** @param resource|false $handle */
    public function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{8,64}$/', $id) === 1;
    }

    private function jobsDir(): string
    {
        return $this->dir . '/jobs';
    }

    private function jobPath(string $id): string
    {
        return $this->jobsDir() . '/' . $id . '.json';
    }
}

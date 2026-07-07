<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/UrlValidator.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/JobStore.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/UpstreamException.php';

/**
 * Orchestrates the async grading flow (architecture §3, §5): validate + rate
 * limit + cache on job creation; on poll, run the upstream analysis behind a
 * single-flight lock and cache the result. All I/O is injected so this is fully
 * unit-testable offline; `grade.php` is only a thin HTTP wrapper around it.
 */
final class GradeService
{
    private const STRATEGIES = ['mobile', 'desktop'];

    /** @var callable(array<string,mixed>):array<string,mixed> */
    private $analyzer;
    /** @var (callable(string):array<string>)|null */
    private $resolver;

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $analyzer
     *        Given a job, returns the result payload or throws UpstreamException.
     * @param (callable(string):array<string>)|null $resolver DNS resolver for URL validation.
     */
    public function __construct(
        private Cache $cache,
        private JobStore $jobs,
        private RateLimiter $rateLimiter,
        callable $analyzer,
        private int $cacheTtlSeconds = 43200,
        private string $defaultStrategy = 'mobile',
        ?callable $resolver = null,
    ) {
        $this->analyzer = $analyzer;
        $this->resolver = $resolver;
    }

    /**
     * Create a grading job (POST /api/grade).
     *
     * @param array<string,mixed> $input request body (url, strategy, refresh)
     * @return array<string,mixed> response body
     */
    public function createJob(array $input, string $clientIp): array
    {
        $limit = $this->rateLimiter->hit($clientIp);
        if (!$limit['allowed']) {
            return self::error('RATE_LIMITED', 'Too many requests. Please slow down.', $limit['retryAfter']);
        }

        try {
            $validated = UrlValidator::validate((string) ($input['url'] ?? ''), $this->resolver);
        } catch (ValidationException $e) {
            return self::error($e->errorCode, $e->getMessage());
        }

        $strategy = $this->normalizeStrategy($input['strategy'] ?? null);
        $refresh = (bool) ($input['refresh'] ?? false);
        $cacheKey = $validated['canonicalKey'] . '|' . $strategy;

        $jobData = [
            'url' => $validated['url'],
            'host' => $validated['host'],
            'canonicalKey' => $cacheKey,
            'strategy' => $strategy,
            'refresh' => $refresh,
        ];

        $cached = $refresh ? null : $this->cache->get($cacheKey);
        if (is_array($cached)) {
            $cached['cached'] = true;
            $id = $this->jobs->create($jobData + ['status' => 'done', 'result' => $cached]);
            return ['ok' => true, 'id' => $id, 'status' => 'done'];
        }

        $id = $this->jobs->create($jobData);
        return ['ok' => true, 'id' => $id, 'status' => 'queued'];
    }

    /**
     * Poll a job (GET /api/grade?id=…). Runs the analysis inline on first poll.
     *
     * @return array<string,mixed> response body
     */
    public function poll(string $id): array
    {
        if (!JobStore::isValidId($id)) {
            return self::error('NOT_FOUND', 'Unknown job.');
        }
        $job = $this->jobs->get($id);
        if ($job === null) {
            return self::error('NOT_FOUND', 'Unknown job.');
        }

        $status = (string) ($job['status'] ?? 'queued');
        if ($status === 'done') {
            return ['ok' => true, 'id' => $id, 'status' => 'done', 'result' => $job['result'] ?? null];
        }
        if ($status === 'error') {
            return ['ok' => true, 'id' => $id, 'status' => 'error', 'error' => $job['error'] ?? null];
        }

        $cacheKey = (string) $job['canonicalKey'];
        $refresh = (bool) ($job['refresh'] ?? false);

        $cached = $refresh ? null : $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $this->finishFromCache($id, $cached);
        }

        $lock = $this->jobs->acquireLock($cacheKey);
        if ($lock === false) {
            // Another request is already producing this result; keep polling.
            $this->jobs->update($id, ['status' => 'running']);
            return ['ok' => true, 'id' => $id, 'status' => 'running'];
        }

        try {
            // Re-check the cache now that we hold the lock (it may have filled).
            $cached = $refresh ? null : $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $this->finishFromCache($id, $cached);
            }

            $this->jobs->update($id, ['status' => 'running']);
            $result = ($this->analyzer)($job);

            $this->cache->set($cacheKey, $result, $this->cacheTtlSeconds);
            $result['cached'] = false;
            $this->jobs->update($id, ['status' => 'done', 'result' => $result]);

            return ['ok' => true, 'id' => $id, 'status' => 'done', 'result' => $result];
        } catch (UpstreamException $e) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            $this->jobs->update($id, ['status' => 'error', 'error' => $error]);
            return ['ok' => true, 'id' => $id, 'status' => 'error', 'error' => $error];
        } finally {
            $this->jobs->releaseLock($lock);
        }
    }

    /**
     * @param array<string,mixed> $cached
     * @return array<string,mixed>
     */
    private function finishFromCache(string $id, array $cached): array
    {
        $cached['cached'] = true;
        $this->jobs->update($id, ['status' => 'done', 'result' => $cached]);
        return ['ok' => true, 'id' => $id, 'status' => 'done', 'result' => $cached];
    }

    private function normalizeStrategy(mixed $strategy): string
    {
        $strategy = is_string($strategy) ? strtolower($strategy) : '';
        return in_array($strategy, self::STRATEGIES, true) ? $strategy : $this->defaultStrategy;
    }

    /** @return array<string,mixed> */
    private static function error(string $code, string $message, int $retryAfter = 0): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($retryAfter > 0) {
            $error['retryAfter'] = $retryAfter;
        }
        return ['ok' => false, 'error' => $error];
    }
}

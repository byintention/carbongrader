<?php

declare(strict_types=1);

/** Offline test for GradeService. Run with: `php tests/GradeServiceTest.php`. */

namespace CarbonGrader;

require_once __DIR__ . '/../src/api/lib/GradeService.php';

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  ok  - {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "FAIL  - {$name}: {$e->getMessage()}\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            ($msg !== '' ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }
}

function assertTrue(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new \RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function tmpDir(): string
{
    return sys_get_temp_dir() . '/cg_grade_' . bin2hex(random_bytes(6));
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** A stub analyzer that records how many times it ran. */
function countingAnalyzer(int &$calls): callable
{
    return static function (array $job) use (&$calls): array {
        $calls++;
        return [
            'url' => $job['url'],
            'host' => $job['host'],
            'strategy' => $job['strategy'],
            'hosting' => ['green' => true, 'hostedBy' => 'Test Host', 'verified' => true],
            'page' => ['totalBytes' => 1000000, 'performanceScore' => 0.9, 'resources' => []],
            'opportunities' => [],
        ];
    };
}

function makeService(
    string $dir,
    callable $analyzer,
    int $maxPerMinute = 100,
    ?callable $resolver = null,
    int $ttl = 3600,
): GradeService {
    return new GradeService(
        new Cache($dir . '/cache'),
        new JobStore($dir),
        new RateLimiter($dir . '/ratelimit', $maxPerMinute),
        $analyzer,
        $ttl,
        'mobile',
        $resolver,
    );
}

echo "GradeService\n";

$publicResolver = static fn (string $host): array => ['93.184.216.34'];

check('create then poll runs analysis and returns result', function () use ($publicResolver) {
    $dir = tmpDir();
    $calls = 0;
    $svc = makeService($dir, countingAnalyzer($calls), 100, $publicResolver);
    $create = $svc->createJob(['url' => 'https://example.com/'], '203.0.113.1');
    assertSame(true, $create['ok']);
    assertSame('queued', $create['status']);
    $poll = $svc->poll($create['id']);
    assertSame('done', $poll['status']);
    assertSame(false, $poll['result']['cached']);
    assertSame(1000000, $poll['result']['page']['totalBytes']);
    assertSame(1, $calls);
    rrmdir($dir);
});

check('second create for same url hits cache (no re-analysis)', function () use ($publicResolver) {
    $dir = tmpDir();
    $calls = 0;
    $svc = makeService($dir, countingAnalyzer($calls), 100, $publicResolver);
    $first = $svc->createJob(['url' => 'https://example.com/'], '203.0.113.1');
    $svc->poll($first['id']); // populates cache
    $second = $svc->createJob(['url' => 'https://example.com/'], '203.0.113.1');
    assertSame('done', $second['status'], 'cache hit on create');
    $poll = $svc->poll($second['id']);
    assertSame(true, $poll['result']['cached']);
    assertSame(1, $calls, 'analyzer ran only once');
    rrmdir($dir);
});

check('refresh bypasses cache', function () use ($publicResolver) {
    $dir = tmpDir();
    $calls = 0;
    $svc = makeService($dir, countingAnalyzer($calls), 100, $publicResolver);
    $first = $svc->createJob(['url' => 'https://example.com/'], '203.0.113.1');
    $svc->poll($first['id']);
    $refresh = $svc->createJob(['url' => 'https://example.com/', 'refresh' => true], '203.0.113.1');
    assertSame('queued', $refresh['status'], 'refresh does not use cache');
    $svc->poll($refresh['id']);
    assertSame(2, $calls, 'analyzer ran again for refresh');
    rrmdir($dir);
});

check('strategy is part of the cache key', function () use ($publicResolver) {
    $dir = tmpDir();
    $calls = 0;
    $svc = makeService($dir, countingAnalyzer($calls), 100, $publicResolver);
    $svc->poll($svc->createJob(['url' => 'https://example.com/', 'strategy' => 'mobile'], '203.0.113.1')['id']);
    $desktop = $svc->createJob(['url' => 'https://example.com/', 'strategy' => 'desktop'], '203.0.113.1');
    assertSame('queued', $desktop['status'], 'desktop not served from mobile cache');
    rrmdir($dir);
});

check('poll unknown id -> NOT_FOUND', function () {
    $dir = tmpDir();
    $calls = 0;
    $svc = makeService($dir, countingAnalyzer($calls));
    $r = $svc->poll('abcdef1234');
    assertSame(false, $r['ok']);
    assertSame('NOT_FOUND', $r['error']['code']);
    rrmdir($dir);
});

check('invalid url -> INVALID_URL', function () {
    $dir = tmpDir();
    $calls = 0;
    $svc = makeService($dir, countingAnalyzer($calls));
    $r = $svc->createJob(['url' => 'ftp://example.com'], '203.0.113.1');
    assertSame(false, $r['ok']);
    assertSame('INVALID_URL', $r['error']['code']);
    rrmdir($dir);
});

check('private host -> BLOCKED_HOST', function () {
    $dir = tmpDir();
    $calls = 0;
    $svc = makeService($dir, countingAnalyzer($calls));
    $r = $svc->createJob(['url' => 'http://127.0.0.1/'], '203.0.113.1');
    assertSame(false, $r['ok']);
    assertSame('BLOCKED_HOST', $r['error']['code']);
    rrmdir($dir);
});

check('rate limit blocks after the limit', function () use ($publicResolver) {
    $dir = tmpDir();
    $calls = 0;
    $svc = makeService($dir, countingAnalyzer($calls), 1, $publicResolver);
    $first = $svc->createJob(['url' => 'https://example.com/'], '198.51.100.7');
    assertSame(true, $first['ok']);
    $second = $svc->createJob(['url' => 'https://example.com/'], '198.51.100.7');
    assertSame(false, $second['ok']);
    assertSame('RATE_LIMITED', $second['error']['code']);
    assertTrue(($second['error']['retryAfter'] ?? 0) > 0);
    rrmdir($dir);
});

check('analyzer failure -> job error (ok:true, status:error)', function () use ($publicResolver) {
    $dir = tmpDir();
    $analyzer = static function (array $job): array {
        throw new UpstreamException('PSI_ANALYSIS_FAILED', 'boom');
    };
    $svc = makeService($dir, $analyzer, 100, $publicResolver);
    $create = $svc->createJob(['url' => 'https://example.com/'], '203.0.113.1');
    $poll = $svc->poll($create['id']);
    assertSame(true, $poll['ok']);
    assertSame('error', $poll['status']);
    assertSame('PSI_ANALYSIS_FAILED', $poll['error']['code']);
    assertSame(GradeService::publicUpstreamMessage('PSI_ANALYSIS_FAILED'), $poll['error']['message']);
    assertTrue(!str_contains((string) $poll['error']['message'], 'boom'));
    // Errored job stays errored on re-poll.
    $again = $svc->poll($create['id']);
    assertSame('error', $again['status']);
    rrmdir($dir);
});

check('poll returns running when single-flight lock is held', function () use ($publicResolver) {
    $dir = tmpDir();
    $calls = 0;
    $jobs = new JobStore($dir);
    $svc = new GradeService(
        new Cache($dir . '/cache'),
        $jobs,
        new RateLimiter($dir . '/ratelimit', 100),
        countingAnalyzer($calls),
        3600,
        'mobile',
        $publicResolver,
    );
    $create = $svc->createJob(['url' => 'https://example.com/'], '203.0.113.1');
    $job = $jobs->get($create['id']);
    $held = $jobs->acquireLock((string) $job['canonicalKey']); // simulate another worker
    $poll = $svc->poll($create['id']);
    assertSame('running', $poll['status']);
    assertSame(0, $calls, 'analyzer not run while lock held');
    $jobs->releaseLock($held);
    rrmdir($dir);
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

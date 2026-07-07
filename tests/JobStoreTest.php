<?php

declare(strict_types=1);

/** Offline test for JobStore. Run with: `php tests/JobStoreTest.php`. */

namespace CarbonGrader;

require_once __DIR__ . '/../src/api/lib/JobStore.php';

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
    return sys_get_temp_dir() . '/cg_jobs_' . bin2hex(random_bytes(6));
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

echo "JobStore\n";

$dir = tmpDir();
$now = 5000;
$clock = static function () use (&$now): int {
    return $now;
};
// Deterministic incrementing ids.
$counter = 0;
$ids = static function () use (&$counter): string {
    return str_pad((string) (++$counter), 8, '0', STR_PAD_LEFT);
};

check('create stores a queued job with timestamps', function () use ($dir, $clock, $ids) {
    $store = new JobStore($dir, $clock, $ids);
    $id = $store->create(['url' => 'https://example.com/']);
    $job = $store->get($id);
    assertTrue($job !== null);
    assertSame('queued', $job['status']);
    assertSame('https://example.com/', $job['url']);
    assertSame($id, $job['id']);
    assertSame(5000, $job['createdAt']);
});

check('update merges and bumps updatedAt', function () use ($dir, &$now, $clock, $ids) {
    $store = new JobStore($dir, $clock, $ids);
    $now = 5000;
    $id = $store->create([]);
    $now = 5030;
    $job = $store->update($id, ['status' => 'done', 'result' => ['ok' => true]]);
    assertSame('done', $job['status']);
    assertSame(['ok' => true], $job['result']);
    assertSame(5030, $job['updatedAt']);
    assertSame(5000, $job['createdAt'], 'createdAt preserved');
});

check('get on unknown id returns null', function () use ($dir, $clock, $ids) {
    $store = new JobStore($dir, $clock, $ids);
    assertSame(null, $store->get('deadbeef'));
});

check('get rejects malformed id', function () use ($dir, $clock, $ids) {
    $store = new JobStore($dir, $clock, $ids);
    assertSame(null, $store->get('../etc/passwd'));
});

check('update on unknown id throws', function () use ($dir, $clock, $ids) {
    $store = new JobStore($dir, $clock, $ids);
    try {
        $store->update('abcdef12', ['status' => 'done']);
        throw new \RuntimeException('expected throw');
    } catch (\RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'Unknown job'));
    }
});

check('single-flight lock is exclusive per key', function () use ($dir, $clock, $ids) {
    $store = new JobStore($dir, $clock, $ids);
    $a = $store->acquireLock('https://example.com/');
    assertTrue($a !== false, 'first acquire succeeds');
    $b = $store->acquireLock('https://example.com/');
    assertSame(false, $b, 'second acquire (same key) blocked while held');
    // A different key is independent.
    $c = $store->acquireLock('https://other.com/');
    assertTrue($c !== false, 'different key acquires');
    $store->releaseLock($a);
    $d = $store->acquireLock('https://example.com/');
    assertTrue($d !== false, 're-acquire after release');
    $store->releaseLock($c);
    $store->releaseLock($d);
});

rrmdir($dir);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

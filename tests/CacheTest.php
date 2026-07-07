<?php

declare(strict_types=1);

/** Offline test for Cache. Run with: `php tests/CacheTest.php`. */

namespace CarbonGrader;

require_once __DIR__ . '/../src/api/lib/Cache.php';

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

function tmpDir(): string
{
    $dir = sys_get_temp_dir() . '/cg_cache_' . bin2hex(random_bytes(6));
    return $dir;
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

echo "Cache\n";

$dir = tmpDir();
$now = 1000;
$clock = static function () use (&$now): int {
    return $now;
};

check('set/get round-trips arrays', function () use ($dir, $clock) {
    $c = new Cache($dir, $clock);
    $c->set('k1', ['green' => true, 'n' => 42], 3600);
    assertSame(['green' => true, 'n' => 42], $c->get('k1'));
    assertSame(true, $c->has('k1'));
});

check('missing key returns null', function () use ($dir, $clock) {
    $c = new Cache($dir, $clock);
    assertSame(null, $c->get('nope'));
    assertSame(false, $c->has('nope'));
});

check('entry expires after TTL', function () use ($dir, &$now, $clock) {
    $c = new Cache($dir, $clock);
    $now = 1000;
    $c->set('temp', 'v', 60);
    $now = 1059;
    assertSame('v', $c->get('temp'), 'still valid at t+59');
    $now = 1060;
    assertSame(null, $c->get('temp'), 'expired at t+60');
});

check('ttl <= 0 never expires', function () use ($dir, &$now, $clock) {
    $c = new Cache($dir, $clock);
    $now = 1000;
    $c->set('forever', 'v', 0);
    $now = 999999999;
    assertSame('v', $c->get('forever'));
});

check('delete removes the entry', function () use ($dir, $clock) {
    $c = new Cache($dir, $clock);
    $c->set('bye', 'v', 3600);
    $c->delete('bye');
    assertSame(null, $c->get('bye'));
});

rrmdir($dir);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/** Offline test for RateLimiter. Run with: `php tests/RateLimiterTest.php`. */

namespace CarbonGrader;

require_once __DIR__ . '/../src/api/lib/RateLimiter.php';

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
    return sys_get_temp_dir() . '/cg_rl_' . bin2hex(random_bytes(6));
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

echo "RateLimiter\n";

$dir = tmpDir();
$now = 90;
$clock = static function () use (&$now): int {
    return $now;
};

check('allows up to the limit then blocks', function () use ($dir, &$now, $clock) {
    $now = 90; // window = 1
    $rl = new RateLimiter($dir, 3, $clock);
    $id = '203.0.113.5';
    $r1 = $rl->hit($id);
    assertSame(true, $r1['allowed']);
    assertSame(2, $r1['remaining']);
    assertSame(true, $rl->hit($id)['allowed']);
    $r3 = $rl->hit($id);
    assertSame(true, $r3['allowed']);
    assertSame(0, $r3['remaining']);
    $r4 = $rl->hit($id);
    assertSame(false, $r4['allowed'], '4th over limit of 3');
    assertTrue($r4['retryAfter'] > 0, 'retryAfter set when blocked');
});

check('new window resets the count', function () use ($dir, &$now, $clock) {
    $rl = new RateLimiter($dir, 2, $clock);
    $id = '198.51.100.9';
    $now = 120; // window 2
    assertSame(true, $rl->hit($id)['allowed']);
    assertSame(true, $rl->hit($id)['allowed']);
    assertSame(false, $rl->hit($id)['allowed']);
    $now = 180; // window 3 -> reset
    $fresh = $rl->hit($id);
    assertSame(true, $fresh['allowed'], 'allowed again in new window');
    assertSame(1, $fresh['remaining']);
});

check('different identifiers are independent', function () use ($dir, &$now, $clock) {
    $now = 300;
    $rl = new RateLimiter($dir, 1, $clock);
    assertSame(true, $rl->hit('10.0.0.1')['allowed']);
    assertSame(false, $rl->hit('10.0.0.1')['allowed']);
    assertSame(true, $rl->hit('10.0.0.2')['allowed'], 'second IP unaffected');
});

rrmdir($dir);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

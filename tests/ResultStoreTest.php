<?php

declare(strict_types=1);

/** Offline test for ResultStore. Run with: `php tests/ResultStoreTest.php`. */

namespace CarbonGrader;

require_once __DIR__ . '/../src/api/lib/ResultStore.php';

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
    return sys_get_temp_dir() . '/cg_results_' . bin2hex(random_bytes(6));
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

echo "ResultStore\n";

$dir = tmpDir();

check('save then get round-trips the public payload', function () use ($dir) {
    $store = new ResultStore($dir);
    $store->save('abcdef0123456789', [
        'url' => 'https://example.com/path',
        'host' => 'example.com',
        'strategy' => 'desktop',
        'fetchedAt' => '2026-10-27T12:00:00+00:00',
        'cached' => false,
        'hosting' => ['green' => true, 'hostedBy' => 'Example Cloud', 'verified' => true],
        'page' => [
            'totalBytes' => 1200,
            'performanceScore' => 0.5,
            'resources' => [['type' => 'image', 'bytes' => 800, 'count' => 2]],
        ],
        'opportunities' => [['id' => 'unused-js', 'title' => 'Reduce unused JavaScript', 'savingsMs' => 400]],
        'apiKey' => 'secret',
        'clientIp' => '203.0.113.1',
    ]);
    $saved = $store->get('abcdef0123456789');
    assertTrue(is_file($dir . '/abcdef0123456789.json'), 'file sits in the results directory');
    assertSame('https://example.com/path', $saved['url']);
    assertSame('desktop', $saved['strategy']);
    assertSame(true, $saved['hosting']['green']);
    assertSame(1200, $saved['page']['totalBytes']);
    assertSame(0.5, $saved['page']['performanceScore']);
    assertTrue(!array_key_exists('apiKey', $saved));
    assertTrue(!array_key_exists('clientIp', $saved));
    assertTrue(!str_contains((string) file_get_contents($dir . '/abcdef0123456789.json'), 'secret'));
});

check('get on unknown id returns null', function () use ($dir) {
    $store = new ResultStore($dir);
    assertSame(null, $store->get('deadbeef'));
});

check('get and save reject a path-like id', function () use ($dir) {
    $store = new ResultStore($dir);
    assertSame(null, $store->get('../etc/passwd'));
    assertSame(null, $store->get('2026-10/abcdef0123456789'));
    try {
        $store->save('../etc/passwd', ['url' => 'https://example.com/']);
        throw new \RuntimeException('expected throw');
    } catch (\InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'Invalid result id'));
    }
    assertTrue(!is_file($dir . '/../etc/passwd.json'));
});

rrmdir($dir);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

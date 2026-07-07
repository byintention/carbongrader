<?php

declare(strict_types=1);

/**
 * Dependency-free, offline test for PageSpeedClient. The HTTP transport is
 * stubbed with saved fixtures — no network or API key needed.
 * Run with: `php tests/PageSpeedClientTest.php`.
 */

namespace CarbonGrader;

require_once __DIR__ . '/../src/api/lib/PageSpeedClient.php';

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

function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
}

function stub(int $status, string $body, ?string &$captured = null): callable
{
    return static function (string $url) use ($status, $body, &$captured): array {
        $captured = $url;
        return ['status' => $status, 'body' => $body];
    };
}

/** Find a resource entry by type. */
function resourceOf(array $resources, string $type): ?array
{
    foreach ($resources as $r) {
        if ($r['type'] === $type) {
            return $r;
        }
    }
    return null;
}

echo "PageSpeedClient\n";

check('extracts total bytes and performance score', function () {
    $client = new PageSpeedClient('', 35, stub(200, fixture('pagespeed_example.json')));
    $r = $client->analyze('https://example.com/', 'mobile');
    assertSame(1843200, $r['totalBytes']);
    assertSame(0.82, $r['performanceScore']);
});

check('aggregates resources by type from network-requests', function () {
    $client = new PageSpeedClient('', 35, stub(200, fixture('pagespeed_example.json')));
    $r = $client->analyze('https://example.com/');
    $img = resourceOf($r['resources'], 'image');
    $script = resourceOf($r['resources'], 'script');
    $font = resourceOf($r['resources'], 'font');
    $other = resourceOf($r['resources'], 'other');
    assertSame(['type' => 'image', 'bytes' => 980000, 'count' => 3], $img);
    assertSame(['type' => 'script', 'bytes' => 420000, 'count' => 2], $script);
    assertSame(['type' => 'font', 'bytes' => 180000, 'count' => 2], $font);
    assertSame(['type' => 'other', 'bytes' => 500, 'count' => 1], $other, 'Ping maps to other');
});

check('resources are sorted by bytes descending', function () {
    $client = new PageSpeedClient('', 35, stub(200, fixture('pagespeed_example.json')));
    $r = $client->analyze('https://example.com/');
    assertSame('image', $r['resources'][0]['type']);
});

check('extracts opportunities, filters zero-savings, sorts desc', function () {
    $client = new PageSpeedClient('', 35, stub(200, fixture('pagespeed_example.json')));
    $r = $client->analyze('https://example.com/');
    assertSame(2, count($r['opportunities']), 'render-blocking (0ms) excluded');
    assertSame('uses-optimized-images', $r['opportunities'][0]['id']);
    assertSame(1200, $r['opportunities'][0]['savingsMs']);
    assertSame('unused-javascript', $r['opportunities'][1]['id']);
});

check('builds URL with url/category/strategy/key', function () {
    $captured = '';
    $client = new PageSpeedClient('SECRET_KEY', 35, stub(200, fixture('pagespeed_example.json'), $captured));
    $client->analyze('https://example.com/?a=1', 'desktop');
    assertTrue(str_starts_with($captured, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?'), 'base');
    assertTrue(str_contains($captured, 'strategy=desktop'), 'strategy');
    assertTrue(str_contains($captured, 'category=performance'), 'category');
    assertTrue(str_contains($captured, 'key=SECRET_KEY'), 'key');
    assertTrue(str_contains($captured, 'url=' . rawurlencode('https://example.com/?a=1')), 'encoded url');
});

check('omits key when none provided', function () {
    $captured = '';
    $client = new PageSpeedClient('', 35, stub(200, fixture('pagespeed_example.json'), $captured));
    $client->analyze('https://example.com/');
    assertTrue(!str_contains($captured, 'key='), 'no key param');
});

check('runtime error -> PSI_ANALYSIS_FAILED', function () {
    $client = new PageSpeedClient('', 35, stub(200, fixture('pagespeed_runtime_error.json')));
    try {
        $client->analyze('https://unreachable.example/');
    } catch (UpstreamException $e) {
        assertSame('PSI_ANALYSIS_FAILED', $e->errorCode);
        return;
    }
    throw new \RuntimeException('expected UpstreamException');
});

check('HTTP 429 -> RATE_LIMITED', function () {
    $client = new PageSpeedClient('', 35, stub(429, '{"error":{"code":429,"message":"quota"}}'));
    try {
        $client->analyze('https://example.com/');
    } catch (UpstreamException $e) {
        assertSame('RATE_LIMITED', $e->errorCode);
        return;
    }
    throw new \RuntimeException('expected UpstreamException');
});

check('API error object -> UPSTREAM', function () {
    $client = new PageSpeedClient('', 35, stub(400, '{"error":{"code":400,"message":"bad key"}}'));
    try {
        $client->analyze('https://example.com/');
    } catch (UpstreamException $e) {
        assertSame('UPSTREAM', $e->errorCode);
        return;
    }
    throw new \RuntimeException('expected UpstreamException');
});

check('invalid JSON -> UPSTREAM', function () {
    $client = new PageSpeedClient('', 35, stub(200, '<html>nope</html>'));
    try {
        $client->analyze('https://example.com/');
    } catch (UpstreamException $e) {
        assertSame('UPSTREAM', $e->errorCode);
        return;
    }
    throw new \RuntimeException('expected UpstreamException');
});

check('invalid strategy -> UPSTREAM', function () {
    $client = new PageSpeedClient('', 35, stub(200, fixture('pagespeed_example.json')));
    try {
        $client->analyze('https://example.com/', 'tablet');
    } catch (UpstreamException $e) {
        assertSame('UPSTREAM', $e->errorCode);
        return;
    }
    throw new \RuntimeException('expected UpstreamException');
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

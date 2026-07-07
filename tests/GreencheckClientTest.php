<?php

declare(strict_types=1);

/**
 * Dependency-free, offline test for GreencheckClient. The HTTP transport is
 * stubbed so no network is used. Run with: `php tests/GreencheckClientTest.php`.
 */

namespace CarbonGrader;

require_once __DIR__ . '/../src/api/lib/GreencheckClient.php';

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

function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
}

/** Build a transport stub returning a fixed status/body. */
function stub(int $status, string $body, ?string &$captured = null): callable
{
    return static function (string $url) use ($status, $body, &$captured): array {
        $captured = $url;
        return ['status' => $status, 'body' => $body];
    };
}

echo "GreencheckClient\n";

check('green host parsed', function () {
    $client = new GreencheckClient(10, stub(200, fixture('greencheck_green.json')));
    $r = $client->check('google.com');
    assertSame(true, $r['green']);
    assertSame('Google Inc.', $r['hostedBy']);
});

check('grey host parsed (no hostedBy)', function () {
    $client = new GreencheckClient(10, stub(200, fixture('greencheck_grey.json')));
    $r = $client->check('example-grey.test');
    assertSame(false, $r['green']);
    assertSame(null, $r['hostedBy']);
});

check('builds the correct v3 endpoint with bare host', function () {
    $captured = null;
    $client = new GreencheckClient(10, stub(200, fixture('greencheck_green.json'), $captured));
    $client->check('Example.COM');
    assertSame('https://api.thegreenwebfoundation.org/api/v3/greencheck/example.com', $captured);
});

check('non-200 throws UPSTREAM', function () {
    $client = new GreencheckClient(10, stub(500, 'oops'));
    try {
        $client->check('example.com');
    } catch (UpstreamException $e) {
        assertSame('UPSTREAM', $e->errorCode);
        return;
    }
    throw new \RuntimeException('expected UpstreamException');
});

check('invalid JSON throws UPSTREAM', function () {
    $client = new GreencheckClient(10, stub(200, '<html>not json</html>'));
    try {
        $client->check('example.com');
    } catch (UpstreamException $e) {
        assertSame('UPSTREAM', $e->errorCode);
        return;
    }
    throw new \RuntimeException('expected UpstreamException');
});

check('transport timeout propagates as TIMEOUT', function () {
    $client = new GreencheckClient(10, static function (string $url): array {
        throw new UpstreamException('TIMEOUT', 'simulated timeout');
    });
    try {
        $client->check('example.com');
    } catch (UpstreamException $e) {
        assertSame('TIMEOUT', $e->errorCode);
        return;
    }
    throw new \RuntimeException('expected UpstreamException');
});

check('empty host throws UPSTREAM', function () {
    $client = new GreencheckClient(10, stub(200, '{}'));
    try {
        $client->check('   ');
    } catch (UpstreamException $e) {
        assertSame('UPSTREAM', $e->errorCode);
        return;
    }
    throw new \RuntimeException('expected UpstreamException');
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

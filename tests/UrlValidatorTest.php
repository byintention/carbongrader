<?php

declare(strict_types=1);

/**
 * Dependency-free test runner for UrlValidator. Runs offline via an injected
 * DNS resolver. Run with: `php tests/UrlValidatorTest.php`.
 * (P5.3 will migrate these to PHPUnit; kept plain to avoid a Composer dep now.)
 */

namespace CarbonGrader;

require_once __DIR__ . '/../src/api/lib/UrlValidator.php';

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
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new \RuntimeException(($msg !== '' ? "$msg: " : '') . "expected $e, got $a");
    }
}

/** Assert that validate() throws ValidationException with the given error code. */
function assertRejected(string $url, string $code, ?callable $resolver = null): void
{
    try {
        UrlValidator::validate($url, $resolver);
    } catch (ValidationException $e) {
        assertSame($code, $e->errorCode, "wrong error code for '$url'");
        return;
    }
    throw new \RuntimeException("expected '$url' to be rejected with $code, but it passed");
}

// Resolvers used so hostname cases never touch the network.
$public = static fn (string $host): array => ['93.184.216.34'];
$private = static fn (string $host): array => ['10.0.0.5'];

echo "UrlValidator\n";

check('bare host gets https + trailing slash', function () use ($public) {
    $r = UrlValidator::validate('example.com', $public);
    assertSame('https://example.com/', $r['url']);
    assertSame('example.com', $r['host']);
    assertSame('https://example.com/', $r['canonicalKey']);
});

check('canonical key drops www and trailing slash but url keeps them', function () use ($public) {
    $r = UrlValidator::validate('https://www.example.com/foo/', $public);
    assertSame('https://www.example.com/foo/', $r['url']);
    assertSame('https://example.com/foo', $r['canonicalKey']);
});

check('http scheme is allowed', function () use ($public) {
    $r = UrlValidator::validate('http://example.com', $public);
    assertSame('http://example.com/', $r['url']);
    assertSame('http://example.com/', $r['canonicalKey']);
});

check('query string is kept in the canonical key', function () use ($public) {
    $r = UrlValidator::validate('https://example.com/a?b=1&c=2', $public);
    assertSame('https://example.com/a?b=1&c=2', $r['canonicalKey']);
});

check('uppercase host is lowercased', function () use ($public) {
    $r = UrlValidator::validate('HTTPS://EXAMPLE.COM/Path', $public);
    assertSame('https://example.com/Path', $r['url']);
});

check('empty input rejected', fn () => assertRejected('', 'INVALID_URL'));
check('whitespace rejected', fn () => assertRejected('   ', 'INVALID_URL'));
check('ftp scheme rejected', fn () => assertRejected('ftp://example.com', 'INVALID_URL'));
check('embedded credentials rejected', fn () => assertRejected('https://user:pass@example.com', 'INVALID_URL'));
check('non-default port rejected', fn () => assertRejected('https://example.com:8080', 'INVALID_URL'));
check('invalid host chars rejected', fn () => assertRejected('https://exa_mple.com', 'INVALID_URL'));

check('localhost blocked', fn () => assertRejected('http://localhost', 'BLOCKED_HOST'));
check('single-label host blocked', fn () => assertRejected('https://intranet', 'BLOCKED_HOST'));
check('loopback IPv4 blocked', fn () => assertRejected('http://127.0.0.1', 'BLOCKED_HOST'));
check('private IPv4 blocked', fn () => assertRejected('http://10.0.0.1', 'BLOCKED_HOST'));
check('link-local IPv4 blocked', fn () => assertRejected('http://169.254.1.1', 'BLOCKED_HOST'));
check('CGNAT IPv4 blocked', fn () => assertRejected('http://100.64.0.1', 'BLOCKED_HOST'));
check('loopback IPv6 blocked', fn () => assertRejected('http://[::1]', 'BLOCKED_HOST'));
check('IPv4-mapped IPv6 loopback blocked', fn () => assertRejected('http://[::ffff:127.0.0.1]', 'BLOCKED_HOST'));

check('public IP literal allowed', function () {
    $r = UrlValidator::validate('http://93.184.216.34/');
    assertSame('93.184.216.34', $r['host']);
});

check('host resolving to private IP is blocked', function () use ($private) {
    assertRejected('https://sneaky.example.com', 'BLOCKED_HOST', $private);
});

check('host resolving to public IP is allowed', function () use ($public) {
    $r = UrlValidator::validate('https://ok.example.com', $public);
    assertSame('ok.example.com', $r['host']);
});

check('unresolvable host rejected', function () {
    assertRejected('https://nope.example.com', 'INVALID_URL', static fn (string $h): array => []);
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/ValidationException.php';

/**
 * Validates, normalizes, and canonicalizes user-supplied URLs, and guards
 * against SSRF by refusing hosts that resolve to non-public addresses.
 *
 * See docs/architecture.md §10 (canonicalization) and §11 (security).
 * Decision (2026-06-10): query string is KEPT in the canonical cache key.
 */
final class UrlValidator
{
    public const ALLOWED_SCHEMES = ['http', 'https'];
    public const ALLOWED_PORTS = [80, 443];
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];
    private const MAX_LENGTH = 2048;

    /**
     * Validate + normalize a URL.
     *
     * @param callable(string):array<string>|null $resolver Host->IPs resolver
     *        (injectable so SSRF checks are unit-testable offline).
     * @return array{url: string, host: string, canonicalKey: string}
     *         `url` = the URL to fetch; `canonicalKey` = the cache key.
     * @throws ValidationException
     */
    public static function validate(string $input, ?callable $resolver = null): array
    {
        $raw = trim($input);
        if ($raw === '') {
            throw new ValidationException('INVALID_URL', 'No URL provided.');
        }
        if (strlen($raw) > self::MAX_LENGTH) {
            throw new ValidationException('INVALID_URL', 'URL is too long.');
        }

        // Assume https:// when the user omits the scheme (so parse_url sees a host).
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $raw)) {
            $raw = 'https://' . $raw;
        }

        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ValidationException('INVALID_URL', 'Could not parse the URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new ValidationException('INVALID_URL', 'Only http and https URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationException('INVALID_URL', 'URLs with embedded credentials are not allowed.');
        }

        $host = self::normalizeHost($parts['host']);
        if ($host === '') {
            throw new ValidationException('INVALID_URL', 'Missing host.');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && !in_array($port, self::ALLOWED_PORTS, true)) {
            throw new ValidationException('INVALID_URL', 'Only the default ports (80/443) are allowed.');
        }

        // IP literal → check directly; hostname → validate shape, then resolve.
        $ipLiteral = trim($host, '[]');
        if (filter_var($ipLiteral, FILTER_VALIDATE_IP) !== false) {
            self::assertPublicIp($ipLiteral);
        } else {
            self::assertValidHostname($host);
            self::assertResolvesToPublic($host, $resolver);
        }

        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? null;

        $needsPort = $port !== null && $port !== self::DEFAULT_PORTS[$scheme];
        $fetchUrl = $scheme . '://' . $host
            . ($needsPort ? ':' . $port : '')
            . ($path === '' ? '/' : $path)
            . ($query !== null && $query !== '' ? '?' . $query : '');

        return [
            'url' => $fetchUrl,
            'host' => $host,
            'canonicalKey' => self::canonicalKey($scheme, $host, $path, $query),
        ];
    }

    /**
     * Build the canonical cache key: lowercase host with leading `www.` dropped,
     * fragment removed, trailing slash normalized. Query is kept.
     */
    public static function canonicalKey(string $scheme, string $host, string $path, ?string $query): string
    {
        $host = preg_replace('/^www\./', '', strtolower($host)) ?? $host;
        $path = $path === '' ? '/' : $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $key = $scheme . '://' . $host . $path;
        if ($query !== null && $query !== '') {
            $key .= '?' . $query;
        }

        return $key;
    }

    /** Lowercase, strip a trailing dot, and punycode IDNs when `intl` is available. */
    private static function normalizeHost(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if ($host === '' || str_starts_with($host, '[')) {
            return $host; // IPv6 literal keeps its brackets.
        }
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }
        return $host;
    }

    private static function assertValidHostname(string $host): void
    {
        // Refuse non-routable single-label names (localhost, intranet, ...).
        if ($host === 'localhost' || !str_contains($host, '.')) {
            throw new ValidationException('BLOCKED_HOST', 'Refusing to analyze a non-public host.');
        }
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (!preg_match('/^(?=.{1,253}$)' . $label . '(?:\.' . $label . ')+$/', $host)) {
            throw new ValidationException('INVALID_URL', 'Invalid host name.');
        }
    }

    /** @param callable(string):array<string>|null $resolver */
    private static function assertResolvesToPublic(string $host, ?callable $resolver): void
    {
        $resolver ??= self::defaultResolver(...);
        $ips = $resolver($host);
        if ($ips === []) {
            throw new ValidationException('INVALID_URL', 'Host could not be resolved.');
        }
        foreach ($ips as $ip) {
            self::assertPublicIp($ip);
        }
    }

    private static function assertPublicIp(string $ip): void
    {
        if (!self::isPublicIp($ip)) {
            throw new ValidationException('BLOCKED_HOST', 'URL resolves to a non-public address.');
        }
    }

    /** True only for globally routable, non-private, non-reserved addresses. */
    public static function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) must be judged by its embedded v4.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $mapped = self::mappedIpv4($ip);
            if ($mapped !== null) {
                return self::isPublicIp($mapped);
            }
        }

        $ok = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        if ($ok === false) {
            return false;
        }

        // Carrier-grade NAT (100.64.0.0/10) is not covered by the reserved flag.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        }

        return true;
    }

    private static function mappedIpv4(string $ipv6): ?string
    {
        $packed = @inet_pton($ipv6);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }
        if (substr($packed, 0, 10) === str_repeat("\0", 10) && substr($packed, 10, 2) === "\xff\xff") {
            $v4 = inet_ntop(substr($packed, 12, 4));
            return $v4 === false ? null : $v4;
        }
        return null;
    }

    /**
     * Default DNS resolver (A + AAAA). Returns an empty array on failure.
     * @return array<string>
     */
    private static function defaultResolver(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }
}

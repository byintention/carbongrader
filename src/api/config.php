<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/GreencheckClient.php';
require_once __DIR__ . '/lib/PageSpeedClient.php';
require_once __DIR__ . '/lib/GradeService.php';
require_once __DIR__ . '/lib/UpstreamException.php';

/**
 * Minimal `.env` parser (no dependency). Real environment variables take
 * precedence over the file. Values may be quoted; lines starting with # are
 * comments.
 *
 * @return array<string,string>
 */
function cg_load_env(string $path): array
{
    $vars = [];
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $len = strlen($value);
            if ($len >= 2
                && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            $vars[$key] = $value;
        }
    }
    return $vars;
}

/**
 * Resolved application config (cached per request).
 *
 * @return array<string,mixed>
 */
function cg_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $root = dirname(__DIR__, 2);
    $env = cg_load_env($root . '/.env');

    $get = static function (string $key, string $default) use ($env): string {
        $fromEnv = getenv($key);
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }
        return $env[$key] ?? $default;
    };

    $varDir = $get('VAR_DIR', 'var');
    if (!str_starts_with($varDir, '/')) {
        $varDir = $root . '/' . $varDir;
    }

    $config = [
        'root' => $root,
        'varDir' => $varDir,
        'pagespeedApiKey' => $get('PAGESPEED_API_KEY', ''),
        'strategyDefault' => $get('PSI_STRATEGY_DEFAULT', 'mobile'),
        'cacheTtlPsi' => (int) $get('CACHE_TTL_PSI', '43200'),
        'cacheTtlGreencheck' => (int) $get('CACHE_TTL_GREENCHECK', '172800'),
        'httpTimeout' => (int) $get('HTTP_TIMEOUT_SECONDS', '35'),
        'rateLimitPerMinute' => (int) $get('RATE_LIMIT_PER_MINUTE', '10'),
    ];
    return $config;
}

/**
 * Build a replay transport from a prefetched response so a client can parse a
 * body we already downloaded (in parallel), preserving typed errors.
 *
 * @param array{status:int,body:string,errno:int,error:string} $response
 */
function cg_replay(array $response): callable
{
    return static function (string $url) use ($response): array {
        if (($response['errno'] ?? 0) !== 0) {
            $code = $response['errno'] === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'UPSTREAM';
            throw new UpstreamException($code, $response['error'] !== '' ? $response['error'] : 'HTTP request failed.');
        }
        return ['status' => $response['status'], 'body' => $response['body']];
    };
}

/**
 * The production analyzer: fetch Greencheck + PageSpeed in parallel, parse each,
 * and assemble the result payload (architecture §5.3). Greencheck failures are
 * non-fatal (host reported grey + unverified); a PageSpeed failure fails the job.
 *
 * @param array<string,mixed> $config
 * @return callable(array<string,mixed>):array<string,mixed>
 */
function cg_make_analyzer(array $config): callable
{
    return static function (array $job) use ($config): array {
        $host = (string) $job['host'];
        $url = (string) $job['url'];
        $strategy = (string) $job['strategy'];
        $timeout = (int) $config['httpTimeout'];

        $psi = new PageSpeedClient((string) $config['pagespeedApiKey'], $timeout);
        $urls = [
            'green' => GreencheckClient::endpointFor($host),
            'psi' => $psi->buildUrl($url, $strategy),
        ];
        $responses = Http::getMany($urls, $timeout);

        // PageSpeed is the critical path — let its errors propagate.
        $page = (new PageSpeedClient((string) $config['pagespeedApiKey'], $timeout, cg_replay($responses['psi'])))
            ->analyze($url, $strategy);

        // Green hosting is best-effort.
        try {
            $checked = (new GreencheckClient($timeout, cg_replay($responses['green'])))->check($host);
            $hosting = ['green' => $checked['green'], 'hostedBy' => $checked['hostedBy'], 'verified' => true];
        } catch (UpstreamException) {
            $hosting = ['green' => false, 'hostedBy' => null, 'verified' => false];
        }

        return [
            'url' => $url,
            'host' => $host,
            'strategy' => $strategy,
            'fetchedAt' => gmdate('c'),
            'hosting' => $hosting,
            'page' => [
                'totalBytes' => $page['totalBytes'],
                'performanceScore' => $page['performanceScore'],
                'resources' => $page['resources'],
            ],
            'opportunities' => $page['opportunities'],
        ];
    };
}

/**
 * Compose a ready-to-use GradeService with production dependencies.
 *
 * @param array<string,mixed> $config
 */
function cg_make_grade_service(array $config): GradeService
{
    $varDir = (string) $config['varDir'];
    return new GradeService(
        new Cache($varDir . '/cache'),
        new JobStore($varDir),
        new RateLimiter($varDir . '/ratelimit', (int) $config['rateLimitPerMinute']),
        cg_make_analyzer($config),
        (int) $config['cacheTtlPsi'],
        (string) $config['strategyDefault'],
    );
}

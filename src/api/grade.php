<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/config.php';

/**
 * Public endpoint (architecture §5).
 *   POST /api/grade.php        { url, strategy?, refresh? }  -> create job
 *   GET  /api/grade.php?id=…                                 -> poll / run job
 *
 * Thin wrapper: all logic lives in GradeService (unit-tested). This file maps
 * HTTP <-> service and never leaks stack traces.
 */

/** Map an error code to an HTTP status. */
function cg_http_status(string $code): int
{
    return match ($code) {
        'INVALID_URL', 'BLOCKED_HOST' => 400,
        'NOT_FOUND' => 404,
        'RATE_LIMITED' => 429,
        'TIMEOUT' => 504,
        'UPSTREAM', 'PSI_ANALYSIS_FAILED' => 502,
        default => 500,
    };
}

/** @param array<string,mixed> $body */
function cg_emit(array $body): never
{
    $status = 200;
    if (($body['ok'] ?? true) === false) {
        $status = cg_http_status((string) ($body['error']['code'] ?? ''));
        if (isset($body['error']['retryAfter']) && is_int($body['error']['retryAfter'])) {
            header('Retry-After: ' . $body['error']['retryAfter']);
        }
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$service = cg_make_grade_service(cg_config());
// NOTE: behind a proxy/CDN, derive the client IP from a trusted forwarded
// header instead of REMOTE_ADDR (see docs/planning.md open questions).
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = [];
    }
    cg_emit($service->createJob($input, $clientIp));
}

if ($method === 'GET' && isset($_GET['id'])) {
    cg_emit($service->poll((string) $_GET['id']));
}

cg_emit(['ok' => false, 'error' => ['code' => 'INVALID_URL', 'message' => 'Use POST to create a job or GET ?id=… to poll.']]);

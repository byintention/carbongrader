<?php

declare(strict_types=1);

/**
 * Router for `php -S -t src`. The built-in server ignores .htaccess, so this
 * denies non-public PHP and sends the security headers. PHP scripts are handed
 * back to the server (return false). Static files are sent here so the headers
 * stay attached.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$denied = $path === '/router.php'
    || $path === '/api/config.php'
    || str_starts_with($path, '/api/lib/');

if ($denied) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    cg_security_headers();
    header('Cache-Control: no-store');
    echo 'Not found';
    return true;
}

$docroot = realpath(__DIR__);
$relative = $path === '/' ? '/index.html' : $path;
$full = realpath(__DIR__ . $relative);

if (
    $docroot === false
    || $full === false
    || !is_file($full)
    || !str_starts_with($full, $docroot . DIRECTORY_SEPARATOR)
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    cg_security_headers();
    echo 'Not found';
    return true;
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
if ($ext === 'php') {
    cg_security_headers();
    return false;
}

$types = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'svg' => 'image/svg+xml',
    'json' => 'application/json; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'txt' => 'text/plain; charset=utf-8',
];

cg_security_headers();
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
readfile($full);
return true;

function cg_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' https://cdn.jsdelivr.net; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "font-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'"
    );
}

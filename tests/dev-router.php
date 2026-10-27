<?php

declare(strict_types=1);

/**
 * Allowlisted server for the browser spec page. Docroot is the repo, but this
 * script serves only the runner, the app JS, and the two public config files.
 * Run: php -S localhost:8099 -t . tests/dev-router.php
 */

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/' || $path === '/browser.html') {
    serve($root . '/tests/browser.html', 'text/html; charset=utf-8');
}

if (str_starts_with($path, '/src/js/') && str_ends_with($path, '.js')) {
    $file = inside($root . '/src/js', $root . $path);
    if ($file !== null) {
        serve($file, 'text/javascript; charset=utf-8');
    }
}

if ($path === '/config/scoring.json' || $path === '/config/recommendations.json') {
    $file = inside($root . '/config', $root . $path);
    if ($file !== null) {
        serve($file, 'application/json; charset=utf-8');
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'Not found';
return true;

function serve(string $file, string $type): never
{
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

function inside(string $dir, string $candidate): ?string
{
    $root = realpath($dir);
    $file = realpath($candidate);
    if ($root === false || $file === false || !is_file($file)) {
        return null;
    }
    if ($file !== $root && !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $file;
}

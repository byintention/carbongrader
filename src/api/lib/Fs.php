<?php

declare(strict_types=1);

namespace CarbonGrader;

/**
 * Small filesystem helpers shared by the file-backed stores. All writes are
 * atomic (write-temp-then-rename) so a crash mid-write can't leave a half file.
 */
final class Fs
{
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
    }

    public static function atomicWrite(string $path, string $contents): void
    {
        self::ensureDir(dirname($path));
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException("Failed to write: {$path}");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to move temp file into place: {$path}");
        }
    }

    public static function writeJson(string $path, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to JSON-encode value for ' . $path);
        }
        self::atomicWrite($path, $json);
    }

    /** @return array<mixed>|null */
    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}

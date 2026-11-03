<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/Fs.php';
require_once __DIR__ . '/JobStore.php';

/**
 * Durable copy of a finished grade, one JSON file per id.
 * The id is the job id. Lookup is a single filename under $dir.
 * Files are not pruned here; a later script can delete by mtime.
 */
final class ResultStore
{
    public function __construct(private string $dir)
    {
        Fs::ensureDir($this->dir);
    }

    /**
     * Write the public payload for this id. Replaces any previous file.
     *
     * @param array<string,mixed> $result
     */
    public function save(string $id, array $result): void
    {
        if (!JobStore::isValidId($id)) {
            throw new \InvalidArgumentException('Invalid result id.');
        }
        Fs::writeJson($this->path($id), self::publicPayload($result));
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        if (!JobStore::isValidId($id)) {
            return null;
        }
        return Fs::readJson($this->path($id));
    }

    /**
     * Fields the dashboard is allowed to see. Drops anything else (IPs, keys).
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function publicPayload(array $result): array
    {
        $hosting = is_array($result['hosting'] ?? null) ? $result['hosting'] : [];
        $page = is_array($result['page'] ?? null) ? $result['page'] : [];
        $resources = [];
        foreach (array_slice(is_array($page['resources'] ?? null) ? $page['resources'] : [], 0, 20) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $resources[] = [
                'type' => is_string($item['type'] ?? null) && $item['type'] !== '' ? $item['type'] : 'other',
                'bytes' => self::nonNegative($item['bytes'] ?? 0),
                'count' => self::nonNegative($item['count'] ?? 0),
            ];
        }
        $opportunities = [];
        foreach (array_slice(is_array($result['opportunities'] ?? null) ? $result['opportunities'] : [], 0, 8) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $opportunities[] = [
                'id' => is_string($item['id'] ?? null) ? $item['id'] : '',
                'title' => is_string($item['title'] ?? null) ? $item['title'] : '',
                'savingsMs' => self::nonNegative($item['savingsMs'] ?? 0),
            ];
        }
        $hostedBy = $hosting['hostedBy'] ?? null;
        $strategy = ($result['strategy'] ?? '') === 'desktop' ? 'desktop' : 'mobile';

        return [
            'url' => is_string($result['url'] ?? null) ? $result['url'] : '',
            'host' => is_string($result['host'] ?? null) ? $result['host'] : '',
            'strategy' => $strategy,
            'fetchedAt' => is_string($result['fetchedAt'] ?? null) ? $result['fetchedAt'] : '',
            'cached' => (bool) ($result['cached'] ?? false),
            'hosting' => [
                'green' => (bool) ($hosting['green'] ?? false),
                'hostedBy' => is_string($hostedBy) ? $hostedBy : null,
                'verified' => ($hosting['verified'] ?? true) !== false,
            ],
            'page' => [
                'totalBytes' => self::nonNegative($page['totalBytes'] ?? 0),
                'performanceScore' => self::score($page['performanceScore'] ?? null),
                'resources' => $resources,
            ],
            'opportunities' => $opportunities,
        ];
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    private static function nonNegative(mixed $value): int
    {
        if (!is_int($value) && !is_float($value)) {
            return 0;
        }
        if (!is_finite($value) || $value < 0) {
            return 0;
        }
        return (int) $value;
    }

    private static function score(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        if (!is_finite($value) || $value < 0) {
            return null;
        }
        return (float) $value;
    }
}

<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/UpstreamException.php';

/**
 * Client for the Green Web Foundation Greencheck API v3.
 * GET https://api.thegreenwebfoundation.org/api/v3/greencheck/{hostname}
 *
 * The transport is injectable so the client is unit-testable offline against a
 * fixture. See docs/architecture.md §4.2.
 */
final class GreencheckClient
{
    private const BASE_URL = 'https://api.thegreenwebfoundation.org/api/v3/greencheck/';

    /** Build the v3 endpoint for a bare host (used when prefetching in parallel). */
    public static function endpointFor(string $host): string
    {
        return self::BASE_URL . rawurlencode(strtolower(trim($host)));
    }

    /** @var callable(string):array{status:int,body:string} */
    private $transport;

    /** @param (callable(string):array{status:int,body:string})|null $transport */
    public function __construct(
        private int $timeoutSeconds = 10,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? fn (string $url): array => Http::get($url, $this->timeoutSeconds);
    }

    /**
     * Check whether a host is served from a green (renewable-powered) host.
     *
     * @return array{green: bool, hostedBy: ?string}
     * @throws UpstreamException on non-200, invalid JSON, timeout, or transport error
     */
    public function check(string $host): array
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            throw new UpstreamException('UPSTREAM', 'Empty host passed to greencheck.');
        }

        $url = self::BASE_URL . rawurlencode($host);
        $response = ($this->transport)($url);

        if ($response['status'] !== 200) {
            throw new UpstreamException('UPSTREAM', "Greencheck returned HTTP {$response['status']}.");
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new UpstreamException('UPSTREAM', 'Greencheck returned invalid JSON.');
        }

        $hostedBy = isset($data['hosted_by']) && is_string($data['hosted_by']) && $data['hosted_by'] !== ''
            ? $data['hosted_by']
            : null;

        return [
            'green' => ($data['green'] ?? false) === true,
            'hostedBy' => $hostedBy,
        ];
    }
}

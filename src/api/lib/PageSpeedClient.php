<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/UpstreamException.php';

/**
 * Client for the Google PageSpeed Insights API v5 (Lighthouse).
 * GET https://www.googleapis.com/pagespeedonline/v5/runPagespeed
 *
 * Extracts total transfer bytes, the performance score, a per-type resource
 * breakdown (reconstructed from the `network-requests` audit, since
 * `resource-summary` was removed in Lighthouse 11), and improvement
 * opportunities. Transport is injectable for offline tests. See architecture §4.1.
 */
final class PageSpeedClient
{
    private const BASE_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    private const STRATEGIES = ['mobile', 'desktop'];

    /** @var callable(string):array{status:int,body:string} */
    private $transport;

    /** @param (callable(string):array{status:int,body:string})|null $transport */
    public function __construct(
        private string $apiKey = '',
        private int $timeoutSeconds = 35,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? fn (string $url): array => Http::get($url, $this->timeoutSeconds);
    }

    /**
     * Analyze a URL and return the normalized page metrics.
     *
     * @return array{
     *     totalBytes: int,
     *     performanceScore: ?float,
     *     resources: list<array{type: string, bytes: int, count: int}>,
     *     opportunities: list<array{id: string, title: string, savingsMs: int}>
     * }
     * @throws UpstreamException on transport/API failure, invalid JSON, or a failed analysis
     */
    public function analyze(string $url, string $strategy = 'mobile'): array
    {
        if (!in_array($strategy, self::STRATEGIES, true)) {
            throw new UpstreamException('UPSTREAM', "Invalid strategy: {$strategy}.");
        }

        $response = ($this->transport)($this->buildUrl($url, $strategy));

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new UpstreamException('UPSTREAM', 'PageSpeed returned invalid JSON.');
        }

        if ($response['status'] !== 200 || isset($data['error'])) {
            $message = is_array($data['error'] ?? null) ? (string) ($data['error']['message'] ?? 'unknown') : 'unknown';
            $code = $response['status'] === 429 ? 'RATE_LIMITED' : 'UPSTREAM';
            throw new UpstreamException($code, "PageSpeed API error (HTTP {$response['status']}): {$message}");
        }

        $lhr = $data['lighthouseResult'] ?? null;
        if (!is_array($lhr)) {
            throw new UpstreamException('PSI_ANALYSIS_FAILED', 'PageSpeed response is missing the Lighthouse result.');
        }

        if (isset($lhr['runtimeError']) && is_array($lhr['runtimeError'])) {
            $rtCode = (string) ($lhr['runtimeError']['code'] ?? 'UNKNOWN');
            $rtMsg = (string) ($lhr['runtimeError']['message'] ?? 'Lighthouse could not analyze the page.');
            throw new UpstreamException('PSI_ANALYSIS_FAILED', "Lighthouse error {$rtCode}: {$rtMsg}");
        }

        $audits = is_array($lhr['audits'] ?? null) ? $lhr['audits'] : [];
        $resources = $this->extractResources($audits);

        return [
            'totalBytes' => $this->extractTotalBytes($audits, $resources),
            'performanceScore' => $this->extractPerformanceScore($lhr),
            'resources' => $resources,
            'opportunities' => $this->extractOpportunities($audits),
        ];
    }

    public function buildUrl(string $url, string $strategy): string
    {
        $params = [
            'url' => $url,
            'category' => 'performance',
            'strategy' => $strategy,
        ];
        if ($this->apiKey !== '') {
            $params['key'] = $this->apiKey;
        }
        return self::BASE_URL . '?' . http_build_query($params);
    }

    private function extractPerformanceScore(array $lhr): ?float
    {
        $score = $lhr['categories']['performance']['score'] ?? null;
        return is_numeric($score) ? (float) $score : null;
    }

    /**
     * @param array<string,mixed> $audits
     * @param list<array{type:string,bytes:int,count:int}> $resources
     */
    private function extractTotalBytes(array $audits, array $resources): int
    {
        $value = $audits['total-byte-weight']['numericValue'] ?? null;
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }
        // Fall back to summing the reconstructed resource breakdown.
        return array_sum(array_column($resources, 'bytes'));
    }

    /**
     * Rebuild per-type totals from the `network-requests` audit.
     *
     * @param array<string,mixed> $audits
     * @return list<array{type: string, bytes: int, count: int}>
     */
    private function extractResources(array $audits): array
    {
        $items = $audits['network-requests']['details']['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $totals = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $this->mapResourceType((string) ($item['resourceType'] ?? 'Other'));
            $bytes = (int) round((float) ($item['transferSize'] ?? 0));
            $totals[$type] ??= ['type' => $type, 'bytes' => 0, 'count' => 0];
            $totals[$type]['bytes'] += $bytes;
            $totals[$type]['count']++;
        }

        $resources = array_values($totals);
        usort($resources, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
        return $resources;
    }

    /**
     * @param array<string,mixed> $audits
     * @return list<array{id: string, title: string, savingsMs: int}>
     */
    private function extractOpportunities(array $audits): array
    {
        $opportunities = [];
        foreach ($audits as $id => $audit) {
            if (!is_array($audit)) {
                continue;
            }
            $details = $audit['details'] ?? null;
            if (!is_array($details) || ($details['type'] ?? '') !== 'opportunity') {
                continue;
            }
            $savings = (int) round((float) ($details['overallSavingsMs'] ?? $audit['numericValue'] ?? 0));
            if ($savings <= 0) {
                continue;
            }
            $opportunities[] = [
                'id' => (string) $id,
                'title' => (string) ($audit['title'] ?? $id),
                'savingsMs' => $savings,
            ];
        }

        usort($opportunities, static fn (array $a, array $b): int => $b['savingsMs'] <=> $a['savingsMs']);
        return $opportunities;
    }

    private function mapResourceType(string $resourceType): string
    {
        return match ($resourceType) {
            'Document' => 'document',
            'Script' => 'script',
            'Stylesheet' => 'stylesheet',
            'Image' => 'image',
            'Font' => 'font',
            'Media' => 'media',
            default => 'other',
        };
    }
}

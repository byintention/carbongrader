<?php

declare(strict_types=1);

namespace CarbonGrader;

require_once __DIR__ . '/UpstreamException.php';

/**
 * Minimal cURL GET helper shared by the upstream API clients. Only ever used to
 * call trusted APIs (Green Web Foundation, Google) — never the user's URL — so
 * following redirects here is not an SSRF vector.
 */
final class Http
{
    /**
     * @param array<string> $headers
     * @return array{status: int, body: string}
     * @throws UpstreamException on connection failure or timeout
     */
    public static function get(string $url, int $timeoutSeconds = 10, array $headers = []): array
    {
        if (!function_exists('curl_init')) {
            throw new UpstreamException('UPSTREAM', 'PHP cURL extension is not available.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 10),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_USERAGENT => 'CarbonGrader/0.1',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            $code = $errno === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'UPSTREAM';
            throw new UpstreamException($code, "HTTP request failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Fetch several URLs concurrently (used to run Greencheck + PageSpeed in
     * parallel). Never throws for a single failed URL — the failure is reported
     * per key via `errno`/`error` so callers can decide how to handle it.
     *
     * @param array<string,string> $urls key => URL
     * @param array<string> $headers
     * @return array<string,array{status:int,body:string,errno:int,error:string}>
     */
    public static function getMany(array $urls, int $timeoutSeconds = 35, array $headers = []): array
    {
        if (!function_exists('curl_multi_init')) {
            throw new UpstreamException('UPSTREAM', 'PHP cURL extension is not available.');
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 10),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
                CURLOPT_USERAGENT => 'CarbonGrader/0.1',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $results[$key] = [
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body' => (string) curl_multi_getcontent($ch),
                'errno' => curl_errno($ch),
                'error' => curl_error($ch),
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        return $results;
    }
}

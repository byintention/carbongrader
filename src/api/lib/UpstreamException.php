<?php

declare(strict_types=1);

namespace CarbonGrader;

/**
 * Thrown when an upstream API (Greencheck, PageSpeed) fails. `$errorCode` maps to
 * the public API error taxonomy (see docs/architecture.md §5.4).
 */
final class UpstreamException extends \RuntimeException
{
    /** @param 'UPSTREAM'|'TIMEOUT'|'RATE_LIMITED'|'PSI_ANALYSIS_FAILED' $errorCode */
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

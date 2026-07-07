<?php

declare(strict_types=1);

namespace CarbonGrader;

/**
 * Thrown when a user-supplied URL is rejected. `$errorCode` maps directly to the
 * public API error taxonomy (see docs/architecture.md §5.4).
 */
final class ValidationException extends \RuntimeException
{
    /** @param 'INVALID_URL'|'BLOCKED_HOST' $errorCode */
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

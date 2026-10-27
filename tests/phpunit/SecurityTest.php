<?php

declare(strict_types=1);

namespace CarbonGrader\Tests;

use CarbonGrader\GradeService;
use CarbonGrader\UrlValidator;
use CarbonGrader\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Focused PHPUnit checks. The full offline suite remains the scripts under tests/.
 */
final class SecurityTest extends TestCase
{
    public function testLoopbackHostIsBlocked(): void
    {
        $this->expectException(ValidationException::class);
        UrlValidator::validate('http://127.0.0.1/');
    }

    public function testHostResolvingToPrivateAddressIsBlocked(): void
    {
        $this->expectException(ValidationException::class);
        UrlValidator::validate('https://intranet.example/', static fn (): array => ['10.1.2.3']);
    }

    public function testPublicUpstreamMessageHidesProviderDetail(): void
    {
        $message = GradeService::publicUpstreamMessage('RATE_LIMITED');
        $this->assertStringNotContainsString('project_number', $message);
        $this->assertStringNotContainsString('HTTP', $message);
        $this->assertNotSame('', $message);
    }
}

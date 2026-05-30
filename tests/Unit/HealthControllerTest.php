<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Controllers\Api\HealthController;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testBuildStatusReturnsStatusAndChecksKeys(): void
    {
        $result = HealthController::buildStatus();

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('checks', $result);
    }

    public function testPhpVersionCheckPassesOnCurrentRuntime(): void
    {
        $result = HealthController::buildStatus();

        $this->assertSame('ok', $result['checks']['php_version']);
    }

    public function testStatusDegradedWhenAnyCheckFails(): void
    {
        $result = HealthController::aggregateStatus([
            'php_version' => 'ok',
            'database' => 'error',
        ]);

        $this->assertSame('degraded', $result);
    }

    public function testStatusOkWhenAllChecksPass(): void
    {
        $result = HealthController::aggregateStatus([
            'php_version' => 'ok',
            'database' => 'ok',
        ]);

        $this->assertSame('ok', $result);
    }
}

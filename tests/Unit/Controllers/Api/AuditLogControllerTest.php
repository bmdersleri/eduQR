<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Controllers\Api;

use EduQR\Contracts\AuditLogRepositoryInterface;
use EduQR\Controllers\Api\AuditLogController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AuditLogControllerTest extends TestCase
{
    /**
     * @requirement FR-91
     */
    public function testBuildPayloadReturnsPaginatedAuditLogs(): void
    {
        $repo = $this->createMock(AuditLogRepositoryInterface::class);
        $repo->expects($this->once())
            ->method('count')
            ->with('admin')
            ->willReturn(3);
        $repo->expects($this->once())
            ->method('list')
            ->with(25, 25, 'admin')
            ->willReturn([
                [
                    'id' => 2,
                    'actor_type' => 'admin',
                    'actor_id' => 7,
                    'action' => 'user.created',
                    'entity_type' => 'user',
                    'entity_id' => 7,
                    'metadata_json' => '{"role":"instructor"}',
                    'created_at' => '2026-06-04 10:15:00',
                ],
            ]);

        $controller = new AuditLogController($repo);
        $method = new ReflectionMethod($controller, 'buildPayload');
        $payload = $method->invoke($controller, 25, 2, 'admin');

        $this->assertTrue($payload['success']);
        $this->assertSame(3, $payload['data']['total']);
        $this->assertSame(2, $payload['data']['page']);
        $this->assertSame(25, $payload['data']['limit']);
        $this->assertSame(1, $payload['data']['pages']);
        $this->assertSame('user.created', $payload['data']['logs'][0]['action']);
    }

    /**
     * @requirement FR-91
     */
    public function testBuildPayloadReturnsAtLeastOnePageForEmptyResults(): void
    {
        $repo = $this->createMock(AuditLogRepositoryInterface::class);
        $repo->expects($this->once())
            ->method('count')
            ->with(null)
            ->willReturn(0);
        $repo->expects($this->once())
            ->method('list')
            ->with(50, 0, null)
            ->willReturn([]);

        $controller = new AuditLogController($repo);
        $method = new ReflectionMethod($controller, 'buildPayload');
        $payload = $method->invoke($controller, 50, 1, null);

        $this->assertSame(1, $payload['data']['pages']);
        $this->assertSame([], $payload['data']['logs']);
    }
}

<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Controllers\Api;

use EduQR\Controllers\Api\ReactionController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ReactionControllerTest extends TestCase
{
    /**
     * Built without the constructor so no repository (and therefore no database
     * connection) is created — only the payload shape is under test.
     */
    private function makeController(): ReactionController
    {
        return (new ReflectionClass(ReactionController::class))->newInstanceWithoutConstructor();
    }

    /**
     * The student response must never leak the aggregate counts — that is what
     * makes reacting safe while exam_mode / show_results are off (FR-48).
     *
     * @requirement FR-48
     */
    public function testStudentPayloadCarriesNoAggregateCounts(): void
    {
        $controller = $this->makeController();
        $method = new ReflectionMethod($controller, 'buildStudentPayload');
        $payload = $method->invoke($controller, 'got_it');

        $this->assertTrue($payload['success']);
        $this->assertSame(['reaction'], array_keys($payload['data']));
        $this->assertSame('got_it', $payload['data']['reaction']);

        // No numeric field anywhere in the envelope — counts would be integers
        $this->assertSame([], array_filter($payload['data'], 'is_int'));
        $this->assertArrayNotHasKey('got_it', $payload['data']);
        $this->assertArrayNotHasKey('lost', $payload['data']);
    }

    /**
     * @requirement FR-48
     */
    public function testStudentPayloadEchoesTheStoredReaction(): void
    {
        $controller = $this->makeController();
        $method = new ReflectionMethod($controller, 'buildStudentPayload');

        $this->assertSame('lost', $method->invoke($controller, 'lost')['data']['reaction']);
    }
}

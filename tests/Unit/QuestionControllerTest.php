<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Controllers\Api\QuestionController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class QuestionControllerTest extends TestCase
{
    private function callNormalizeImportPayload(array $body): array
    {
        $controller = new QuestionController();
        $method = new ReflectionMethod($controller, 'normalizeImportPayload');
        $method->setAccessible(true);

        return $method->invoke($controller, $body);
    }

    public function testLegacyFormatNormalization(): void
    {
        $body = [
            'questions' => [
                [
                    'question_text' => 'Q1',
                    'question_type' => 'open_text',
                    'stage' => 'opening',
                ],
                [
                    'question_text' => 'Q2',
                    'question_type' => 'open_text',
                    // no stage, should fallback to middle
                ],
                [
                    'question_text' => 'Q3',
                    'question_type' => 'open_text',
                    'stage' => 'invalid_stage_val', // invalid stage, fallback to middle
                ],
            ],
        ];

        $normalized = $this->callNormalizeImportPayload($body);

        $this->assertCount(3, $normalized);
        $this->assertSame('opening', $normalized[0]['stage']);
        $this->assertSame('middle', $normalized[1]['stage']);
        $this->assertSame('middle', $normalized[2]['stage']);
    }

    public function testNewSectionsFormatNormalizationAndOrdering(): void
    {
        $body = [
            'course_name' => 'CS101',
            'topic_name' => 'Loops',
            'sections' => [
                'closing' => [
                    [
                        'question_text' => 'Closing Q',
                        'question_type' => 'open_text',
                    ],
                ],
                'opening' => [
                    [
                        'question_text' => 'Opening Q',
                        'question_type' => 'open_text',
                    ],
                ],
                'middle' => [
                    [
                        'question_text' => 'Middle Q',
                        'question_type' => 'open_text',
                    ],
                ],
            ],
        ];

        $normalized = $this->callNormalizeImportPayload($body);

        // Strict ordering opening -> middle -> closing
        $this->assertCount(3, $normalized);

        $this->assertSame('opening', $normalized[0]['stage']);
        $this->assertSame('[CS101 | Loops | Acilis] Opening Q', $normalized[0]['question_text']);

        $this->assertSame('middle', $normalized[1]['stage']);
        $this->assertSame('[CS101 | Loops | Orta] Middle Q', $normalized[1]['question_text']);

        $this->assertSame('closing', $normalized[2]['stage']);
        $this->assertSame('[CS101 | Loops | Kapanis] Closing Q', $normalized[2]['question_text']);
    }

    public function testInvalidPayloadStructureThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('import:invalid_payload');

        $body = [
            'not_questions_or_sections' => [],
        ];

        $this->callNormalizeImportPayload($body);
    }

    public function testInvalidQuestionsTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('import:invalid_payload');

        $body = [
            'questions' => 'not an array',
        ];

        $this->callNormalizeImportPayload($body);
    }

    public function testInvalidSectionsTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('import:invalid_payload');

        $body = [
            'sections' => 'not an array',
        ];

        $this->callNormalizeImportPayload($body);
    }

    public function testEmptyPayloadThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('questions:required');

        $body = [
            'questions' => [],
        ];

        $this->callNormalizeImportPayload($body);
    }
}

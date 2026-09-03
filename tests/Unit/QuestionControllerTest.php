<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\AuditLogRepositoryInterface;
use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Controllers\Api\QuestionController;
use EduQR\Exceptions\ValidationException;
use EduQR\I18n\I18nService;
use EduQR\Services\QuestionService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class QuestionControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        I18nService::init(dirname(__DIR__, 2) . '/locales', 'en');
    }

    private function callNormalizeImportPayload(array $body, string $locale = 'en'): array
    {
        I18nService::init(dirname(__DIR__, 2) . '/locales', $locale);

        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $options = $this->createMock(OptionRepositoryInterface::class);
        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $courses = $this->createMock(CourseRepositoryInterface::class);
        $service = new QuestionService($questions, $options, $sessions, $courses);

        $auditLog = $this->createMock(AuditLogRepositoryInterface::class);
        $controller = new QuestionController($service, $auditLog);
        $method = new ReflectionMethod($controller, 'normalizeImportPayload');

        return $method->invoke($controller, $body);
    }

    private function assertInvalidImportPayload(array $body): void
    {
        try {
            $this->callNormalizeImportPayload($body);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_import_payload', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('invalid_import_payload', $e->getPublicCode());
            $this->assertNull($e->getField());
        }
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
        $this->assertSame('[CS101 | Loops | Opening] Opening Q', $normalized[0]['question_text']);

        $this->assertSame('middle', $normalized[1]['stage']);
        $this->assertSame('[CS101 | Loops | Middle] Middle Q', $normalized[1]['question_text']);

        $this->assertSame('closing', $normalized[2]['stage']);
        $this->assertSame('[CS101 | Loops | Closing] Closing Q', $normalized[2]['question_text']);
    }

    public function testNewSectionsFormatUsesTurkishStageLabels(): void
    {
        $body = [
            'course_name' => 'CS101',
            'topic_name' => 'Loops',
            'sections' => [
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
                'closing' => [
                    [
                        'question_text' => 'Closing Q',
                        'question_type' => 'open_text',
                    ],
                ],
            ],
        ];

        $normalized = $this->callNormalizeImportPayload($body, 'tr');

        $this->assertSame('[CS101 | Loops | Açılış] Opening Q', $normalized[0]['question_text']);
        $this->assertSame('[CS101 | Loops | Orta] Middle Q', $normalized[1]['question_text']);
        $this->assertSame('[CS101 | Loops | Kapanış] Closing Q', $normalized[2]['question_text']);
    }

    public function testInvalidPayloadStructureThrows(): void
    {
        $body = [
            'not_questions_or_sections' => [],
        ];

        $this->assertInvalidImportPayload($body);
    }

    public function testInvalidQuestionsTypeThrows(): void
    {
        $body = [
            'questions' => 'not an array',
        ];

        $this->assertInvalidImportPayload($body);
    }

    public function testInvalidSectionsTypeThrows(): void
    {
        $body = [
            'sections' => 'not an array',
        ];

        $this->assertInvalidImportPayload($body);
    }

    public function testEmptyPayloadThrows(): void
    {
        $body = [
            'questions' => [],
        ];

        try {
            $this->callNormalizeImportPayload($body);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('required', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('missing_fields', $e->getPublicCode());
            $this->assertSame('questions', $e->getField());
        }
    }

    public function testCombinedFormatNormalizationAndOrdering(): void
    {
        $body = [
            'course_name' => 'CS101',
            'topic_name' => 'Loops',
            'questions' => [
                [
                    'question_text' => 'Legacy Middle',
                    'question_type' => 'open_text',
                    'stage' => 'middle',
                ],
                [
                    'question_text' => 'Legacy Closing',
                    'question_type' => 'open_text',
                    'stage' => 'closing',
                ],
            ],
            'sections' => [
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
                'closing' => [
                    [
                        'question_text' => 'Closing Q',
                        'question_type' => 'open_text',
                    ],
                ],
            ],
        ];

        $normalized = $this->callNormalizeImportPayload($body);

        // Strict ordering opening -> middle -> closing
        // Within each bucket, questions are appended in order they are processed
        // Total questions: 1 opening, 2 middle (Legacy Middle, Middle Q), 2 closing (Legacy Closing, Closing Q)
        $this->assertCount(5, $normalized);

        $this->assertSame('opening', $normalized[0]['stage']);
        $this->assertSame('[CS101 | Loops | Opening] Opening Q', $normalized[0]['question_text']);

        $this->assertSame('middle', $normalized[1]['stage']);
        $this->assertSame('Legacy Middle', $normalized[1]['question_text']);

        $this->assertSame('middle', $normalized[2]['stage']);
        $this->assertSame('[CS101 | Loops | Middle] Middle Q', $normalized[2]['question_text']);

        $this->assertSame('closing', $normalized[3]['stage']);
        $this->assertSame('Legacy Closing', $normalized[3]['question_text']);

        $this->assertSame('closing', $normalized[4]['stage']);
        $this->assertSame('[CS101 | Loops | Closing] Closing Q', $normalized[4]['question_text']);
    }

    public function testInvalidSectionKeysThrows(): void
    {
        $body = [
            'sections' => [
                'invalid_key' => [
                    [
                        'question_text' => 'Q',
                        'question_type' => 'open_text',
                    ],
                ],
            ],
        ];

        $this->assertInvalidImportPayload($body);
    }
}

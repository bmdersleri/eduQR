<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Services\QuestionGenerationService;
use PHPUnit\Framework\TestCase;

final class QuestionGenerationServiceTest extends TestCase
{
    public function testGenerateFromNotesParsesValidResponse(): void
    {
        $captured = [];
        $transport = function (string $url, array $headers, string $body) use (&$captured): array {
            $captured = [
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ];

            return [
                'status' => 200,
                'body' => json_encode([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'questions' => [
                                        [
                                            'stage' => 'opening',
                                            'question_text' => 'Opening question?',
                                            'question_type' => 'open_text',
                                            'show_results' => false,
                                            'allow_multiple_answers' => false,
                                            'options' => [],
                                        ],
                                        [
                                            'stage' => 'middle',
                                            'question_text' => 'Middle question?',
                                            'question_type' => 'multiple_choice',
                                            'show_results' => true,
                                            'allow_multiple_answers' => false,
                                            'options' => [
                                                ['option_text' => 'A'],
                                                ['option_text' => 'B'],
                                            ],
                                        ],
                                        [
                                            'stage' => 'closing',
                                            'question_text' => 'Closing question?',
                                            'question_type' => 'likert_5',
                                            'show_results' => false,
                                            'allow_multiple_answers' => false,
                                            'options' => [],
                                        ],
                                    ],
                                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        };

        $service = new QuestionGenerationService(
            'https://example.test/v1/chat/completions',
            'secret',
            'demo-model',
            30,
            $transport
        );

        $questions = $service->generateFromNotes('Course title', 'Topic', 'Some lecture notes', 'en');

        $this->assertCount(3, $questions);
        $this->assertSame('opening', $questions[0]['stage']);
        $this->assertSame('Middle question?', $questions[1]['question_text']);
        $this->assertSame('multiple_choice', $questions[1]['question_type']);
        $this->assertStringContainsString('Some lecture notes', $captured['body']);
        $this->assertStringContainsString('Course title', $captured['body']);
    }

    public function testInvalidResponseThrowsInvalidLlMResponse(): void
    {
        $transport = function (string $url, array $headers, string $body): array {
            return [
                'status' => 200,
                'body' => json_encode([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'not json',
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        };

        $service = new QuestionGenerationService(
            'https://example.test/v1/chat/completions',
            'secret',
            'demo-model',
            30,
            $transport
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_llm_response');

        $service->generateFromNotes('Course title', 'Topic', 'Some lecture notes', 'en');
    }
}

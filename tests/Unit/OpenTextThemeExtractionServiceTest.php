<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Services\OpenTextThemeExtractionService;
use PHPUnit\Framework\TestCase;

final class OpenTextThemeExtractionServiceTest extends TestCase
{
    public function testExtractThemesParsesValidResponse_FR65(): void
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
                    'themes' => [
                        [
                            'title' => 'Pointer basics',
                            'summary' => 'Students mention pointer logic.',
                            'keywords' => ['pointers', 'references'],
                            'example_answers' => ['Pointer logic', 'Following references'],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        };

        $service = new OpenTextThemeExtractionService(
            'https://example.test/v1/chat/completions',
            'secret',
            'demo-model',
            30,
            $transport
        );

        $themes = $service->extractThemes('What was hard?', [
            ['answer_text' => 'Pointer logic'],
            ['answer_text' => 'Following references'],
        ], 'en');

        $this->assertCount(1, $themes);
        $this->assertSame('Pointer basics', $themes[0]['title']);
        $this->assertStringContainsString('What was hard?', $captured['body']);
    }

    public function testInvalidResponseThrowsInvalidLlMResponse_FR65(): void
    {
        $transport = function (string $url, array $headers, string $body): array {
            return [
                'status' => 200,
                'body' => json_encode([
                    'themes' => 'not-an-array',
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        };

        $service = new OpenTextThemeExtractionService(
            'https://example.test/v1/chat/completions',
            'secret',
            'demo-model',
            30,
            $transport
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_llm_response');

        $service->extractThemes('What was hard?', [['answer_text' => 'Pointer logic']], 'en');
    }
}

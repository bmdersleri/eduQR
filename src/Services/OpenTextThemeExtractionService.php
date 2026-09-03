<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Config;
use EduQR\Contracts\OpenTextThemeExtractionServiceInterface;
use EduQR\Exceptions\ValidationException;

final class OpenTextThemeExtractionService implements OpenTextThemeExtractionServiceInterface
{
    private const MAX_ANSWERS = 80;
    private const MAX_THEME_TITLE_LEN = 120;
    private const MAX_THEME_SUMMARY_LEN = 300;
    private const MAX_KEYWORD_LEN = 40;
    private const MAX_EXAMPLE_LEN = 200;

    /**
     * @var callable|null
     * Signature: function(string $url, array $headers, string $body): array{status:int, body:string}
     */
    private $transport;

    public function __construct(
        private readonly string $apiUrl = '',
        private readonly string $apiKey = '',
        private readonly string $model = '',
        private readonly int $timeoutSeconds = 30,
        ?callable $transport = null
    ) {
        $this->transport = $transport;
    }

    public static function fromConfig(?callable $transport = null): self
    {
        return new self(
            (string) Config::get('LLM_API_URL', ''),
            (string) Config::get('LLM_API_KEY', ''),
            (string) Config::get('LLM_MODEL', 'gpt-4o-mini'),
            max(5, (int) Config::get('LLM_TIMEOUT_SECONDS', 30)),
            $transport
        );
    }

    /**
     * @param array<int,array<string,mixed>> $answers
     * @return array<int,array<string,mixed>>
     */
    public function extractThemes(string $questionText, array $answers, string $language): array
    {
        if (trim($this->apiUrl) === '') {
            // Infrastructure failure, not a domain one (NFR-78): the provider is
            // unreachable or unconfigured. Published as 503.
            throw new \RuntimeException('llm_unavailable');
        }

        $normalizedAnswers = $this->normalizeAnswers($answers);
        if ($normalizedAnswers === []) {
            return [];
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You summarize classroom open-text answers into themes. Return valid JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($questionText, $normalizedAnswers, $language),
                ],
            ],
            'temperature' => 0.2,
        ];

        $response = $this->sendRequest($this->apiUrl, $payload);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            // Infrastructure failure, not a domain one (NFR-78): the provider is
            // unreachable or unconfigured. Published as 503.
            throw new \RuntimeException('llm_unavailable');
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new ValidationException('invalid_llm_response');
        }

        if (array_key_exists('themes', $decoded)) {
            if (! is_array($decoded['themes'])) {
                throw new ValidationException('invalid_llm_response');
            }

            return $this->normalizeThemeRows($decoded['themes']);
        }

        $content = $decoded['choices'][0]['message']['content']
            ?? $decoded['output_text']
            ?? $decoded['content']
            ?? '';

        return $this->normalizeThemes($content);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sendRequest(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        if ($this->transport !== null) {
            $transport = $this->transport;

            return $transport($url, $headers, $body);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
            ]);
            $responseBody = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return ['status' => $status, 'body' => $responseBody];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => $this->timeoutSeconds,
            ],
        ]);

        $responseBody = (string) @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $matches) === 1) {
                $status = (int) $matches[1];

                break;
            }
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    /**
     * @param array<int,string> $answers
     */
    private function buildPrompt(string $questionText, array $answers, string $language): string
    {
        $answerLines = array_map(static fn (string $answer): string => '- ' . $answer, $answers);
        $formattedAnswers = $this->formatPromptAnswers($answerLines);

        return <<<TXT
Language: {$language}
Question: {$questionText}

Analyze the answers below and return JSON only using this shape:
{
  "themes": [
    {
      "title": "Short theme title",
      "summary": "One-sentence summary",
      "keywords": ["keyword", "keyword"],
      "example_answers": ["answer text", "answer text"]
    }
  ]
}

Rules:
- Group the answers into 3 to 5 concise themes when possible.
- Keep themes grounded in the answers.
- Use the same language as the answers when possible.
- Return an empty themes array when the answers are too sparse to cluster.
- Keep keywords short and specific.
- Do not include hidden chain-of-thought or explanations outside JSON.

Answers:
{$formattedAnswers}
TXT;
    }

    /**
     * @param array<int,string> $lines
     */
    private function formatPromptAnswers(array $lines): string
    {
        return implode("\n", $lines);
    }

    /**
     * @return array<int,string>
     */
    private function normalizeAnswers(array $answers): array
    {
        $normalized = [];
        foreach ($answers as $row) {
            $text = trim((string) ($row['answer_text'] ?? $row['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text, 'UTF-8') > 2000) {
                $text = mb_substr($text, 0, 2000, 'UTF-8');
            }
            $normalized[] = $text;
            if (count($normalized) >= self::MAX_ANSWERS) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeThemes(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || ! isset($decoded['themes']) || ! is_array($decoded['themes'])) {
            throw new ValidationException('invalid_llm_response');
        }

        return $this->normalizeThemeRows($decoded['themes']);
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    private function normalizeThemeRows(array $rows): array
    {
        $themes = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new ValidationException('invalid_llm_response');
            }
            $themes[] = $this->normalizeTheme($row);
        }

        return $themes;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeTheme(array $row): array
    {
        $title = trim((string) ($row['title'] ?? ''));
        $summary = trim((string) ($row['summary'] ?? ''));

        if ($title === '' || mb_strlen($title, 'UTF-8') > self::MAX_THEME_TITLE_LEN) {
            throw new ValidationException('invalid_llm_response');
        }
        if ($summary === '' || mb_strlen($summary, 'UTF-8') > self::MAX_THEME_SUMMARY_LEN) {
            throw new ValidationException('invalid_llm_response');
        }

        $keywordsRaw = $row['keywords'] ?? [];
        if (! is_array($keywordsRaw) || $keywordsRaw === []) {
            throw new ValidationException('invalid_llm_response');
        }

        $keywords = [];
        foreach ($keywordsRaw as $keyword) {
            $value = trim((string) $keyword);
            if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX_KEYWORD_LEN) {
                throw new ValidationException('invalid_llm_response');
            }
            $keywords[] = $value;
        }

        $examplesRaw = $row['example_answers'] ?? [];
        if (! is_array($examplesRaw)) {
            throw new ValidationException('invalid_llm_response');
        }

        $exampleAnswers = [];
        foreach ($examplesRaw as $example) {
            $value = trim((string) $example);
            if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX_EXAMPLE_LEN) {
                throw new ValidationException('invalid_llm_response');
            }
            $exampleAnswers[] = $value;
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'keywords' => $keywords,
            'example_answers' => $exampleAnswers,
        ];
    }
}

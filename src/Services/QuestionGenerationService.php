<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Config;
use EduQR\Contracts\QuestionGenerationServiceInterface;

final class QuestionGenerationService implements QuestionGenerationServiceInterface
{
    private const ALLOWED_TYPES = ['multiple_choice', 'open_text', 'yes_no', 'likert_5'];

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
     * @return array<int,array<string,mixed>>
     */
    public function generateFromNotes(string $courseTitle, ?string $topicName, string $lectureNotes, string $language): array
    {
        if (trim($this->apiUrl) === '') {
            throw new \RuntimeException('llm_unavailable');
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You write classroom polling questions. Return valid JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($courseTitle, $topicName, $lectureNotes, $language),
                ],
            ],
            'temperature' => 0.3,
        ];

        $response = $this->sendRequest($this->apiUrl, $payload);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            throw new \RuntimeException('llm_unavailable');
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('invalid_llm_response');
        }

        $content = $decoded['choices'][0]['message']['content']
            ?? $decoded['output_text']
            ?? $decoded['content']
            ?? '';

        return $this->normalizeGeneratedPayload($content);
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

    private function buildPrompt(string $courseTitle, ?string $topicName, string $lectureNotes, string $language): string
    {
        $topic = trim((string) $topicName);
        $stageSpec = $language === 'tr'
            ? 'opening = açılış, middle = orta, closing = kapanış'
            : 'opening = opening, middle = middle, closing = closing';

        return <<<TXT
Course title: {$courseTitle}
Topic: {$topic}
Language: {$language}

Create exactly 3 classroom questions from the lecture notes below.
Return JSON only using this shape:
{
  "questions": [
    {
      "stage": "opening|middle|closing",
      "question_text": "...",
      "question_type": "multiple_choice|open_text|yes_no|likert_5",
      "show_results": false,
      "allow_multiple_answers": false,
      "options": [
        { "option_text": "...", "is_correct": false }
      ]
    }
  ]
}

Rules:
- Return one question for each stage in the order opening, middle, closing.
- Keep each question grounded in the notes.
- Use the active language for the question text and options.
- If question_type is multiple_choice, include 2 to 8 options.
- If question_type is open_text, yes_no, or likert_5, omit the options array or return an empty array.
- {$stageSpec}

Lecture notes:
{$lectureNotes}
TXT;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeGeneratedPayload(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            throw new \RuntimeException('invalid_llm_response');
        }

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || ! isset($decoded['questions']) || ! is_array($decoded['questions'])) {
            throw new \RuntimeException('invalid_llm_response');
        }

        $questions = [];
        foreach ($decoded['questions'] as $row) {
            if (! is_array($row)) {
                throw new \RuntimeException('invalid_llm_response');
            }

            $questions[] = $this->normalizeQuestion($row);
        }

        if (count($questions) !== 3) {
            throw new \RuntimeException('invalid_llm_response');
        }

        $stages = array_column($questions, 'stage');
        sort($stages);
        if ($stages !== ['closing', 'middle', 'opening']) {
            throw new \RuntimeException('invalid_llm_response');
        }

        return $questions;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeQuestion(array $row): array
    {
        $stage = (string) ($row['stage'] ?? '');
        if (! in_array($stage, ['opening', 'middle', 'closing'], true)) {
            throw new \RuntimeException('invalid_llm_response');
        }

        $text = trim((string) ($row['question_text'] ?? ''));
        if ($text === '' || mb_strlen($text, 'UTF-8') > 500) {
            throw new \RuntimeException('invalid_llm_response');
        }

        $type = (string) ($row['question_type'] ?? '');
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \RuntimeException('invalid_llm_response');
        }

        $question = [
            'question_text' => $text,
            'question_type' => $type,
            'stage' => $stage,
            'show_results' => (bool) ($row['show_results'] ?? false),
            'allow_multiple_answers' => (bool) ($row['allow_multiple_answers'] ?? false),
            'options' => [],
        ];

        if ($type === 'multiple_choice') {
            $options = $row['options'] ?? [];
            if (! is_array($options) || count($options) < 2 || count($options) > 8) {
                throw new \RuntimeException('invalid_llm_response');
            }

            foreach ($options as $opt) {
                if (! is_array($opt)) {
                    throw new \RuntimeException('invalid_llm_response');
                }

                $optText = trim((string) ($opt['option_text'] ?? ''));
                if ($optText === '' || mb_strlen($optText, 'UTF-8') > 200) {
                    throw new \RuntimeException('invalid_llm_response');
                }

                $question['options'][] = [
                    'option_text' => $optText,
                    'is_correct' => (bool) ($opt['is_correct'] ?? false),
                ];
            }
        }

        return $question;
    }
}

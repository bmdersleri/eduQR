<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\ScoringServiceInterface;
use EduQR\Support\TextFold;
use PDO;

/**
 * Quiz scoring — FR-92, NFR-82.
 *
 * Split out of ReportService: the report, the gradebook export and nothing else
 * need a score, and neither of them needs to know how one is arrived at.
 *
 * The PDO handle is required rather than optional. Every method here reads rows,
 * so a ScoringService without a connection could not answer a single question.
 */
final class ScoringService implements ScoringServiceInterface
{
    public function __construct(
        private readonly QuestionRepositoryInterface $questions,
        private readonly PDO                         $pdo,
    ) {
    }

    public function computeScores(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id AS participant_id, nickname
             FROM participants
             WHERE session_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $correctOptions = $this->correctOptions($sessionId);

        $answersStmt = $this->pdo->prepare(
            'SELECT a.participant_id, a.question_id, a.selected_option_id, a.answer_text
             FROM answers a
             INNER JOIN participants p ON p.id = a.participant_id
             WHERE p.session_id = ?'
        );
        $answersStmt->execute([$sessionId]);

        $points = [];
        foreach ($answersStmt->fetchAll(PDO::FETCH_ASSOC) as $answer) {
            $participantId = (int) $answer['participant_id'];
            $points[$participantId] = ($points[$participantId] ?? 0)
                + self::countCorrectMatches($answer, $correctOptions);
        }

        $rows = array_map(
            static function (array $row) use ($points): array {
                $row['score'] = $points[(int) $row['participant_id']] ?? 0;

                return $row;
            },
            $rows
        );

        // Highest score first; ties keep join order so the ranking is stable.
        usort($rows, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: (int) $a['participant_id'] <=> (int) $b['participant_id']);

        $scores = [];
        $rank = 0;
        $prevScore = null;
        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $score = (int) $row['score'];
            if ($score !== $prevScore) {
                $rank = $i + 1;
                $prevScore = $score;
            }
            $scores[] = [
                'participant_id' => (int) $row['participant_id'],
                'nickname' => $row['nickname'],
                'score' => $score,
                'rank' => $rank,
            ];
        }

        return $scores;
    }

    /**
     * Attainable score: the number of questions in the session carrying at least
     * one is_correct option, matching how computeScores() awards points (FR-92).
     */
    public function maxScore(int $sessionId): int
    {
        $questionIds = array_map(
            static fn (array $question): int => (int) $question['id'],
            $this->questions->findBySession($sessionId)
        );

        if ($questionIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT question_id) FROM options
             WHERE is_correct = 1 AND question_id IN (' . $placeholders . ')'
        );
        $stmt->execute($questionIds);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every is_correct option of every question in the session.
     *
     * @return array<int,array<int,array{id:int,text:string}>> keyed by question id
     */
    public function correctOptions(int $sessionId): array
    {
        $questionIds = array_map(
            static fn (array $question): int => (int) $question['id'],
            $this->questions->findBySession($sessionId)
        );

        if ($questionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT id, question_id, option_text FROM options
             WHERE is_correct = 1 AND question_id IN (' . $placeholders . ')'
        );
        $stmt->execute($questionIds);

        $byQuestion = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $option) {
            $byQuestion[(int) $option['question_id']][] = [
                'id' => (int) $option['id'],
                'text' => (string) $option['option_text'],
            ];
        }

        return $byQuestion;
    }

    /**
     * Counts the correct options one answer satisfies — a selected option id, or
     * for fill_in_the_blank / typed answers a case-insensitive text match
     * (FR-31, FR-92).
     *
     * The text comparison is folded in PHP rather than with SQL LOWER(), which
     * this query used to do. SQL case folding is both engine-dependent and
     * Turkish-incorrect: MySQL's LOWER('İ') under utf8mb4_unicode_ci yields
     * "i" + U+0307 COMBINING DOT ABOVE, and SQLite's LOWER() is ASCII-only and
     * leaves 'İ' untouched entirely. Either way a Turkish student who typed
     * "İstanbul" against a correct answer of "istanbul" was marked wrong.
     *
     * Folding here rather than normalizing on write also means no stored column
     * can go stale when an instructor edits the correct answer, and it puts the
     * graded comparison on the path the tests actually exercise — SQLite-backed
     * integration tests could never have caught the SQL-side bug (NFR-77).
     *
     * @param array<string,mixed>                        $answer
     * @param array<int,array<int,array{id:int,text:string}>> $correctOptions
     */
    private static function countCorrectMatches(array $answer, array $correctOptions): int
    {
        $options = $correctOptions[(int) $answer['question_id']] ?? [];
        if ($options === []) {
            return 0;
        }

        $selectedId = $answer['selected_option_id'] === null ? null : (int) $answer['selected_option_id'];
        $typed = trim((string) ($answer['answer_text'] ?? ''));
        $typedKey = $typed === '' ? null : TextFold::forComparisonNormalized($typed);

        $matches = 0;
        foreach ($options as $option) {
            if ($selectedId !== null && $selectedId === $option['id']) {
                $matches++;

                continue;
            }

            if ($typedKey !== null && $typedKey === TextFold::forComparisonNormalized($option['text'])) {
                $matches++;
            }
        }

        return $matches;
    }
}

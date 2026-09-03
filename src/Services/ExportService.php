<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\ExportServiceInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\ScoringServiceInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Support\TextFold;
use PDO;

/**
 * LMS exports — Moodle GIFT + gradebook CSV (T-1113, FR-98, NFR-82).
 *
 * Split out of ReportService: the two file builds share the ownership check and
 * nothing else with the live-results and report-assembly surfaces, and neither
 * of those needs to know how a GIFT block is escaped.
 *
 * The PDO handle is required. Both entry points read option rows directly —
 * buildGiftExport() through optionsForQuestion(), buildGradebook() through the
 * scoring unit — so an ExportService without a connection could not build
 * either file.
 *
 * requireSession() and requireCourse() are duplicated here rather than shared
 * (NFR-82). A base class or a helper service holding them would put the five
 * units back into one object; two short guards is the price of five that can be
 * read on their own.
 */
final class ExportService implements ExportServiceInterface
{
    public function __construct(
        private readonly SessionRepositoryInterface  $sessions,
        private readonly QuestionRepositoryInterface $questions,
        private readonly CourseRepositoryInterface   $courses,
        private readonly PDO                         $pdo,
        private readonly ScoringServiceInterface     $scoring,
    ) {
    }

    /**
     * Builds a Moodle GIFT question export for one session.
     *
     * The file carries no participant data at all. Access is the same course
     * rule every other report export uses (FR-97). exam_mode (FR-96) gates the
     * student result path only and never restricts the instructor's own export.
     *
     * @requirement FR-98
     * @return array{session_id:int,gift:string,question_count:int,downgraded_count:int}
     * @throws \EduQR\Exceptions\DomainException session_not_found | course_not_found | forbidden
     */
    public function buildGiftExport(int $sessionId, int $userId): array
    {
        $this->requireSession($sessionId, $userId);

        $questions = $this->questions->findBySession($sessionId);
        $blocks = [];
        $downgraded = 0;

        foreach (array_values($questions) as $index => $question) {
            $block = $this->giftBlock(
                $question,
                $this->optionsForQuestion((int) $question['id']),
                $index + 1
            );
            $blocks[] = $block['text'];
            $downgraded += $block['downgraded'] ? 1 : 0;
        }

        $gift = '// ' . self::giftCommentLine(t('report.gift.file_header', ['session' => $sessionId])) . "\n";
        if ($blocks !== []) {
            $gift .= "\n" . implode("\n\n", $blocks) . "\n";
        }

        return [
            'session_id' => $sessionId,
            'gift' => $gift,
            'question_count' => count($blocks),
            'downgraded_count' => $downgraded,
        ];
    }

    /**
     * Builds gradebook rows for one session: one row per participant with the
     * quiz score (FR-92), the attainable maximum and the percentage.
     *
     * Anonymization behaves exactly as buildReport(): a session already
     * anonymized in storage (FR-70) is anonymous regardless of the flag.
     *
     * @requirement FR-98
     * @return array{session_id:int,max_score:int,rows:array<int,array{nickname:string,score:int,max_score:int,percentage:float}>}
     * @throws \EduQR\Exceptions\DomainException session_not_found | course_not_found | forbidden
     */
    public function buildGradebook(int $sessionId, int $userId, bool $anonymize = false): array
    {
        $this->requireSession($sessionId, $userId);

        $maxScore = $this->scoring->maxScore($sessionId);
        $rows = [];
        $counter = 1;

        foreach ($this->scoring->computeScores($sessionId) as $scoreRow) {
            $score = (int) $scoreRow['score'];
            $rows[] = [
                'nickname' => $anonymize
                    ? t('report.anonymized_participant', ['number' => $counter++])
                    : (string) $scoreRow['nickname'],
                'score' => $score,
                'max_score' => $maxScore,
                'percentage' => $maxScore > 0 ? round($score / $maxScore * 100, 1) : 0.0,
            ];
        }

        return [
            'session_id' => $sessionId,
            'max_score' => $maxScore,
            'rows' => $rows,
        ];
    }

    /**
     * One GIFT question block plus whether it had to be downgraded to an essay.
     *
     * @param array<string,mixed>            $question
     * @param array<int,array<string,mixed>> $options
     * @return array{text:string,downgraded:bool}
     */
    private function giftBlock(array $question, array $options, int $number): array
    {
        $type = (string) $question['question_type'];
        $title = self::giftEscape(t('report.gift.question_title', ['number' => $number]));
        $text = self::giftEscape((string) $question['question_text']);
        $correct = array_values(array_filter($options, static fn (array $o): bool => (bool) $o['is_correct']));

        // Essay by definition: no correct answer exists for these types.
        if ($type === 'open_text') {
            return ['text' => '::' . $title . ':: ' . $text . ' {}', 'downgraded' => false];
        }

        // A scale item has no correct answer, so a GIFT multiple choice would be
        // invalid. Keep it as an essay and preserve the scale as visible text.
        if ($type === 'likert_5') {
            return [
                'text' => '::' . $title . ':: ' . $text . self::giftOptionList($options) . ' {}',
                'downgraded' => false,
            ];
        }

        // The remaining forms all need a correct answer. Downgrading to a valid
        // essay keeps the question importable instead of emitting broken GIFT.
        if ($correct === []) {
            return [
                'text' => '// ' . self::giftCommentLine(t('report.gift.no_correct_answer')) . "\n"
                    . '::' . $title . ':: ' . $text . self::giftOptionList($options) . ' {}',
                'downgraded' => true,
            ];
        }

        if ($type === 'fill_in_the_blank') {
            return [
                'text' => '::' . $title . ':: ' . $text . ' {=' . self::giftEscape((string) $correct[0]['option_text']) . '}',
                'downgraded' => false,
            ];
        }

        if ($type === 'yes_no' && count($correct) === 1) {
            $flag = self::giftTrueFalseFlag($correct[0]);
            if ($flag !== null) {
                return ['text' => '::' . $title . ':: ' . $text . ' {' . $flag . '}', 'downgraded' => false];
            }
        }

        return [
            'text' => '::' . $title . ':: ' . $text . " {\n" . self::giftAnswerLines($options, count($correct)) . '}',
            'downgraded' => false,
        ];
    }

    /**
     * Answer lines for a GIFT multiple choice block. A single correct option
     * uses the plain "=" / "~" form; several correct options need the weighted
     * form, because plain GIFT accepts only one "=" per question.
     *
     * @param array<int,array<string,mixed>> $options
     */
    private static function giftAnswerLines(array $options, int $correctCount): string
    {
        $wrongCount = count($options) - $correctCount;
        $lines = '';

        foreach ($options as $option) {
            $text = self::giftEscape((string) $option['option_text']);

            if ((bool) $option['is_correct']) {
                $lines .= $correctCount === 1
                    ? '=' . $text . "\n"
                    : '~%' . self::giftCredit(100 / $correctCount) . '%' . $text . "\n";
            } else {
                $lines .= $correctCount === 1
                    ? '~' . $text . "\n"
                    : '~%-' . self::giftCredit($wrongCount > 0 ? 100 / $wrongCount : 100) . '%' . $text . "\n";
            }
        }

        return $lines;
    }

    private static function giftCredit(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 5, '.', ''), '0'), '.');
    }

    /**
     * Renders the option texts as visible text of an essay question, using the
     * GIFT "\n" escape so the block never gains a blank line.
     *
     * @param array<int,array<string,mixed>> $options
     */
    private static function giftOptionList(array $options): string
    {
        if ($options === []) {
            return '';
        }

        $lines = array_map(
            static fn (array $option): string => '- ' . self::giftEscape((string) $option['option_text']),
            $options
        );

        return '\\n' . implode('\\n', $lines);
    }

    /**
     * Maps a yes/no option onto the GIFT true/false form, or null when it does
     * not map cleanly and the multiple choice fallback should be used.
     *
     * @param array<string,mixed> $option
     */
    private static function giftTrueFalseFlag(array $option): ?string
    {
        // NFR-77: both the instructor's option text and the token list are folded
        // with the same rule — "hayır" and "HAYIR" must map onto the same token.
        $value = TextFold::forComparison(trim((string) ($option['option_value'] ?? '')));
        $text = TextFold::forComparison(trim((string) ($option['option_text'] ?? '')));

        foreach ([['T', ['yes', 'true', 'evet', 'doğru']], ['F', ['no', 'false', 'hayır', 'yanlış']]] as [$flag, $tokens]) {
            $tokens = array_map(static fn (string $token): string => TextFold::forComparison($token), $tokens);
            if (in_array($value, $tokens, true) || in_array($text, $tokens, true)) {
                return $flag;
            }
        }

        return null;
    }

    /**
     * Escapes the GIFT control characters. The backslash is remapped in the same
     * pass so escapes added here are never doubled, and every newline becomes the
     * GIFT "\n" escape — GIFT separates questions by a blank line, so a raw line
     * break inside a question body would split it into two broken questions.
     *
     * A leading "//" needs no escape: every question is written with a "::title::"
     * prefix (exactly as Moodle's own GIFT exporter does), so question text can
     * never start a line and can never be read as a comment.
     */
    private static function giftEscape(string $text): string
    {
        $escaped = strtr($text, [
            '\\' => '\\\\',
            '~' => '\\~',
            '=' => '\\=',
            '#' => '\\#',
            '{' => '\\{',
            '}' => '\\}',
            ':' => '\\:',
        ]);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $escaped);
    }

    /** Collapses a translated string onto one line so it stays a valid GIFT comment. */
    private static function giftCommentLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function optionsForQuestion(int $questionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT option_text, option_value, is_correct, order_no
             FROM options
             WHERE question_id = ?
             ORDER BY order_no ASC'
        );
        $stmt->execute([$questionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requireSession(int $sessionId, int $userId): array
    {
        $session = $this->sessions->findById($sessionId);
        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }
        $this->requireCourse((int) $session['course_id'], $userId);

        return $session;
    }

    private function requireCourse(int $courseId, int $userId): array
    {
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            throw new NotFoundException('course_not_found');
        }
        // Owner or co-instructor (FR-97).
        if ($this->courses->roleFor($courseId, $userId) === null) {
            throw new ForbiddenException('forbidden');
        }

        return $course;
    }
}

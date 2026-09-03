<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\ReportService;

/**
 * Phase 9 — Reports & Export (T-901, T-902, T-903, T-904, T-909)
 *
 * All endpoints require instructor authentication (FR-74).
 * Device hash and IP addresses are never included in any output (FR-72, FR-73).
 */
final class ReportController extends ApiController
{
    private ReportService $report;

    public function __construct()
    {
        $this->report = new ReportService(
            new SessionRepository(),
            new QuestionRepository(),
            new OptionRepository(),
            new CourseRepository(),
        );
    }

    // ── GET /api/v1/sessions/{id}/report ──────────────────────────────────────

    public function reportJson(int $sessionId): void
    {
        $user = AuthMiddleware::require();
        $anonymize = filter_var($_GET['anonymize'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $data = $this->report->buildReport($sessionId, (int) $user['id'], $anonymize);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, ['success' => true, 'data' => $data]);
    }

    // ── GET /api/v1/questions/{id}/themes ───────────────────────────────────

    public function themes(int $questionId): void
    {
        $user = AuthMiddleware::require();

        try {
            $data = $this->report->extractThemes($questionId, (int) $user['id']);
        } catch (
            \RuntimeException $e
        ) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, ['success' => true, 'data' => $data]);
    }

    // ── GET /api/v1/courses/{id}/analytics ───────────────────────────────────

    public function courseAnalytics(int $courseId): void
    {
        $user = AuthMiddleware::require();

        try {
            $data = $this->report->buildCourseAnalytics($courseId, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, ['success' => true, 'data' => $data]);
    }

    // ── GET /api/v1/sessions/{id}/report.pdf ─────────────────────────────────

    public function pdf(int $sessionId): void
    {
        $anonymize = filter_var($_GET['anonymize'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $location = '/api/v1/sessions/' . $sessionId . '/report.html';

        if ($anonymize) {
            $location .= '?anonymize=true';
        }

        http_response_code(302);
        header('Location: ' . $location);
        exit;
    }

    // ── GET /api/v1/sessions/{id}/report.csv ─────────────────────────────────

    public function csv(int $sessionId): void
    {
        $user = AuthMiddleware::require();
        $anonymize = filter_var($_GET['anonymize'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $data = $this->report->buildReport($sessionId, (int) $user['id'], $anonymize);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $filename = 'session-' . $sessionId . ($anonymize ? '-anonymized' : '') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');

        // UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');

        // Header row
        fputcsv($out, [
            t('report.csv.header.question'),
            t('report.csv.header.type'),
            t('report.csv.header.nickname'),
            t('report.csv.header.answer'),
            t('report.csv.header.submitted_at'),
        ]);

        foreach ($data['questions'] as $q) {
            if ($q['type'] === 'open_text') {
                foreach ($q['answers'] as $a) {
                    fputcsv($out, [
                        self::csvCell($q['text']),
                        self::csvCell($q['type']),
                        self::csvCell($a['nickname']),
                        self::csvCell($a['answer_text']),
                        self::csvCell($a['created_at'] ?? ''),
                    ]);
                }
                if (empty($q['answers'])) {
                    fputcsv($out, [
                        self::csvCell($q['text']),
                        self::csvCell($q['type']),
                        '',
                        '',
                        '',
                    ]);
                }
            } else {
                foreach ($q['distribution'] as $opt) {
                    fputcsv($out, [
                        self::csvCell($q['text']),
                        self::csvCell($q['type']),
                        '',
                        self::csvCell($opt['option_text']),
                        (string) $opt['count'],
                    ]);
                }
                if (empty($q['distribution'])) {
                    fputcsv($out, [
                        self::csvCell($q['text']),
                        self::csvCell($q['type']),
                        '',
                        '',
                        '',
                    ]);
                }
            }
        }

        fclose($out);
        exit;
    }

    // ── GET /api/v1/sessions/{id}/questions.gift.txt (T-1113) ────────────────

    /**
     * Moodle GIFT question export. A plain file download — eduQR never talks to
     * an LMS, the instructor uploads the file themselves (FR-98).
     */
    public function gift(int $sessionId): void
    {
        $user = AuthMiddleware::require();

        try {
            $data = $this->report->buildGiftExport($sessionId, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $filename = 'session-' . $sessionId . '-questions.gift.txt';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');

        echo $data['gift'];
        exit;
    }

    // ── GET /api/v1/sessions/{id}/gradebook.csv (T-1113) ─────────────────────

    public function gradebook(int $sessionId): void
    {
        $user = AuthMiddleware::require();
        $anonymize = filter_var($_GET['anonymize'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $data = $this->report->buildGradebook($sessionId, (int) $user['id'], $anonymize);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $filename = 'session-' . $sessionId . '-gradebook' . ($anonymize ? '-anonymized' : '') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');

        // UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');

        fputcsv($out, [
            t('report.csv.header.nickname'),
            t('report.gradebook.header.score'),
            t('report.gradebook.header.max_score'),
            t('report.gradebook.header.percentage'),
        ]);

        foreach ($data['rows'] as $row) {
            fputcsv($out, [
                self::csvCell((string) $row['nickname']),
                (string) $row['score'],
                (string) $row['max_score'],
                number_format((float) $row['percentage'], 1, '.', ''),
            ]);
        }

        fclose($out);
        exit;
    }

    // ── GET /api/v1/sessions/{id}/report.html ─────────────────────────────────

    public function html(int $sessionId): void
    {
        $user = AuthMiddleware::require();
        $anonymize = filter_var($_GET['anonymize'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $data = $this->report->buildReport($sessionId, (int) $user['id'], $anonymize);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        header('Content-Type: text/html; charset=utf-8');

        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $s = $data['session'];
        $sm = $data['summary'];

        echo '<!DOCTYPE html><html lang="' . $e($s['language']) . '"><head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . $e(t('report.title')) . ' — ' . $e($s['title']) . '</title>';
        echo '<style>
            body { font-family: sans-serif; max-width: 900px; margin: 2rem auto; color: #333; }
            h1 { font-size: 1.5rem; border-bottom: 2px solid #dee2e6; padding-bottom:.5rem; }
            h2 { font-size: 1.1rem; margin-top: 2rem; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 1rem; }
            td, th { border: 1px solid #dee2e6; padding: .4rem .6rem; text-align: left; font-size: .9rem; }
            th { background: #f8f9fa; }
            .meta { margin-bottom: 1.5rem; color: #555; font-size: .9rem; }
            .meta span { margin-right: 1.5rem; }
            @media print { body { margin: 0; } }
        </style></head><body>';

        echo '<h1>' . $e(t('report.title')) . ': ' . $e($s['title']) . '</h1>';
        echo '<div class="meta">';
        echo '<span><strong>' . $e(t('report.course')) . ':</strong> ' . $e($s['course_title']) . '</span>';
        echo '<span><strong>' . $e(t('report.participant_count')) . ':</strong> ' . $sm['participant_count'] . '</span>';
        echo '<span><strong>' . $e(t('report.question_count')) . ':</strong> ' . $sm['question_count'] . '</span>';
        echo '<span><strong>' . $e(t('report.answer_count')) . ':</strong> ' . $sm['answer_count'] . '</span>';
        // FR-85: locale-aware percent, so tr renders "%83,4" not "83.4%".
        $rateFormatted = fmt_percent((float) $sm['participation_rate'] * 100);
        echo '<span>' . $e(str_replace('{rate}', $rateFormatted, t('report.participation_rate'))) . '</span>';
        if ($s['anonymized'] || $anonymize) {
            echo '<span><em>' . $e(t('report.anonymize')) . '</em></span>';
        }
        echo '</div>';

        foreach ($data['questions'] as $q) {
            // FR-80: the enum is the stable identifier, the label is translated.
            echo '<h2>' . $e($q['text']) . ' <small style="color:#6c757d">(' . $e(t('question.type.' . $q['type'])) . ')</small></h2>';

            if ($q['type'] === 'open_text') {
                if (empty($q['answers'])) {
                    echo '<p><em>' . $e(t('results.no_answers')) . '</em></p>';
                } else {
                    echo '<table><tr><th>' . $e(t('report.csv.header.nickname')) . '</th>';
                    echo '<th>' . $e(t('report.csv.header.answer')) . '</th></tr>';
                    foreach ($q['answers'] as $a) {
                        if ($a['is_hidden']) {
                            continue; // skip hidden in printable view
                        }
                        echo '<tr><td>' . $e($a['nickname']) . '</td><td>' . $e($a['answer_text']) . '</td></tr>';
                    }
                    echo '</table>';
                }

                echo '<h3 style="margin-top:1rem">' . $e(t('results.word_cloud')) . '</h3>';
                if (empty($q['word_cloud'])) {
                    echo '<p><em>' . $e(t('results.word_cloud.empty')) . '</em></p>';
                } else {
                    echo '<div style="display:flex;flex-wrap:wrap;gap:.5rem">';
                    foreach ($q['word_cloud'] as $term) {
                        $fontSize = 0.9 + ((float) ($term['weight'] ?? 0)) * 0.7;
                        echo '<span style="display:inline-block;padding:.35rem .7rem;border-radius:999px;background:#0d6efd;color:#fff;font-size:' . number_format($fontSize, 2, '.', '') . 'rem">';
                        echo $e($term['term']) . ' <small>(' . (int) $term['count'] . ')</small>';
                        echo '</span>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<table><tr><th>' . $e(t('report.answer_distribution')) . '</th>';
                echo '<th style="width:80px">N</th><th style="width:80px">%</th></tr>';
                foreach ($q['distribution'] as $opt) {
                    echo '<tr><td>' . $e($opt['option_text']) . '</td>';
                    echo '<td>' . $opt['count'] . '</td>';
                    echo '<td>' . $e(fmt_percent((float) $opt['percentage'])) . '</td></tr>'; // FR-85
                }
                echo '</table>';
            }
        }

        echo '</body></html>';
        exit;
    }

    // ── CSV formula-injection protection (T-903 / SEC §8) ────────────────────

    /**
     * Returns a string safe for CSV. Cells that start with =, +, -, @ are prefixed
     * with a single apostrophe so spreadsheet apps treat them as text.
     */
    private static function csvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', '|', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}

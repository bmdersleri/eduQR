<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseAnalyticsServiceInterface;
use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\ReportBuilderInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;

/**
 * Course-level analytics — FR-64, NFR-82.
 *
 * Split out of ReportService: this unit measures nothing itself. It walks the
 * sessions of a course, asks the report unit for each one, and adds the
 * summaries up, so a figure on the course screen is by construction the same
 * figure the session report shows.
 *
 * Alone among the five units it takes no PDO handle. Every row it needs
 * arrives through a repository or through ReportBuilder, so a connection would
 * be a constructor parameter no method ever dereferences.
 *
 * requireCourse() is duplicated here rather than shared (NFR-82); see
 * ReportBuilder for why. Only that one guard is copied — this unit is entered
 * by course id and never by session or question id, so the other two would be
 * dead code.
 */
final class CourseAnalyticsService implements CourseAnalyticsServiceInterface
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly CourseRepositoryInterface  $courses,
        private readonly ReportBuilderInterface     $reports,
    ) {
    }

    /**
     * Builds a course-level analytics view across every session in the course.
     *
     * @requirement FR-64
     * @throws \EduQR\Exceptions\DomainException course_not_found | forbidden
     */
    public function buildCourseAnalytics(int $courseId, int $userId): array
    {
        $course = $this->requireCourse($courseId, $userId);
        $sessions = $this->sessions->listByCourse($courseId);

        $sessionAnalytics = [];
        $questionTypeCounts = [
            'multiple_choice' => 0,
            'open_text' => 0,
            'yes_no' => 0,
            'likert_5' => 0,
        ];

        $closedSessionCount = 0;
        $participantCount = 0;
        $questionCount = 0;
        $answerCount = 0;
        $participationTotal = 0.0;
        $lastSessionAt = null;

        foreach ($sessions as $session) {
            $report = $this->reports->buildReport((int) $session['id'], $userId, false);
            $summary = $report['summary'];

            if (($session['status'] ?? '') === 'closed') {
                $closedSessionCount++;
            }

            $participantCount += (int) $summary['participant_count'];
            $questionCount += (int) $summary['question_count'];
            $answerCount += (int) $summary['answer_count'];
            $participationTotal += (float) $summary['participation_rate'];

            foreach ($report['questions'] as $question) {
                $type = $question['type'];
                if (array_key_exists($type, $questionTypeCounts)) {
                    $questionTypeCounts[$type]++;
                }
            }

            $candidateLastSessionAt = $session['started_at'] ?: $session['created_at'];
            if ($candidateLastSessionAt !== null && ($lastSessionAt === null || strcmp((string) $candidateLastSessionAt, (string) $lastSessionAt) > 0)) {
                $lastSessionAt = $candidateLastSessionAt;
            }

            $sessionAnalytics[] = [
                'session_id' => (int) $session['id'],
                'title' => $session['title'],
                'short_code' => $session['short_code'],
                'status' => $session['status'],
                'started_at' => $session['started_at'],
                'closed_at' => $session['closed_at'],
                'participant_count' => (int) $summary['participant_count'],
                'question_count' => (int) $summary['question_count'],
                'answer_count' => (int) $summary['answer_count'],
                'participation_rate' => (float) $summary['participation_rate'],
                'anonymized' => (bool) $session['anonymized'],
                'is_quiz' => (bool) ($session['is_quiz'] ?? false),
            ];
        }

        $sessionCount = count($sessionAnalytics);

        return [
            'course' => [
                'id' => (int) $course['id'],
                'title' => $course['title'],
                'code' => $course['code'],
                'semester' => $course['semester'],
                'status' => $course['status'],
            ],
            'summary' => [
                'session_count' => $sessionCount,
                'closed_session_count' => $closedSessionCount,
                'participant_count' => $participantCount,
                'question_count' => $questionCount,
                'answer_count' => $answerCount,
                'average_participation_rate' => $sessionCount > 0 ? round($participationTotal / $sessionCount, 4) : 0.0,
                'last_session_at' => $lastSessionAt,
            ],
            'question_type_breakdown' => array_map(
                static fn (string $type, int $count): array => ['type' => $type, 'count' => $count],
                array_keys($questionTypeCounts),
                array_values($questionTypeCounts)
            ),
            'sessions' => $sessionAnalytics,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

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

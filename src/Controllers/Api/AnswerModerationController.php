<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Support\Database;
use PDO;

/**
 * POST /api/v1/answers/{id}/hide   (T-810)
 * POST /api/v1/answers/{id}/unhide (T-810)
 *
 * Only an instructor on the session's course (owner or co-instructor, FR-97)
 * may hide/unhide answers.
 * Requires moderation_mode = 1 on the session (enforced at service layer).
 */
final class AnswerModerationController extends ApiController
{
    public function hide(int $answerId): void
    {
        $this->setHidden($answerId, 1);
    }

    public function unhide(int $answerId): void
    {
        $this->setHidden($answerId, 0);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function setHidden(int $answerId, int $isHidden): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $pdo = Database::connection();

        // Load the answer to get question_id
        $stmt = $pdo->prepare('SELECT * FROM answers WHERE id = ? LIMIT 1');
        $stmt->execute([$answerId]);
        $answer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($answer === false) {
            $this->error(404, 'answer_not_found', t('error.question_not_found'));
        }

        // Resolve question → session → ownership
        $questionRepo = Container::questionRepository();
        $question = $questionRepo->findById((int) $answer['question_id']);

        if ($question === null) {
            $this->error(404, 'question_not_found', t('error.question_not_found'));
        }

        $sessionRepo = Container::sessionRepository();
        $session = $sessionRepo->findById((int) $question['session_id']);

        if ($session === null) {
            $this->error(404, 'session_not_found', t('error.session_not_found'));
        }

        $courseRepo = Container::courseRepository();
        $courseId = (int) $session['course_id'];
        $course = $courseRepo->findById($courseId);

        // Owner or co-instructor (FR-97).
        if ($course === null || $courseRepo->roleFor($courseId, (int) $user['id']) === null) {
            $this->error(403, 'forbidden', t('error.forbidden'));
        }

        // Update is_hidden
        $pdo->prepare('UPDATE answers SET is_hidden = ? WHERE id = ?')
            ->execute([$isHidden, $answerId]);

        $this->json(200, [
            'success' => true,
            'data' => ['id' => $answerId, 'is_hidden' => (bool) $isHidden],
        ]);
    }
}

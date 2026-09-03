<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\QuestionService;

final class QuestionImageController extends ApiController
{
    private const UPLOAD_DIR = 'uploads/questions';
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png'];
    private const ALLOWED_EXTS = ['jpg', 'jpeg', 'png'];

    private QuestionService $service;
    private QuestionRepository $questions;

    public function __construct()
    {
        $this->questions = new QuestionRepository();
        $this->service = new QuestionService(
            $this->questions,
            new OptionRepository(),
            new SessionRepository(),
            new CourseRepository(),
        );
    }

    // ── POST /api/v1/questions/{id}/image ─────────────────────────────────────

    public function upload(int $questionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $question = $this->questions->findById($questionId);
        if ($question === null) {
            $this->error(404, 'question_not_found', t('error.question_not_found'));
        }
        $this->authorise((int) $question['session_id'], (int) $user['id']);

        if ($question['status'] !== 'draft') {
            $this->error(422, 'question_not_draft', t('error.invalid_state_transition'));
        }

        $file = $_FILES['image'] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->error(400, 'missing_fields', t('validation.required'), 'image');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->error(400, 'upload_error', t('error.server_error'));
        }
        if ($file['size'] > self::MAX_BYTES) {
            $this->error(422, 'file_too_large', t('validation.text_too_long'), 'image');
        }

        $mime = mime_content_type($file['tmp_name']);
        if (! in_array($mime, self::ALLOWED_TYPES, true)) {
            $this->error(422, 'invalid_file_type', t('error.server_error'), 'image');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED_EXTS, true)) {
            $ext = $mime === 'image/png' ? 'png' : 'jpg';
        }

        $uploadDir = $this->resolveUploadDir();
        $filename = $questionId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $uploadDir . '/' . $filename;
        $relPath = self::UPLOAD_DIR . '/' . $filename;

        // Delete old image if exists
        $this->deleteExistingImage($question['image_path'] ?? null);

        if (! move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->error(500, 'upload_failed', t('error.server_error'));
        }

        $this->service->update($questionId, (int) $user['id'], ['image_path' => $relPath]);

        $this->json(200, [
            'success' => true,
            'data' => ['image_url' => '/' . $relPath],
        ]);
    }

    // ── DELETE /api/v1/questions/{id}/image ───────────────────────────────────

    public function delete(int $questionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $question = $this->questions->findById($questionId);
        if ($question === null) {
            $this->error(404, 'question_not_found', t('error.question_not_found'));
        }
        $this->authorise((int) $question['session_id'], (int) $user['id']);

        if ($question['status'] !== 'draft') {
            $this->error(422, 'question_not_draft', t('error.invalid_state_transition'));
        }

        $this->deleteExistingImage($question['image_path'] ?? null);
        $this->service->update($questionId, (int) $user['id'], ['image_path' => '']);

        $this->json(200, ['success' => true, 'data' => null]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function resolveUploadDir(): string
    {
        $publicDir = dirname(__DIR__, 3) . '/public';
        $dir = $publicDir . '/' . self::UPLOAD_DIR;
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function deleteExistingImage(?string $imagePath): void
    {
        if ($imagePath === null || $imagePath === '') {
            return;
        }
        $abs = dirname(__DIR__, 3) . '/public/' . $imagePath;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    private function authorise(int $sessionId, int $userId): void
    {
        $sessionRepo = new SessionRepository();
        $session = $sessionRepo->findById($sessionId);
        if ($session === null) {
            $this->error(404, 'session_not_found', t('error.session_not_found'));
        }
        $courseRepo = new CourseRepository();
        $courseId = (int) $session['course_id'];
        $course = $courseRepo->findById($courseId);
        // Owner or co-instructor (FR-97).
        if ($course === null || $courseRepo->roleFor($courseId, $userId) === null) {
            $this->error(403, 'forbidden', t('error.forbidden'));
        }
    }
}

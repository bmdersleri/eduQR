<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Exceptions\ConflictException;

/**
 * Thrown when a participant attempts to answer a question they have already
 * answered. Maps to HTTP 409 Conflict.
 *
 * Predates NFR-78 and keeps both its name and its namespace so that the catch
 * sites in `AnswerController` and `templates/student/play.php` — which name the
 * class, not a code — go on working. It is a `ConflictException` so that the
 * type-based mapping in §9.1 covers it like any other domain failure.
 *
 * @requirement NFR-78
 */
final class DuplicateAnswerException extends ConflictException
{
}

<?php

declare(strict_types=1);

namespace EduQR\Services;

/**
 * Thrown when a participant attempts to answer a question they have already answered.
 * Maps to HTTP 409 Conflict.
 */
final class DuplicateAnswerException extends \RuntimeException {}

<?php

declare(strict_types=1);

namespace EduQR\Exceptions;

/**
 * The request conflicts with current state: the thing it would create already
 * exists, or the thing it would do has already been done.
 *
 * Not final: `EduQR\Services\DuplicateAnswerException` extends it so that the
 * catch sites naming that class keep working.
 *
 * @requirement NFR-78
 */
class ConflictException extends DomainException
{
    public const DEFAULT_STATUS = 409;

    public function __construct(
        string $errorCode,
        int $status = self::DEFAULT_STATUS,
        ?string $publicCode = null,
        ?string $field = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $status, $publicCode, $field, $previous);
    }
}

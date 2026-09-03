<?php

declare(strict_types=1);

namespace EduQR\Exceptions;

/**
 * The entity exists and the caller is known, but is not permitted to do this.
 *
 * @requirement NFR-78
 */
final class ForbiddenException extends DomainException
{
    public const DEFAULT_STATUS = 403;

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

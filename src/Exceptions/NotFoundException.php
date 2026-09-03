<?php

declare(strict_types=1);

namespace EduQR\Exceptions;

/**
 * The addressed entity does not exist, or the caller may not know that it does.
 *
 * The second half of that sentence is why `invalid_credentials` lives here: the
 * login endpoint deliberately refuses to confirm whether an email is registered
 * (FR-08), and answers 401 rather than the 404 default.
 *
 * @requirement NFR-78
 */
final class NotFoundException extends DomainException
{
    public const DEFAULT_STATUS = 404;

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

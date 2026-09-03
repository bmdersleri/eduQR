<?php

declare(strict_types=1);

namespace EduQR\Exceptions;

/**
 * The request was understood and rejected on its contents.
 *
 * This covers both malformed input and an action the addressed entity's current
 * state does not admit — activating a question in a closed session, editing a
 * question that has left `draft`. Those read as state errors, but the published
 * contract answers them 422 rather than 409, and the caller fixes them by
 * changing the request rather than by resolving a conflict.
 *
 * @requirement NFR-78
 */
final class ValidationException extends DomainException
{
    public const DEFAULT_STATUS = 422;

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

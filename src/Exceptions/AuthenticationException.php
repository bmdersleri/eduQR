<?php

declare(strict_types=1);

namespace EduQR\Exceptions;

/**
 * The caller has not proved who they are: no credentials, wrong credentials, or
 * no participant token for the session being acted on.
 *
 * Distinct from ForbiddenException, which means the caller *is* known and is
 * still not permitted. The distinction matters to the client: a 401 is worth
 * retrying after signing in, a 403 is not.
 *
 * The published message stays deliberately vague for `invalid_credentials`
 * (FR-08 — never reveal whether an email is registered). That vagueness belongs
 * to the message the boundary resolves, not to the type thrown here.
 *
 * @requirement NFR-78
 */
final class AuthenticationException extends DomainException
{
    public const DEFAULT_STATUS = 401;

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

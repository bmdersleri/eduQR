<?php

declare(strict_types=1);

namespace EduQR\Exceptions;

/**
 * Base class for the four domain exception types described in
 * SYSTEM_ARCHITECTURE.md §9.1 (NFR-78).
 *
 * A domain exception carries the stable, machine-readable error code published
 * in API_SPEC.md §7 — never a human-readable sentence, which belongs in
 * `locales/` and is resolved at the HTTP boundary.
 *
 * It extends \RuntimeException, and `getMessage()` returns the error code, so
 * that call sites written against the pre-NFR-78 convention
 * (`throw new \RuntimeException('course_not_found')`) keep working unchanged
 * while the HTTP layer is migrated to type-based mapping under T-1127.
 *
 * Three pieces of context travel with the code:
 *
 * - **status** — the HTTP status the boundary should answer with. Each subtype
 *   supplies a default; the throw site overrides it where the published
 *   contract differs by context (`session_closed` is 410 at join, 422 while
 *   answering).
 * - **publicCode** — the code published to the client when it differs from the
 *   code that was thrown. `participant_not_found` is published as `not_joined`;
 *   `question_not_active` as `question_closed`. The thrown code stays available
 *   through `getErrorCode()` because it, not the public code, selects the
 *   translation key.
 * - **field** — the input a validation failure points at, for the `field`
 *   member of the error envelope.
 *
 * @requirement NFR-78
 */
abstract class DomainException extends \RuntimeException
{
    /**
     * @param string      $errorCode  Machine-readable code, e.g. 'course_not_found'
     * @param int         $status     HTTP status to answer with
     * @param string|null $publicCode Code published to the client, when it differs
     * @param string|null $field      Input name a validation failure points at
     */
    public function __construct(
        string $errorCode,
        private readonly int $status,
        private readonly ?string $publicCode = null,
        private readonly ?string $field = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }

    /**
     * The code that was thrown. Identical to `getMessage()`; named so that call
     * sites do not have to read a code out of something called a message.
     */
    public function getErrorCode(): string
    {
        return $this->getMessage();
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The code to publish to the client: the override when one was given, the
     * thrown code otherwise.
     */
    public function getPublicCode(): string
    {
        return $this->publicCode ?? $this->getMessage();
    }

    public function getField(): ?string
    {
        return $this->field;
    }
}

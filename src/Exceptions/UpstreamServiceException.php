<?php

declare(strict_types=1);

namespace EduQR\Exceptions;

/**
 * A service we depend on failed us: unreachable, unconfigured, or answering
 * with something we cannot use.
 *
 * The caller did nothing wrong and no entity is in a rejecting state, so none of
 * the four caller-facing types describes this honestly. It is still a domain
 * exception rather than a bare \RuntimeException because both of its codes are
 * published in API_SPEC.md §7 with a defined status — `llm_unavailable` as 503
 * and `invalid_llm_response` as 422 — and a published code must be mappable by
 * type, or the boundary is back to comparing message text.
 *
 * The default is 503, the honest answer when an upstream is simply down. The
 * 422 case overrides it: there the upstream answered, and what it sent back was
 * unusable.
 *
 * Not to be confused with a failure of *our own* infrastructure with no
 * published code — `short_code_exhausted` stays a plain \RuntimeException and
 * is answered 500, because a saturated code space is a bug on our side, not a
 * contract we offer callers.
 *
 * @requirement NFR-78
 */
final class UpstreamServiceException extends DomainException
{
    public const DEFAULT_STATUS = 503;

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

<?php

declare(strict_types=1);

namespace EduQR\Controllers;

use EduQR\Exceptions\DomainException;

/**
 * The one HTTP envelope for `/api/v1/` (NFR-79, ADR-0007).
 *
 * Before this class, seventeen API controllers each carried their own copy of
 * the JSON envelope, the request-body decoder, and a table translating error
 * codes to HTTP statuses. The copies had already drifted. This class owns all
 * three, and the controllers own none.
 *
 * ## Mapping a domain failure
 *
 * There is deliberately **no** global code-to-status table here. A status is
 * not a property of a code: `session_closed` is `410 Gone` at join and resolve,
 * and `422` on the answer path, because entering a session that has ended is a
 * different failure from being told that the session you are already inside has
 * ended (SYSTEM_ARCHITECTURE.md §9.1). A table keyed by code cannot express
 * that; the throw site can, and under NFR-78 it does.
 *
 * So the mapper reads what the exception carries:
 *
 * - `getStatus()`      — the HTTP status, defaulted by subtype, overridden at
 *                        the throw site where the published contract differs.
 * - `getPublicCode()`  — the code published to the client. `participant_not_found`
 *                        publishes as `not_joined`, `question_not_active` as
 *                        `question_closed`, `course_owner_only` as `forbidden`.
 * - `getField()`       — the input a validation failure points at, emitted as
 *                        the `field` member when present.
 *
 * The message is the one thing the exception does not carry, because a message
 * is a translation and Law 1 keeps translations in `locales/`. It is looked up
 * from the **thrown** code, not the published one — `course_owner_only` is
 * published as `forbidden` but still reads "only the course owner may do this".
 *
 * @requirement NFR-79
 */
abstract class ApiController
{
    /**
     * Translation keys for the codes whose message is not `error.<thrown code>`.
     *
     * Keyed by the thrown code (`getErrorCode()`), never by the published one:
     * `course_owner_only` publishes as `forbidden` and would lose its own
     * sentence if this were keyed the other way. Its absence from this table is
     * the proof — `error.course_owner_only` is exactly what it wants.
     *
     * @var array<string, string>
     */
    private const MESSAGE_KEYS = [
        // Published under another name, and the message follows the publication:
        // there is no `error.participant_not_found` string to show a student.
        'participant_not_found' => 'error.not_joined',
        'question_not_active' => 'error.question_closed',
        'question_not_draft' => 'error.invalid_state_transition',

        // Codes whose message key simply predates the code, unchanged here.
        'invalid_option' => 'error.invalid_answer_shape',
        'already_anonymized' => 'common.error',

        // Auth messages are screen strings, not `error.*` strings.
        'too_many_attempts' => 'auth.login.error.locked',
        'invalid_credentials' => 'auth.login.error.invalid',
        'invalid_reset_token' => 'auth.reset.error.invalid_token',

        // Input validation codes (NFR-83). These name a *reason* — the offending
        // field travels separately in `getField()` — so one message serves many
        // codes and many fields: `required` is thrown for `title`, `email`,
        // `question_id` and a dozen more. `error.*` is reserved for sentences
        // that describe a whole failure, which is why none of these use it.
        'required' => 'validation.required',
        'text_too_long' => 'validation.text_too_long',
        'invalid_language' => 'validation.invalid_language',
        'nickname_required' => 'validation.required',
        'nickname_too_long' => 'validation.nickname_too_long',
        'nickname_invalid_chars' => 'student.join.error.invalid',
        'password_too_short' => 'validation.password_too_short',
        'password_too_weak' => 'validation.password_too_weak',
    ];

    // ── Success envelope ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    protected function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    // ── Error envelope ────────────────────────────────────────────────────────

    /**
     * The `field` member is emitted only when there is a field to point at, so
     * that a caller can test for its presence rather than for an empty string.
     */
    protected function error(int $status, string $code, string $message, ?string $field = null): never
    {
        $error = ['code' => $code, 'message' => $message];

        if ($field !== null && $field !== '') {
            $error['field'] = $field;
        }

        $this->json($status, ['success' => false, 'error' => $error]);
    }

    // ── Request body ──────────────────────────────────────────────────────────

    /**
     * A malformed or empty body decodes to `[]` rather than raising: every
     * endpoint validates the fields it needs, so an unparseable body reaches the
     * same "required field missing" answer as an empty one.
     *
     * The `array` return type is load-bearing rather than decorative — a body
     * that is valid JSON but not an object (`5`, `"x"`) fails here as a
     * TypeError and is answered 500 by the global handler. That is what the
     * seven implementations this replaces did, so it is what this does.
     *
     * @return array<mixed> decoded JSON object, or `[]` when empty or malformed
     */
    protected function jsonBody(): array
    {
        return json_decode((string) file_get_contents('php://input'), true) ?? [];
    }

    // ── Exception → response ──────────────────────────────────────────────────

    /**
     * The entry point for a service failure.
     *
     * Typed failures are mapped by what they carry. Anything else is a bug on
     * our side rather than a contract offered to callers — a saturated short-code
     * space, a `PDOException` from a repository — and is answered 500 with a
     * generic localized message, exactly as the per-controller `default` arms
     * this replaces did.
     */
    protected function handleRuntimeException(\RuntimeException $e): never
    {
        if ($e instanceof DomainException) {
            $this->failFromDomain($e);
        }

        $this->error(500, 'server_error', t('error.server_error'));
    }

    protected function failFromDomain(DomainException $e): never
    {
        $mapped = self::domainEnvelope($e);

        $this->json($mapped['status'], $mapped['body']);
    }

    /**
     * The mapper itself, kept side-effect free so it can be tested without a
     * response being sent.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function domainEnvelope(DomainException $e): array
    {
        $error = [
            'code' => $e->getPublicCode(),
            'message' => self::messageFor($e->getErrorCode()),
        ];

        $field = $e->getField();
        if ($field !== null && $field !== '') {
            $error['field'] = $field;
        }

        return [
            'status' => $e->getStatus(),
            'body' => ['success' => false, 'error' => $error],
        ];
    }

    /**
     * The locale key a thrown error code resolves to, without translating it.
     *
     * Choosing the key and rendering it are two jobs. Keeping them apart lets a
     * test pin the choice without depending on whether I18nService happens to
     * have been initialised, which is process-global and test-order dependent.
     */
    public static function messageKeyFor(string $errorCode): string
    {
        return self::MESSAGE_KEYS[$errorCode] ?? 'error.' . $errorCode;
    }

    /**
     * Resolve the user-facing message for a thrown error code (Law 1).
     */
    public static function messageFor(string $errorCode): string
    {
        return t(self::messageKeyFor($errorCode));
    }
}

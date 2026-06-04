# ADR-0005: Course-Scoped Question Bank with JSON Payloads and OpenAI-Compatible LLM Generation

**Status:** Accepted
**Date:** 2026-06-04

## Context

The instructor workflow needs two related capabilities:

1. Reuse questions across multiple sessions in the same course.
2. Generate draft questions from lecture notes with an LLM, then review and publish them into a session.

The existing `questions` table is session-scoped, so it cannot represent reusable bank items without changing the session question lifecycle.

## Decision

Add a separate, course-scoped `question_bank_items` table that stores a normalized question payload in `payload_json`.

Bank items are copied into a session by reusing the existing `QuestionService::create()` validation and option-building logic.

For LLM generation, use a provider-agnostic, OpenAI-compatible HTTP API contract configured through environment variables. The application does not hard-code a single vendor.

## Consequences

**Positive:**
- Session questions remain session-scoped and unchanged.
- Reusable bank entries can be copied into any later session for the same course.
- LLM output can be validated before it becomes a persistent bank item.
- The provider can be swapped without changing the instructor UI or bank storage model.

**Negative:**
- Bank entries are stored as JSON rather than fully normalized rows.
- LLM generation depends on external configuration and can fail independently of the main app.
- Copying from bank to session requires a second validation pass, which is intentional but adds a small amount of code.

## Notes

This choice is deliberately limited to the course scope so the bank remains useful across lecture sessions without introducing a global question marketplace or cross-course sharing rules.

# ADR-0002: HTTP Polling Over WebSockets for MVP

**Status:** Accepted
**Date:** 2026-05-14

## Context

Real-time classroom feedback requires that students see new questions and instructors see new answers within a few seconds. WebSocket (or Socket.IO) connections provide true server-push capability but require a persistent process (Node.js sidecar, Ratchet, or similar) that cannot run on standard cPanel shared hosting.

## Decision

Use HTTP polling for MVP:
- **Instructor panel** polls `/api/v1/sessions/{id}/results` every **2 seconds**
- **Student client** polls `/api/v1/sessions/{short_code}/active-question` every **3 seconds**

Poll interval values are configurable via `.env` (`POLL_INTERVAL_INSTRUCTOR_MS`, `POLL_INTERVAL_STUDENT_MS`).

## Consequences

**Positive:**
- Works on any PHP-capable shared hosting with no additional infrastructure
- Simple to implement, test, and debug — plain HTTP GET responses
- Fully cacheable with `Cache-Control: no-store` semantics for the hot endpoints
- Meets the NFR-02 requirement: a new answer is visible within 5 seconds (≤ 3 s poll + ≤ 2 s aggregation)

**Negative:**
- Higher request volume than WebSockets at classroom scale (≈ 30–200 students × 0.33 req/s each)
- Slightly higher perceived latency than true push (0–3 s vs. sub-100 ms)
- Does not scale to very large audiences (500+ students) without load balancing

## Superseded By

Phase 11: Replace polling with Socket.IO sidecar (Node.js) or Ratchet (PHP). Covered by T-1110.

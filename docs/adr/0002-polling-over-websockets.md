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

## Re-evaluation — 2026-09-03

The Phase 11 plan was to supersede this decision with a Socket.IO sidecar (T-1110). That task is
now **closed without shipping**; polling stands. Reasons:

1. **No requirement pushes for it.** NFR-02 asks for a new answer to be visible within 5 seconds.
   Polling meets that today. WebSockets would reduce server load, not latency — they solve a
   scale problem the project does not yet have.
2. **The cost is a second runtime, not a library.** Socket.IO is merely where the Node dependency
   in the task title came from; Ratchet, Swoole and Mercure carry the same real cost. Every option
   needs a long-running process holding one open socket per student, which PHP-FPM cannot do
   (one worker per connection). That means a process manager, a reverse-proxy WebSocket upgrade,
   a separate authentication path for socket connections, and a third service in the container
   stack (ADR-0006) — and it ends the "runs on any PHP-capable shared host" property that
   ADR-0001 and this ADR were both chosen to preserve.
3. **The load is affordable at the real audience size.** At the 3 s student interval, load is
   roughly `students / 3` requests per second: ~13 req/s at 40 students, ~33 at 100, ~100 at 300.
   The first two are unremarkable. Only lecture-hall scale makes this uncomfortable, and cheaper
   fixes come first.

Two measurement notes taken during the re-evaluation:

- Poll intervals are **hardcoded in the templates** (`3000` in `templates/student/wait.php` and
  `answered.php`, `3000` in `templates/live/results.php`, `5000` in `templates/admin/sessions/detail.php`,
  `2000` in `templates/admin/sessions/results.php`) while `.env.example` declares
  `POLL_INTERVAL_INSTRUCTOR_MS` and `POLL_INTERVAL_STUDENT_MS`, which nothing reads. The
  documented interval above is therefore aspirational, not what ships.
- The student `wait`/`answered` poll only needs to learn whether the active question changed, but
  every poll pays a full request cycle.

Both are addressed by **T-1123 / NFR-76** (bounded-cost polling: `304 Not Modified` on unchanged
state, a cheap active-question endpoint, and intervals actually read from configuration), which
is expected to remove most of the load without touching the architecture.

The shape of that answer is now specified in API_SPEC.md §1.9 and §1.10: five polled endpoints,
each with a version query cheap enough that a `304` costs less than the body it replaces, and one
interval key per screen. The two keys `.env.example` already declared are kept, and two more join
them — the four hardcoded values map to four screens, not to two audiences, so collapsing them to
the existing pair would have silently changed how often two of the screens poll.

Revisit this ADR if a deployment genuinely needs to serve 500+ concurrent students, or if the
project ever gains a Node runtime for another reason — at that point the trade-off changes.

**Status of this decision:** Accepted, reaffirmed 2026-09-03.

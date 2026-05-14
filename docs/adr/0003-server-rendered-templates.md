# ADR-0003: Server-Rendered Templates Over an SPA for the Student Side

**Status:** Accepted
**Date:** 2026-05-14

## Context

The student-facing interface must be reachable on smartphones with limited processing power and potentially slow connections. A JavaScript-heavy SPA (React, Vue) would require a build pipeline, significantly larger initial payload, and may not degrade gracefully when JavaScript is unavailable.

The instructor panel is less constrained (modern desktop browser, stable network) but shares the same hosting environment.

## Decision

Use **server-rendered PHP partials** for all HTML views (both student and instructor):
- No template engine beyond PHP itself (`<?= htmlspecialchars(...) ?>` escaping convention)
- Bootstrap 5 for layout and components
- Vanilla ES2022 modules for interactivity (polling, Chart.js charts)
- No build step — JS files are served as-is

The student answer page degrades to a plain HTML form POST when JavaScript is disabled (NFR-44).

## Consequences

**Positive:**
- First contentful paint is fast — no JS bundle download/parse required before content
- Works without JavaScript for the critical student join → submit flow
- No build toolchain dependency; works on any web server
- Easier for a single developer to maintain without a JS ecosystem

**Negative:**
- Full-page reloads for navigation (acceptable for MVP usage pattern)
- Not suitable for highly interactive features (e.g., real-time collaborative editing)
- Mixing PHP logic and HTML requires strict discipline to avoid spaghetti code

## Superseded By

Phase 11: Migrate instructor panel to React + Vite. Student side to remain lightweight. Covered by SYSTEM_ARCHITECTURE.md §1.2 future stack.

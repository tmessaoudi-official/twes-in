# tool-audit Plan

## Decisions Log
- [2026-10-16 21:51] ASSUMED (review): The sweep's five research agents run on the session's model (no agent override is set: model-override.sh get agent is empty), unnamed, read-only on the tree, each writing its report to var/claude/tool-audit/raw/ and returning one line. Alternatives: a cheaper model per group (riskier on documentation reading).
- [2026-10-16 22:17] ASSUMED (review): E-12 fixed at once (P1, wrong stock under a fault): the publisher guards every domain-event listener; advisor agreed at 3C. Alternatives: per-listener catches with an architecture test.
- [2026-10-16 22:35] ASSUMED (review): B-6 fixed at once (security): symfony/lock on flock for every rate limiter; the advisor placed it after the page. Alternatives: PostgreSQL advisory locks, or a recorded departure.

## Formal Plan

The tool-by-tool audit (docs/SPEC.md § 7, 2026-10-16 17:24; § 8 head, item 3): every tool used here is read against
its own documentation at the version pinned (`make versions`), and each finding is fixed or recorded in § 7 as a
departure with its reason. Lens 55 « code layout and architecture conformance » folds in (§ 7 2026-10-16 17:25).
Optimistic locking and the one refusal contract are the first two fixes (§ 7 2026-10-16 17:17); the rest go in the
order the developer ranks them on the findings page.

1. Sweep: five read-only research agents, one per group of tools, each finding with the documentation page and its
   words, the place in the tree (`file:line`), what the documentation recommends, a severity, the fix, and whether
   § 7 already records it as a departure. Raw reports in `var/claude/tool-audit/raw/`.
   - A: Angular, TypeScript, Angular Material, Tailwind, Vitest
   - B: Symfony, PHP, Monolog, Messenger and Scheduler
   - C: API Platform, Doctrine (ORM, migrations), PostgreSQL
   - D: Docker and Compose, nginx, Caddy, GitHub Actions
   - E: Playwright, and lens 55 (layout and architecture against § 3 and the DDD reference)
2. Synthesis: one ranked list, duplicates merged, each finding checked against the tree before it is kept; a findings
   page for the developer to rank (agree, reject, note), and a § 7 NOTED entry pointing at it.
3. Optimistic locking: a version on every editable record, 409 when it changed since it was opened.
4. The refusal contract: one problem+json refusal with a stable reason, one web mapper in place of the per-feature ones.
5. The other fixes in the developer's order, departures recorded in § 7.

Verification: each fix test-first with its sabotage; the gate, the e2e it touches, the production image and the
deploy check before each push.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Sweep, five groups of tools | L | done | - | var/claude/tool-audit/** |
| 2 | Synthesis and the findings page | M | doing | - | docs/SPEC.md |
| 3 | Optimistic locking | L | todo | - | api/** web/** |
| 4 | The refusal contract | L | todo | - | api/** web/** |
| 5 | The other fixes, in the developer's order | L | todo | - | |
<!-- /progress-block -->
### Blocked
### Needs input
- The ranking of step 2's findings (the developer, on the findings page).
### Needs research
### Fragile
- The sweep reports live in `var/claude/tool-audit/raw/` only (not in git); the page carries each finding's fix, documentation and grade.
### Known issues

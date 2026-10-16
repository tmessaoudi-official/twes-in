# tool-audit Plan

## Decisions Log
- [2026-10-16 21:51] ASSUMED (review): The sweep's five research agents run on the session's model (no agent override is set: model-override.sh get agent is empty), unnamed, read-only on the tree, each writing its report to var/claude/tool-audit/raw/ and returning one line. Alternatives: a cheaper model per group (riskier on documentation reading).
- [2026-10-16 22:17] ASSUMED (review): E-12 fixed at once (P1, wrong stock under a fault): the publisher guards every domain-event listener; advisor agreed at 3C. Alternatives: per-listener catches with an architecture test.
- [2026-10-16 22:35] ASSUMED (review): B-6 fixed at once (security): symfony/lock on flock for every rate limiter; the advisor placed it after the page. Alternatives: PostgreSQL advisory locks, or a recorded departure.
- [2026-10-16 22:43] ASSUMED (review): Optimistic locking takes Doctrine's own mechanism: #[ORM\Version] on each edited aggregate root, checked by a Shared Application port (RecordVersions) whose Doctrine adapter calls EntityManager::lock(LockMode::OPTIMISTIC, version), the flush-time UPDATE … WHERE version closing the race; HTTP always sends the version (required on every PUT), internal callers (imports, fixtures) pass none and skip the check; advisor agreed at goal start. Alternatives: a domain-managed counter under a pessimistic lock; If-Match/ETag headers.
- [2026-10-16 22:43] ASSUMED (review): The stale-version 409 is the refusal contract's first problem type (record-changed, a ProblemExceptionInterface API Platform renders with its type), so the web tells it from the other 409s of the same PUT (a customer number taken); the web reloads, merges through mergeSavedVersion and says so, never retrying by itself; advisor agreed. Alternatives: status alone (ambiguous), an automatic retry after a clean merge.
- [2026-10-16 22:43] ASSUMED (review): Left out of the version check, each listed in the gate with its reason: a person's own toggles where the last word is the intent (module interest, the company opened at sign-in) and the company's single switches (second factor required, period closing), which still bump the company's version that the profile form checks; item POST actions and deletes stay out of « edit » as the 17:17 ruling wrote it, a follow-on under Needs input. Alternatives: every company PUT checked.
- [2026-10-17 01:03] AGREED: All 62 findings marked « fix » by the developer (no notes, no reordering); step 2 done.
- [2026-10-17 01:03] ASSUMED (review): Step 5 follows the findings page's ranked order (unchanged by the developer's marks; advisor agreed).

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
| 2 | Synthesis and the findings page | M | done | - | docs/SPEC.md |
| 3 | Optimistic locking (slice 1: the mechanism, the gate and the customer; then the other live kinds, then the dialogs by context) | L | doing | - | api/** web/** |
| 4 | The refusal contract | L | todo | - | api/** web/** |
| 5 | The other fixes, in the developer's order | L | todo | - | |
<!-- /progress-block -->
### Blocked
### Needs input
- Optimistic locking leaves out, as the 17:17 ruling wrote « edit »: the item POST actions (issue, validate, cancel, pay…) and the deletes; bulk payment already refuses an invoice changed since its check. Whether they check a version too is asked once the PUTs are done.
- A product's barcodes and home locations are replaced as a set by a PUT of their own, and change no product row, so the product's version does not move with them: their slice decides what version they send (the product's, bumped by the replacement, or none, listed).
### Needs research
### Fragile
- The sweep reports live in `var/claude/tool-audit/raw/` only (not in git); the page carries each finding's fix, documentation and grade.
### Known issues

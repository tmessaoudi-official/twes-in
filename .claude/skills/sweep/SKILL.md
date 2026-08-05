---
name: sweep
spotlight: true
description: Use when running a Phase 6 second sweep on uncommitted changes before committing, or reviewing code written outside the standard agent workflow.
user-invocable: true
disallowed-tools: AskUserQuestion
---

<!-- ═══════════════════════════════════════════════════════════════════════════════════
  twes-in ADAPTATION (2026-07-29) of the pdfturbo container port (2026-07-27), which itself came
  from the developer's machine bundle `claude-setup-global-20260722` via the phorj port. The port's
  machinery (plain-text questions, reviewer-subagent certification, `var/claude/**` reports, the
  ≤5-agent cap) is kept verbatim; what was RE-GROUNDED is the domain. pdfturbo's PDF-editor hooks —
  export byte-identity claims, redaction burns, DOCX in-place saves, IndexedDB/`SCHEMA_VERSION`
  persistence, 3-locale key sync, canvas/jsdom test split — are gone, because twes-in has no
  analogue for them. What is load-bearing here instead: money and tax arithmetic, invoice/quote
  state machines, multi-tenant data isolation, API-contract stability for the Angular and Flutter
  clients, e-invoicing (Peppol / EN16931 / Factur-X) schema validity, PDF document rendering, and DB
  migration safety. These deltas OVERRIDE the body below wherever they conflict:

  1. QUESTIONS ARE PLAIN TEXT. `AskUserQuestion` TIMES OUT in this cloud container, so a gate that
     "asks" cannot fire. Every "invoke ask-human" below means: print the question, a minimal
     concrete example, numbered options, and the recommended option FIRST with its reason, as
     ordinary prose — then STOP and wait. Protocol: `.claude/skills/ask-human/SKILL.md`.
  2. NO `advisor()` HERE — the tool does not exist in this environment. Independent certification =
     fresh-context read-only reviewer subagents, run as the three twes-in lenses
     (`domain-correctness-reviewer`, `tenancy-security-reviewer`, `completeness-reviewer` — see
     `/converge`). All three are REAL agent definitions in `.claude/agents/` — spawn them by name
     via the Agent tool rather than re-describing their charter inline, so each lens's attack surface
     stays in one place. Self-grading is the last resort and MUST be disclosed as self-graded.
  3. REPORTS GO TO `var/claude/…` in the repo — gitignored via `/var`, survives
     compaction inside the session, never committed. NOT `~/.claude/projects/…`: that is wiped when
     the container is reclaimed, so a report written there is lost. Never `git add` a report regardless
     — being ignored is what keeps them out of history, not what makes staging them harmless.
  4. `--scope=global|both` IS REMOVED wherever it appears: `~/.claude/` in this container is
     GENERATED from repo files by `scripts/claude-bootstrap/install.sh`, so auditing it audits a copy.
  5. ≤5 concurrent subagents (10 caused ~50% rate-limit failures upstream). Every pipeline agent
     writes its raw output to `var/claude/<stage>/raw/` BEFORE returning — autocompact fires at 80%
     here and in-conversation results do not survive it.
  6. PROJECT RULES WIN on any conflict: `/home/user/twes-in/CLAUDE.md`. It EXISTS and is
     authoritative — READ IT. It carries the licensing invariants, the certification ladder, the
     git-autonomy override, the architecture rules (hexagonal, framework-free `Domain/`), the quality
     gate, and the in-repo plan home (`docs/plans/<topic>.plan.md`, each plan carrying its own
     `## Decisions Log`). On any conflict with a delta above, CLAUDE.md wins.
  7. THREE TOOLCHAINS — THE API PARTLY BUILT, BOTH CLIENTS SCAFFOLDED, INFRA NOT STARTED. twes-in is a Symfony (PHP) REST API + an Angular
     admin front end + a Flutter client for all six targets
     (Android, iOS, Linux, Windows, macOS, Web — its Web build is a SECOND admin interface alongside
     the Angular one), over Postgres, with Docker Compose for local
     dev. **Wave 0 landed on 2026-07-29**, so there IS a source tree now — but only part of one, and
     the shape matters: `api/src/Domain/` (money, pricing, documents — framework-free, zero Composer
     dependencies), `api/src/Infrastructure/` (tenancy via PostgreSQL row-level security, clock,
     UUIDv7), four PHPUnit suites under `api/tests/`, and the gates in `scripts/gates/` with their own
     `test-gates.sh`. **Still absent: `deptrac` alone.** The Symfony application, Doctrine and the `infra/`
     tier all landed (Wave 1 / 2026-08-01 / 2026-08-05), and **PHPStan runs** at level 6 from
     `api/tools/bin/phpstan.phar` with `api/phpstan.neon.dist`. The parenthetical this banner used to carry —
     *"every Composer dist URL is blocked by egress policy"* — was refuted on 2026-08-01: `composer install
     --prefer-source` works, only `api.github.com` is authorization-scoped, and `deptrac` is installable and
     merely unwired. **`admin/` and `mobile/` are SCAFFOLDED** (`ng new` on Angular 22 / Node 26.5.0,
     `flutter create` on Flutter 3.44.8 with all six platform directories) and each is green on its own
     toolchain — lint/analyze, unit tests, production build — but neither holds DOMAIN or TRANSPORT code yet — each does carry its branding seam, and Flutter its font/same-origin controls, with tests.
     The repo also holds `CLAUDE.md`, `README.md`, `VISION.md`, `LICENSE`, `LICENSING.md`,
     `THIRD-PARTY-NOTICES.md`, four plan files under `docs/plans/` (one mandatory reading before any
     application code), `docs/spec/pricing-vectors.json`, `.claude/`,
     `scripts/claude-bootstrap/`, and `.gitignore`. Run the API tier with
     `cd api && php tools/bin/phpunit-12.phar`; the gates are plain PHP and bash and need nothing
     installed. So: never hardcode a build, test
     or lint command. Read `composer.json`, `package.json` and `pubspec.yaml` for the real script names
     (the API tier runs PINNED PHARS, `php tools/bin/{phpunit-12,phpstan,php-cs-fixer}.phar`, NOT
     `vendor/bin/*` — `vendor/bin/phpstan` in particular can never exist, since phpstan is not a Composer
     dependency here at all;
     `npm run lint` / `npm run test` / `ng build` for Angular, `flutter analyze` / `flutter test`
     for the app — verify, do not assume). When the stack a step needs is absent, say so and skip
     the step. A finding invented about code that does not exist is worse than an empty report.
  8. CLEAN-ROOM, AND IT IS LEGALLY LOAD-BEARING. twes-in is *inspired by* Invoice Ninja, NOT forked
     from it. Read-only study clones live under `/tmp/xxx/` and are outside this repo: never copy
     code or text from them, never cite a `/tmp/xxx` path as project source, and never treat
     "upstream does it differently" as a finding on its own.
═══════════════════════════════════════════════════════════════════════════════════════════════ -->

## twes-in dimensions — MANDATORY additions to this skill's review set

Run these **in addition to** the dimensions below, on every sweep of this repo. Skip a dimension only
when the tree it applies to genuinely does not exist yet (on 2026-07-29 that is `infra/` ONLY — `admin/` and
`mobile/` are scaffolded and building, so their dimensions, INCLUDING visual evidence, apply)
— and then **name the dimensions you skipped and why**. A silently skipped dimension is a coverage lie.

- **Money arithmetic (P0).** Amounts are money, not numbers: a decimal string over bcmath end to end, via the Money value object (never integer minor units, never a decimal library — see CLAUDE.md § Architecture),
  never a binary float, and never a float that only *looks* right because the test picked friendly
  inputs. One rounding helper, one rounding mode, applied at one documented point. Any new total,
  discount, surcharge or tax path needs a unit test whose naive-float form is provably wrong (three
  lines of 9.99 at 20% is a good needle: 6.00 per line vs 5.99 per rate group).
- **Tax and e-invoicing correctness (P0).** VAT is computed per rate group, with exemption /
  reverse-charge reasons carried rather than inferred; multi-currency documents use the rate stored on
  the document, never "today's" rate. A change that claims EN16931 / Peppol / Factur-X validity must be
  **validated against a schema or business-rule set**, not asserted in prose — an unvalidated claim is
  the same untested-promise failure mode, and it rots the same way.
- **Invoice / quote / credit state machine (P0).** Every status change goes through the one transition
  guard. A handler that assigns status directly — a bulk action, a recurring-billing job, a gateway
  webhook, a fixture — is the bug even when the resulting status happens to be right. Issued documents
  are append-only in accounting terms: number, date and totals do not mutate; a credit note is how you
  disagree with a past invoice.
- **Multi-tenant isolation (P0 — this is a data breach, not a bug).** Every query, aggregate, export,
  file/PDF fetch, webhook payload and background job must carry the tenant filter its neighbours carry.
  A deliberately cross-tenant path (platform admin, billing reconciliation) must say in code *why* it is
  exempt. Treat any doubt as P0.
- **Payment and gateway safety (P0).** Webhook handlers are signature-verified and idempotent — a replayed
  event must not double-apply a payment. No card data, gateway secret or customer PII in logs, exception
  messages, error payloads or fixtures. Partial payment, over-payment and refund paths must leave
  `balance == total − sum(applied payments)` true by construction, not by a recompute job.
- **API contract stability.** The Symfony API has two independently-shipped consumers: the Angular admin
  and the Flutter app. A changed response shape, field type, enum value, error code, pagination contract
  or date/number serialisation is a **breaking change** until both consumers are updated or the change is
  shown to be purely additive. Say which of the two it is; do not leave it implied.
- **Migration safety.** A migration is reversible or explicitly declared one-way with the reason stated.
  A backfill over money or tax columns states the recompute rule and the rollback plan. Never edit a
  migration that has already been applied anywhere — add a new one.
- **Visual surface ⇒ delivered evidence.** twes-in's visual surfaces are the **Angular admin UI**, the
  **Flutter app UI**, and the **rendered PDF** of an invoice / quote / credit. A change to any of them
  needs (a) an automated guard in the stack that owns it — derive the runner from `package.json` or
  `pubspec.yaml` rather than assuming one — AND (b) a before/after capture.
  **CAPTURED IS NOT DELIVERED: send it with `SendUserFile` in the same turn you take it.** `var/` is
  gitignored and the container is reclaimed, so a screenshot or a rendered PDF left on disk is evidence
  nobody will ever see, and a claim resting on it is unevidenced.
- **Anti-bandaid gate.** For every `||` fallback, `2>/dev/null`, `|| true`, `try {} catch {}` that
  continues, error trap, retry loop, timeout bump or default-value assignment introduced: state the exact
  failure mode, the *physical* evidence that confirmed it (log, measurement, trace, test output), and
  whether the root cause is fixed. No evidence ⇒ **P0**, replace it with a root-cause fix.

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP — do not execute any other steps.
>
> ```
> /sweep — Run a Phase 6 second sweep on uncommitted changes before committing, or review code written outside the standard agent workflow.
>
> No flags — invoked without arguments.
> ```

---

Run a Phase 6 Second Sweep on current uncommitted changes. **Never auto-applies anything — this command only reads and reports.** Use before committing or to review code written outside the standard agent workflow.

## Steps

1. **Assess the diff**:
   - `git diff --stat` — change footprint (files changed, lines added/removed)
   - `git diff` — full diff
   - `git diff --cached --stat` + `git diff --cached` — staged changes too

2. **Review each changed file** using the Phase 6 checklist:

   **All files**:
   - **Bug hunt**: logic errors, off-by-one, null/nil/undefined deref, unchecked error returns, unhandled edge cases
   - **Security**: credentials/secrets in code, injection risks (SQL, shell, template), missing input validation at system boundaries
   - **Contracts**: changed function signatures, changed CLI flags, changed API response shapes, changed config keys — flag every one as a potential breaking change, and for API shapes name the affected client (Angular admin, Flutter app, or both)
   - **Tests**: new behavior without a test? Modified behavior without updated tests? Derive the runner from the manifest of the stack that owns the file (`composer.json` for the API, `package.json` for Angular, `pubspec.yaml` for Flutter) — if that stack is not in the tree yet, say so rather than naming a command that cannot run
   - **Docs**: changed public interface without updated documentation?

   **Shell scripts** (`.sh`):
   - Missing `set -euo pipefail` or equivalent
   - Unquoted variable expansions (`$VAR` instead of `"$VAR"`)
   - Missing error handling after commands that can fail silently
   - `rm -rf` on an unvalidated or unquoted path

   **Config / infra files** (`.yaml`, `.yml`, `Dockerfile`, `.env`):
   - Secrets or credentials committed directly
   - `ARG` without matching `ENV` if runtime access needed
   - Trailing `;` in list vars that would be silently swallowed

3. **Classify each finding** by severity:
   - **CRITICAL**: security hole, data loss risk, broken API contract, shell injection, unhandled error that will crash in production
   - **WARNING**: missing test, logic edge case, performance regression, missing error handling, unquoted variable
   - **NOTE**: style, naming, non-blocking improvement

4. **Output a structured findings table**:

```
## Sweep Results

| # | Severity | File:Line | Finding | Fix |
|---|----------|-----------|---------|-----|
| 1 | CRITICAL  | <file>:<line>         | Invoice list query missing tenant filter | Scope through the tenant-aware repository |
| 2 | WARNING   | <file>:<line>         | Missing exit-code check     | Check return value of the failing command |
| 3 | NOTE      | <file>:<line>         | Unused binding              | Remove or document |

**Verdict**: PASS (safe to commit) or BLOCKED (N critical findings must be fixed first)
```

5. **Save the report**: Write findings to a timestamped file so they survive the session:

```bash
REPO_ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
SWEEP_DIR="$REPO_ROOT/var/claude/sweeps"
mkdir -p "$SWEEP_DIR"
SWEEP_PATH="$SWEEP_DIR/$(date +%Y-%m-%d-%H%M%S).md"
```

Write the full findings table (including verdict) to `$SWEEP_PATH`. Announce: "Sweep report saved to `$SWEEP_PATH`"

## Notes

- A single CRITICAL finding means verdict is BLOCKED
- Multiple WARNINGs with no CRITICAL = PASS with notes (your discretion)
- Apply **Kernighan's Law**: if the diff is hard to understand, that itself is a WARNING (complexity)
- Apply **Chesterton's Fence**: before flagging a removal as wrong, understand why the code existed (`git blame`, commit message)
- Apply **Hyrum's Law**: any changed public interface (CLI flag, function signature, config key, command output format) is a potential contract break — flag it

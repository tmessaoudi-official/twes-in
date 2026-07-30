---
name: expanding-context
description: Use at the start of Phase 1 Brainstorm for any task. Widens context before committing to an approach — ensures no blind spots. Silent by default; surfaces only surprises, material risks, or wrong-problem signals.
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
     the shape matters: `api/src/Domain/` (money, pricing — framework-free, zero Composer
     dependencies), `api/src/Infrastructure/` (tenancy via PostgreSQL row-level security, clock,
     UUIDv7), four PHPUnit suites under `api/tests/`, and the gates in `scripts/gates/` with their own
     `test-gates.sh`. **Still absent:** the Symfony application, Doctrine, PHPStan and deptrac (every
     Composer dist URL is blocked by egress policy — `CLAUDE.md` § Gotchas), and the `infra/` tier, which
     is a README stub. **`admin/` and `mobile/` are SCAFFOLDED** (`ng new` on Angular 22 / Node 26.5.0,
     `flutter create` on Flutter 3.44.8 with all six platform directories) and each is green on its own
     toolchain — lint/analyze, unit tests, production build — but neither holds DOMAIN or TRANSPORT code yet — each does carry its branding seam, and Flutter its font/same-origin controls, with tests.
     The repo also holds `CLAUDE.md`, `README.md`, `VISION.md`, `LICENSE`, `LICENSING.md`,
     `THIRD-PARTY-NOTICES.md`, four plan files under `docs/plans/` (one mandatory reading before any
     application code), `docs/spec/pricing-vectors.json`, `.claude/`,
     `scripts/claude-bootstrap/`, and `.gitignore`. Run the API tier with
     `cd api && php tools/bin/phpunit-12.phar`; the gates are plain PHP and bash and need nothing
     installed. So: never hardcode a build, test
     or lint command. Read `composer.json`, `package.json` and `pubspec.yaml` for the real script names
     (typically `vendor/bin/phpunit` / `vendor/bin/phpstan` / `vendor/bin/php-cs-fixer` for the API,
     `npm run lint` / `npm run test` / `ng build` for Angular, `flutter analyze` / `flutter test`
     for the app — verify, do not assume). When the stack a step needs is absent, say so and skip
     the step. A finding invented about code that does not exist is worse than an empty report.
  8. CLEAN-ROOM, AND IT IS LEGALLY LOAD-BEARING. twes-in is *inspired by* Invoice Ninja, NOT forked
     from it. Read-only study clones live under `/tmp/xxx/` and are outside this repo: never copy
     code or text from them, never cite a `/tmp/xxx` path as project source, and never treat
     "upstream does it differently" as a finding on its own.
═══════════════════════════════════════════════════════════════════════════════════════════════ -->

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP — do not execute any other steps.
>
> ```
> /expanding-context — Use at the start of Phase 1 Brainstorm for any task. Widens context before committing to an approach — ensures no blind spots. Silent by default; surfaces only surprises, material risks, or wrong-problem signals.
>
> No flags — invoked automatically by Claude during the reasoning workflow.
> ```

---

# Expanding Context

You are about to commit to an approach. This skill ensures you see the full territory
before you do.

**What this skill does**: runs the 23-dimension expansion framework internally (self-contained — the standalone `/expand` skill was not imported; the six groups below ARE the framework). You do NOT
output the full expansion to the user — you use the findings to inform your Phase 1 and
Phase 2 thinking. Produce only a brief internal summary (3-5 bullets) then proceed.

**When to surface the full expansion to the user**: only if they explicitly asked for it
(e.g. "what am I missing?", "give me the full picture", "expand this"). Otherwise keep it
internal and continue with the enriched context.

---

## Internal expansion (run silently)

Quickly sweep these 6 groups — 1-2 observations each, focus on surprises and non-obvious
items only. Skip dimensions where nothing is notable.

**I — Identity**: Is the scope what it appears to be? Is the mental model obvious?

**II — Structure**: What depends on this? What does this depend on? Any hidden contracts?

**III — Behavior**: What are the non-obvious failure modes? What edge cases exist?

**IV — Quality**: Any known issues, dark observability, or test gaps that matter here?

**V — Context**: What constraints or assumptions are load-bearing for this decision?

**VI — Discovery**: Any gaps, risks, or contradictions worth surfacing before proceeding?

**Questions**: Generate 2-3 internal questions — especially Strategic ones. If any question
would materially change the approach, surface it to the user before continuing.

---

## Decision gate

After the internal sweep:

- **No surprises found**: proceed to Phase 2 with enriched context. No output needed.
- **1-2 notable findings**: mention them briefly inline ("One thing worth noting before we
  proceed: ...") then continue.
- **Material risk or wrong-problem signal**: STOP and surface it explicitly. Ask the user
  before continuing. This is more valuable than any implementation.

---

## Skip conditions

Do NOT invoke this skill when:
- Input is already broad ("review the whole codebase", "plan the next sprint")
- Task is a simple lookup or rename with no design decisions
- You already ran this skill in the current session for the same topic
- The user explicitly said "just do it" (Small task signal — respect it)

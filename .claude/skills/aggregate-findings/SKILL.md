---
name: aggregate-findings
spotlight: true
description: Cross-stage synthesis of review reports — deduplicates findings that appear across /inspect, /sleuth, /gaps, /sweep and /inspect --vision runs. Produces one prioritized master list with cross-references instead of N separate reports. Use after running two or more of those skills.
user-invocable: true
args: "[--top=N] [--since=<date>]"
side-effects: Writes a consolidated report to var/claude/reports/aggregate-<date>.md (gitignored; never committed)
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
     UUIDv7), four PHPUnit suites under `api/tests/`, and six gates in `scripts/gates/` with their own
     `test-gates.sh`. **Still absent:** the Symfony application, Doctrine, PHPStan and deptrac (every
     Composer dist URL is blocked by egress policy — `CLAUDE.md` § Gotchas), and the `infra/` tier, which
     is a README stub. **`admin/` and `mobile/` are SCAFFOLDED** (`ng new` on Angular 22 / Node 26.5.0,
     `flutter create` on Flutter 3.44.8 with all six platform directories) and each is green on its own
     toolchain — lint/analyze, unit tests, production build — but neither holds application code yet.
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

> If ARGUMENTS contains `--help`: output the text below verbatim, then immediately STOP — do not execute any other steps. (`--help` takes precedence over all other flags.)
>
> ```
> /aggregate-findings — Cross-stage synthesis of review reports — deduplicates findings across /inspect, /sleuth, /gaps, /sweep and vision runs into one prioritized master list.
> ```
>
> Then output the complete flag table from the **"Flags"** section below. Then STOP.

---

# /aggregate-findings

## When to use
Run after **two or more** of `/inspect`, `/sleuth`, `/gaps`, `/sweep`, `/inspect --vision` have produced reports, to synthesize them into one deduplicated, prioritized master list. (`/mega-analysis` was imported by neither the pdfturbo port nor this twes-in adaptation, so there is no umbrella run to key off: the stage set is simply whatever reports exist under `var/claude/`.)

## Flags
- `--top=N` — show only the top N unique findings (default: all)
- `--since=<date>` — only aggregate reports dated on/after this (default: the most recent report per skill)

## Step 0 — Locate reports

```bash
# Reports live in the repo under var/ (gitignored) — see the adaptation header.
REPO_ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
REPORT_ROOT="$REPO_ROOT/var/claude"
mkdir -p "$REPORT_ROOT/reports"
# Enumerate what actually exists — this list IS the stage set:
find "$REPORT_ROOT" -name '*.md' -not -path '*/reports/*' | sort
```

Enumerate every report found and state the count before reading — an unlisted report is a coverage gap.

## Step 1 — Read all stage reports (parallel, max 5 at a time)

Read every report enumerated in Step 0, in batches of ≤5 files (the project's concurrency ceiling for LLM-backed agents is 5). Typical stage set here: `/inspect`, `/inspect --vision`, `/sleuth`, `/gaps`, `/sweep`. There is no global-scope pass — those flags were removed on import.

Read each file and pass to Step 2.

## Step 2 — Spawn 3 synthesis agents (parallel)

Spawn exactly 3 agents with the full report content:

### Agent 1: Deduplication detector
Prompt: "You are given N stage reports from this project's review skills — a Symfony REST API, an Angular admin front end and a Flutter app in one repo, so the same underlying defect is often reported once per stack and must be collapsed. Your job is to identify findings that appear in 2 or more stage reports — these are the highest-confidence issues. For each cross-stage finding, list: the finding name/ID, which stages mention it, what each stage says (noting any contradictions), and a deduplicated one-sentence summary. Output as a markdown table. Only report findings that appear in ≥2 stages."

### Agent 2: Priority ranker
Prompt: "You are given N stage reports from this project's review skills. Your job is to produce a single master priority list of ALL unique findings (not cross-stage-only), ranked by: (1) severity (P0/High before P1/Med), (2) fix cost (Quick before Long), (3) breadth of impact. Remove exact duplicates. Format: numbered list with severity badge, one-line description, estimated fix time, and which stage it came from. Cap at 50 entries."

### Agent 3: Quick wins extractor
Prompt: "You are given N stage reports from this project's review skills. Your job is to extract all 'quick win' findings: severity P1 or higher AND fix cost ≤30 min. These are the highest-value, lowest-effort items. Output as a table: finding, stage, exact file/line, exact fix, minutes. Include at most 20 rows; rank by impact."

## Step 3 — Synthesize into consolidated report

Combine all 3 agent outputs into:

```markdown
# Aggregate Findings — <date>
Generated: <timestamp> | Stages: <N, named> | Raw findings: ~<N> | Unique after dedup: ~<N>

## Top 10 Cross-Stage Findings (appear in ≥2 stages — highest confidence)
[Agent 1 table]

## Quick Wins (P1+ / ≤30 min fix)
[Agent 3 table]

## Master Priority List (all unique findings, ranked)
[Agent 2 list]
```

## Step 4 — Save and report

Save to `var/claude/reports/aggregate-<date>.md` (gitignored — never `git add` it).

Report to user:
- Total unique findings
- Cross-stage duplicates found and collapsed
- Top 5 quick wins
- Suggest: "Run `/aggregate-findings --top=10` to see just the highest priority items"
- Name any report that existed but could not be parsed — a silently dropped stage is a coverage lie

## Self-reflection
After saving, note any findings where the stages disagreed (e.g., one stage calls it P0, another calls it P2). Flag these as "conflicting severity" in the report.

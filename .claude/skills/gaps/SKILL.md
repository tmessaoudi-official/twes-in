---
name: gaps
spotlight: true
description: Use when hunting for incomplete implementations, missing features, unfulfilled promises, stubs, TODO markers, partial feature flags, or undocumented capabilities across a project.
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
     `/converge`). `.claude/agents/` exists but is EMPTY on 2026-07-29, so those names are lens
     briefs you spawn with, not files you can read. Self-grading is the last resort and MUST be
     disclosed as self-graded.
  3. REPORTS GO TO `var/claude/…` in the repo — gitignored (`/var`), survives compaction inside the
     session, never committed. NOT `~/.claude/projects/…`: that is wiped when the container is
     reclaimed, so a report written there is lost.
  4. `--scope=global|both` IS REMOVED wherever it appears: `~/.claude/` in this container is
     GENERATED from repo files by `scripts/claude-bootstrap/install.sh`, so auditing it audits a copy.
  5. ≤5 concurrent subagents (10 caused ~50% rate-limit failures upstream). Every pipeline agent
     writes its raw output to `var/claude/<stage>/raw/` BEFORE returning — autocompact fires at 80%
     here and in-conversation results do not survive it.
  6. PROJECT RULES WIN on any conflict: `/home/user/twes-in/CLAUDE.md` — the deploy gate, the
     certification ladder, the git-autonomy override, and the in-repo plan home
     (`docs/plans/<topic>.plan.md`, each plan carrying its own `## Decisions Log`). Honest note:
     that CLAUDE.md does NOT exist yet (2026-07-29). Until it lands, these deltas plus the repo
     conventions ARE the rules — never cite a CLAUDE.md section as if you had read it.
  7. THREE TOOLCHAINS, NONE OF THEM BUILT YET. twes-in is a Symfony (PHP) REST API + an Angular
     admin front end + a Flutter mobile/desktop app, over Postgres, with Docker Compose for local
     dev. On 2026-07-29 the repo holds only `.claude/`, `scripts/claude-bootstrap/` and an empty
     `docs/plans/` — there is no `src/` tree of any kind. So: never hardcode a build, test or lint
     command. Read `composer.json`, `package.json` and `pubspec.yaml` for the real script names
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
> /gaps — Use when hunting for incomplete implementations, missing features, unfulfilled promises, stubs, TODO markers, partial feature flags, or undocumented capabilities across a project.
>
> Flags:
>   --quick                        Run agents A, F, H only (~3 min, debt markers/test gaps/error handling)
>   --focus=<A|B|C|D|E|F|G|H|I|J> Run a single detection agent
>   --target=<path>                Analyze a specific directory (default: $CLAUDE_PROJECT_DIR)
>   --priority=high                Report Now items only — skip Soon/Later
>
>   --scope=project|global|both is REMOVED here — see the adaptation header, delta 4.
> ```

---

# /gaps — Incompleteness & Missing Feature Detector

Hunt for incomplete implementations, missing features, unfulfilled promises, and pending work across the entire project. Produces a prioritized roadmap. **Never auto-applies — presents a ranked plan and waits for explicit direction.**

Differentiation from `/inspect`: `/inspect` finds *what's wrong with existing things*. `/gaps` finds *what's missing or unfinished* — features described but not implemented, code started but not completed, documentation that promises things the code doesn't deliver.

Use `--quick` (agents A, F, H only — debt markers, test gaps, error handling; ~3 min), `--focus=<A|B|C|D|E|F|G|H|I|J>` (single agent), `--target=<path>` (analyze a specific directory), `--priority=high` (Now items only — skip Soon/Later).

---

## Step 0: Setup

```bash
# There is no --scope here (adaptation delta 4): one pass over $TARGET, which is either the
# explicit --target path or the project root.
TARGET="${target_arg:-${CLAUDE_PROJECT_DIR:-$PWD}}"
PROJECT_SLUG=$(echo "$TARGET" | sed 's|^/|-|; s|/|-|g')
REPO_ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
GAPS_DIR="$REPO_ROOT/var/claude/gaps"
mkdir -p "$GAPS_DIR"
TODAY=$(date +%Y-%m-%d-%H%M)
REPORT_PATH="${output_arg:-$GAPS_DIR/$TODAY.md}"
PRIOR_GAPS=$(ls "$GAPS_DIR"/*.md 2>/dev/null | sort -r | head -1 || true)
```

Announce: "Scanning gaps: `$TARGET` → saving to `$REPORT_PATH`"

If a prior `/gaps` run exists: note its date. Agents will flag items that have been pending since the prior run as [STALE], helping prioritize chronic incompleteness over fresh debt.

**No `--scope` handling** (adaptation): a single pass over `$TARGET`. If a caller passes `--scope=global` or `--scope=both`, say plainly that the flag was removed for this repo and why, then run the project pass.

## Step 1: Detect Project Context

```bash
ls "$TARGET"/{composer.json,package.json,pubspec.yaml,Makefile,docker-compose*.y*ml,README.md} 2>/dev/null
[[ -f "$TARGET/CLAUDE.md" ]] && head -60 "$TARGET/CLAUDE.md"
git -C "$TARGET" log --oneline -10 2>/dev/null || true
find "$TARGET" -maxdepth 2 \( -name "*.md" -o -name "*.sh" -o -name "*.php" -o -name "*.ts" \
  -o -name "*.dart" \) -not -path '*/vendor/*' -not -path '*/node_modules/*' 2>/dev/null | head -20
```

Summarize: which of the three stacks exist (Symfony API / Angular admin / Flutter app), approximate project age (from git log), primary language(s), team size (from commit authors). Pass as `PROJECT_CONTEXT` to each agent.

**On a greenfield tree, most of what is "missing" is simply unwritten.** When a stack is absent, do not enumerate its unbuilt features as gaps — that turns the whole product backlog into a report. Restrict findings to promises that something in the repo actually makes: a documented command with no file behind it, a plan in `docs/plans/` whose stated next step was never taken, a placeholder or template marker left in place, a config key referenced but never defined. Say plainly which stacks were absent and therefore not scanned.

## Step 2: Spawn Gap-Detection Agents

Respect flags:
- `--quick`: spawn only agents A, F, H (debt markers, test gaps, error handling)
- `--priority=high`: instruct agents to report Now-priority items only
- `--focus=<X>`: spawn only that agent
- Default: spawn in two sequential batches — **never exceed 5 concurrent LLM agents** (5 is the proven rate-limit ceiling; >5 causes ~50% failures):
  - **Batch 1**: spawn agents A–E in one message; wait for all 5 to complete before continuing
  - **Batch 2**: spawn agents F–J in one message; wait for all 5 to complete

**Agent A: Explicit Debt Markers** — TODO, FIXME, HACK, XXX, WORKAROUND, BUG, KLUDGE comments; classified by age and actionability.

**Agent B: Stubs & Placeholder Detection** — empty function bodies, `raise NotImplementedError`, hardcoded placeholder returns, shell scripts with TODO bodies.

**Agent C: Partial Feature Implementations** — unhandled switch/match cases, parsed-but-unused flags or query parameters, stub API handlers, features with empty branches, and state machines with missing transitions (invoice / quote / credit status graphs and payment application are the ones that matter here — an unreachable or unhandled status is a real gap, not a style note).

**Agent D: Undocumented Features (code exists, docs absent)** — commands not in CLAUDE.md, env vars not in .env.example, Makefile targets not in README, hook scripts not in docs.

**Agent E: Promised Features (docs mention, code missing)** — commands in CLAUDE.md with no file, env vars documented but never read, workflows referencing scripts that don't exist, and any capability a `docs/plans/<topic>.plan.md` records as decided but that nothing implements.

**Agent F: Missing Tests for Named Features** — named features with zero tests, error paths with no test, workflows with no integration test.

**Agent G: Config & Environment Gaps** — env vars used but not in .env.example, required config with no startup validation, placeholder values with no format hint.

**Agent H: Missing Error Handling Paths** — happy path without error path, silent switch/if fall-throughs, cleanup that runs on success but not failure.

**Agent I: Template & Placeholder Markers** — `<!-- ADAPT: -->` markers, unactivated `.sh.template` files, `{{VAR}}` placeholders, skeleton banners still present.

**Agent J: Integration & Dependency Stubs** — interfaces with no concrete implementation, abstract base classes never subclassed, plugin systems with no plugins registered, unused imports, Makefile targets calling non-existent scripts.

---

## Step 3: Synthesize Gaps Report

```markdown
# /gaps Report — <DATE>
Scanned: <DATE> | Project: <TARGET> | Stack: <PROJECT_CONTEXT>

## Executive Summary
[3-5 sentences: dominant type of incompleteness, most actionable gap, overall completeness feel]

## Priority Roadmap

### NOW — Act immediately (blocking or high-impact)
| # | Category | Gap | Location | Effort |

### SOON — Important but not blocking
| # | Category | Gap | Location | Effort |

### LATER — Nice to have
| # | Category | Gap | Location | Effort |

## Findings by Category
[A through J sections]

## Stale Gaps [CHRONIC] *(only if prior run exists)*
Items present in prior run that are still unfilled.

## Quick Wins (Effort=Quick, Priority=Now or Soon)
| # | Category | Gap | Next action |
```

## Step 4: Save Report

Write the synthesized report to `$REPORT_PATH`.

## Step 4b: Self-Reflection

Spawn ONE agent to reflect on this command's own definition using the just-saved report as evidence. Pass the actual `$REPORT_PATH` value. The agent produces its blind spots, prompt drift, and proposed changes sections, then reads `$REPORT_PATH` and writes the complete updated file (original content + its block) using the Write tool. Returns only: "Self-reflection appended." Parent announces: "Self-reflection complete — see `$REPORT_PATH`"

## Step 5: Present Roadmap — Hard Stop

Show: Executive summary, full NOW table, Quick Wins table, counts of SOON/LATER.

**Ask in PLAIN TEXT** (per `/ask-human` — `AskUserQuestion` is forbidden here) and STOP:

```
N gaps found (Now: X | Soon: Y | Later: Z). Nothing has been changed — every finding above is
a proposal.

1. Fix specific gaps (recommended) — name the IDs, e.g. `G1, G3`.
2. Show all Soon items.
3. Show one category in full — name it, e.g. `category B`.
4. Nothing — close the report.
```

*Never auto-fills anything. The user decides what to close.*

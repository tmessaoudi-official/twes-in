---
name: sleuth
spotlight: true
description: Use when hunting for hidden behavioral bugs — silent failures, logic traps, contract violations, cross-component inconsistencies, and runtime edge-case failures that pass code review but fail at runtime.
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

## twes-in lens K — MANDATORY additional agent (cross-surface divergence)

Beyond agents A–J, always run **agent K** on this repo:

> **K — Cross-surface divergence.** twes-in computes the same accounting truth in more than one place —
> the Symfony API, the Angular admin, the Flutter app, the generated PDF, the e-invoicing document, and
> the database schema — and every one of those is a chance for two answers to disagree. Hunt for places
> they can:
> **(1) Money/tax recompute divergence** — totals, tax or balance computed in the API and *recomputed*
> anywhere else for display (Angular, Flutter, a PDF template, a report query). A second implementation
> of rounding is a divergence by construction; find which one is authoritative and whether anything says so.
> **(2) State-machine divergence** — a status transition allowed by the entity/service guard but reachable
> another way (a bulk endpoint, a recurring-billing job, a gateway webhook, a fixture), or forbidden in one
> path and silently permitted in another. Look for statuses assigned by direct write.
> **(3) Tenant-scoping divergence** — a query, aggregate, export, attachment fetch or queued job that omits
> the tenant filter its neighbours apply. This is an information leak, not a cosmetic bug: treat any doubt
> as the highest severity you have.
> **(4) Payment ↔ document divergence** — partial payment, over-payment, refund or a replayed gateway
> webhook leaving stored balance/status disagreeing with the sum of applied payments; a handler that is not
> idempotent under redelivery.
> **(5) E-invoicing divergence** — a Peppol / EN16931 / Factur-X document whose VAT breakdown, currency,
> rounding or totals disagree with the stored invoice, or whose validity is asserted but never validated
> against a schema or business-rule set.
> **(6) Migration ↔ model divergence** — a migration (or its down path) that disagrees with the mapping it
> is supposed to implement: a nullability, precision/scale, default, unique or FK constraint present in one
> and not the other. Money columns with the wrong scale are silent corruption.
> For each: file + line, which two surfaces diverge, the smallest invoice/payment fixture that would show
> it, and whether a test guards it in the stack that owns it — read `composer.json` / `package.json` /
> `pubspec.yaml` for the runner rather than assuming one. If that stack is not in the tree yet, say so;
> if it is and no test covers the divergence, that absence IS the finding.
> Research only, no writes.

Report its findings as category **K** alongside A–J.

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then immediately STOP — do not execute any other steps. (`--help` takes precedence over all other flags.)
>
> ```
> /sleuth — Hunt for hidden behavioral bugs: silent failures, logic traps, contract violations, cross-component inconsistencies, and runtime edge-case failures.
> ```
>
> Then output the complete flag table from the **"Step 2: Spawn Investigation Agents"** section below. Then STOP.

---

# /sleuth — Behavioral Bug Hunter

Hunt for hidden behavioral bugs: silent failures, logic traps, contract violations, cross-component inconsistencies, and runtime edge-case failures. **Never auto-applies — produces a confidence-scored investigation report and waits for direction.**

Differentiation from `/inspect`: `/inspect` performs *structural health checks* (dead code, deprecations, missing tests). `/sleuth` performs *behavioral investigation* — bugs that pass code review but fail at runtime, inconsistencies across components, and subtle logic flaws that only manifest under specific conditions.

Use `--focus=<A|B|C|D|E|F|G|H|I|J|K>` (single agent), `--target=<path>` (analyze a specific directory), `--quick` (A+B+F only — logic, silent failures, shell bugs). **`--scope=global|both` is REMOVED here** (adaptation): `~/.claude/` in this container is generated by `scripts/claude-bootstrap/install.sh` from files that already live in the repo, so auditing it audits a copy. Project scope only.

---

## Step 0: Setup

```bash
# --target picks the directory to investigate; there is no --scope here (see adaptation above).
TARGET="${target_arg:-${CLAUDE_PROJECT_DIR:-$PWD}}"
# Reports live in the REPO under var/ (gitignored, survives compaction) — never in ~/.claude,
# which is wiped when the container is reclaimed. Never commit them.
REPO_ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
SLEUTH_DIR="$REPO_ROOT/var/claude/sleuth"
INSPECT_DIR="$REPO_ROOT/var/claude/inspections"
mkdir -p "$SLEUTH_DIR" "$SLEUTH_DIR/raw"
TODAY=$(date +%Y-%m-%d-%H%M)
REPORT_PATH="${output_arg:-$SLEUTH_DIR/$TODAY.md}"
PRIOR_SLEUTH=$(ls "$SLEUTH_DIR"/*.md 2>/dev/null | sort -r | head -1 || true)
LATEST_INSPECT=$(cat "$INSPECT_DIR/latest.json" 2>/dev/null || true)
```

Announce: "Sleuthing project: `$TARGET` → saving to `$REPORT_PATH`"

If a prior `/sleuth` run exists: note its date. Agents will skip already-confirmed findings and focus on new behavioral evidence.
If a prior `/inspect` run exists: note its date so agents don't re-examine already-catalogued structural issues.

**No `--scope` handling** (adaptation): a single pass over `$TARGET`. If a caller passes `--scope=global` or `--scope=both`, say plainly that the flag was removed for this repo and why, then run the project pass.

## Step 1: Detect Project Context

```bash
ls "$TARGET"/{composer.json,package.json,pubspec.yaml,Makefile,docker-compose*.y*ml,*.sh} 2>/dev/null
# twes-in is three stacks in one repo — enumerate which of them actually exist before assuming any:
for M in composer.json package.json pubspec.yaml; do find "$TARGET" -name "$M" -not -path '*/vendor/*' \
  -not -path '*/node_modules/*' -maxdepth 3 2>/dev/null; done
[[ -f "$TARGET/CLAUDE.md" ]] && head -60 "$TARGET/CLAUDE.md"
[[ -f "$TARGET/README.md" ]] && head -40 "$TARGET/README.md"
git -C "$TARGET" log --oneline -5 2>/dev/null || true
```

Summarize: tech stack, project type, primary language(s), and **which of the three stacks are present**
(Symfony API / Angular admin / Flutter app). Pass as `PROJECT_CONTEXT` to each agent. If none of them is
present yet — the state on 2026-07-29 — say so plainly, run the agents over what does exist (shell
hooks, skill and agent definitions, config, and the two plan files under `docs/plans/`, which carry
every ruling in the project), and do not invent findings about application code that has not been
written.

## Step 2: Spawn Investigation Agents

Respect flags:
- `--quick`: spawn only A (Logic), B (Silent Failures), F (Shell Bugs)
- `--focus=<X>`: spawn only that agent
- Default: spawn in two sequential batches — **never exceed 5 concurrent LLM agents** (5 is the proven rate-limit ceiling; >5 causes ~50% failures):
  - **Batch 1**: spawn agents A–E in one message; wait for all 5 to complete before continuing
  - **Batch 2**: spawn agents F–J in one message; wait for all 5 to complete
  - **Batch 3**: spawn agent K (twes-in cross-surface divergence, above) on its own — it is mandatory on this repo, and folding it into Batch 2 would breach the ≤5-concurrency cap

Replace `<TARGET>` with the actual path. Replace `PROJECT_CONTEXT`, `CURRENT_DATE`, `PRIOR_SLEUTH_NOTE`. When constructing each agent prompt, replace `$OUTPUT_FILE` with `$SLEUTH_DIR/raw/A.md` for Agent A, `$SLEUTH_DIR/raw/B.md` for Agent B, … `$SLEUTH_DIR/raw/J.md` for Agent J, `$SLEUTH_DIR/raw/K.md` for Agent K.

---

**Agent A: Logic & Condition Traps**

> Investigate `<TARGET>` for logic and condition bugs. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) tautological conditions — boolean expressions that are always true or always false due to how they're written (check compound conditions with &&/|| where one side makes the other irrelevant); (2) dead branches — if/else or switch/case branches whose condition can never be true given prior guards; (3) negation applied to the wrong level — `!a && b` instead of `!(a && b)`, or NOT applied to an entire condition that should only negate one operand; (4) operator precedence bugs — `&` vs `&&`, `|` vs `||`, arithmetic inside conditions without parentheses; (5) assignment instead of comparison (`=` vs `==`, `:=` vs `=`); (6) loop conditions that never terminate or always skip — off-by-one in while/for loop bounds; (7) short-circuit evaluation used as control flow in a way that silently skips important operations. For each: file:line, what the condition actually evaluates to, what it was likely intended to evaluate to, confidence [High/Medium/Low], reproduction scenario (what input triggers the wrong path). Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

**Agent B: Silent Failure Patterns**

> Investigate `<TARGET>` for silent failure bugs. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) function calls whose return value is ignored when that return value signals success/failure — especially `mkdir`, `cp`, `mv`, `curl`, `git`, any DB write; (2) exception/error handlers that catch a broad error type and then continue execution as if the operation succeeded; (3) operations that return a fallback/default value on failure without indicating that failure occurred — e.g., `dict.get(key, "")` used where an empty string is indistinguishable from "not found"; (4) try/catch blocks that swallow the error and return a success-looking result; (5) async operations whose errors are not propagated to the caller; (6) optional chaining or null coalescing that masks a bug (the object being null is itself a bug, not a valid state); (7) functions that catch errors during setup but then use partially-initialized state as if setup succeeded. For each: file:line, what failure is silently swallowed, what incorrect behavior results, confidence [High/Medium/Low], reproduction scenario. Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

**Agent C: Contract & Interface Violations**

> Investigate `<TARGET>` for contract and interface violations. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) function documentation that claims a parameter is optional but the code crashes or produces wrong output when it's absent; (2) function documentation says "returns X" but the code returns Y in some branches (including returns `null` when the doc implies non-null); (3) type annotations or signatures that do not match actual usage — parameter typed as `string` but code checks `if typeof x === 'number'`; (4) a function described as "idempotent" or "safe to call multiple times" but has state-modifying side effects; (5) API contracts documented in CLAUDE.md or README that the code doesn't honor (e.g., "port env var is optional" but code panics if unset); (6) hook or plugin interfaces where callers pass data in format A but the hook expects format B; (7) CLI flags documented as accepting a value but parsed as a boolean, or vice versa. For each: file:line, what was promised vs what is delivered, confidence [High/Medium/Low], how to reproduce the violation. Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

**Agent D: Cross-Component Inconsistencies**

> Investigate `<TARGET>` for inconsistencies *between* components or files. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) the same concept or constant defined with different values in different files — e.g., a timeout set to 30s in one file and 60s in another for the same operation; (2) the same error condition handled in incompatible ways across similar modules — one module logs and retries, another logs and returns success, another panics; (3) naming that implies identical behavior but code diverges — `validate_user` and `check_user` that should be equivalent but have different validation rules; (4) data flow where producer writes field `user_id` and consumer reads `userId` (case or naming mismatch); (5) feature flag checked for the same feature in multiple places with different logic (different default, different truthiness check); (6) two config-loading paths that produce different results for the same config file; (7) two callers of the same function that pass arguments in different orders (suggesting one of them is wrong). For each: files involved, what's inconsistent, which behavior is likely correct, confidence [High/Medium/Low]. Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

**Agent E: Edge Case & Boundary Traps**

> Investigate `<TARGET>` for edge case and boundary handling bugs. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) string operations that assume non-empty input but are called with values that can be empty (zero-length path, empty array to join, empty string to parse); (2) integer arithmetic that can overflow or underflow — division where denominator can be zero; (3) off-by-one in loops: `for i in range(len(arr))` vs `for i in range(len(arr)-1)` vs indices into arr[i+1]; (4) locale-sensitive operations — string comparisons, number formatting, date parsing that break for non-English locales or different timezones; (5) file paths with spaces or special characters that are passed as unquoted shell strings; (6) HTTP/API responses where the "empty success" response (200 with empty body or empty array) is indistinguishable from a real success response; (7) regex patterns that match more or less than intended — greedy vs non-greedy, anchors missing, character classes including/excluding unintended chars; (8) money and quantity boundaries — an amount held in a binary float, a currency assumed to have 2 decimals, a discount or tax rate that can exceed 100% or go negative, a zero-total or zero-quantity line, an amount formatted or parsed with the wrong decimal/thousands separator for the active locale, and date boundaries on billing periods (timezone or DST shifting an invoice or a recurring run into the wrong period). For each: file:line, the edge case, why existing code handles it incorrectly, confidence [High/Medium/Low]. Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

**Agent F: Shell & Script Behavioral Bugs**

> Investigate `<TARGET>` for shell and Bash behavioral bugs. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) word splitting bugs — variables containing spaces used unquoted in command arguments, causing the shell to split on whitespace (`cp $FILE $DEST` where $FILE might contain spaces); (2) glob expansion in unexpected contexts — `ls $DIR/*` where $DIR is user-supplied, `rm $PATTERN` where PATTERN could match more than intended; (3) subshell scope loss — variables assigned inside `$()` or `( )` that are read outside (the assignment is silently lost); (4) exit code propagation through pipes — `cmd1 | cmd2` where cmd1 fails but the pipeline exit code is 0 because cmd2 succeeded and `pipefail` is not set; (5) IFS modification not restored — script modifies IFS for parsing but doesn't restore it, breaking subsequent word splitting; (6) `trap` handlers that don't propagate exit codes or that leave resources uncleaned on unexpected exit; (7) `local` variable used in function but the same name is used in a caller — in Bash, `local` is actually dynamic scoping, not lexical. For each: file:line, what the bug is, what input triggers it, confidence [High/Medium/Low]. Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

**Agent G: Environment & Config Behavioral Bugs**

> Investigate `<TARGET>` for environment and configuration behavioral bugs. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) env var used with a default that masks a required value — `${DB_HOST:-localhost}` where running without DB_HOST means the app silently connects to localhost instead of failing with a clear error; (2) config value read as string in one place but as integer in another — `parseInt(process.env.PORT)` vs `process.env.PORT + ""` in the same codebase; (3) two config settings that conflict when both are set — e.g., a debug flag that disables a timeout, and a timeout flag — when both are set, which wins? (4) env var that is used before it can be set — loaded at module import time before the `.env` file is parsed; (5) config file path that works in development but breaks in production due to relative path resolution; (6) feature toggle that is documented as "default off" but the code defaults it to "on" when the env var is absent; (7) settings whose interaction is non-commutative — order of config loading matters but is undocumented. For each: file:line, what the config bug is, when it triggers, confidence [High/Medium/Low]. Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

**Agent H: Timing, Ordering & State Bugs**

> Investigate `<TARGET>` for timing, ordering, and stale state bugs. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) code that assumes a dependency is ready without checking — e.g., querying a DB connection before waiting for it to be established; (2) lazy initialization patterns where the initialized value is used in a code path that can run before initialization (race condition or wrong execution order); (3) caching of values that can become stale — a computed value cached at startup that should be re-evaluated periodically; (4) startup scripts that write a "success" marker before all initialization is complete — health checks pass too early; (5) `sleep`-based synchronization — `sleep 2` used where a proper poll/wait loop with a condition check should be used; (6) global mutable state that is read and written from multiple code paths without coordination; (7) retry logic that doesn't clear error state between attempts — retrying an operation that depends on state from the previous (failed) attempt. For each: file:line, the timing or state bug, what triggers it, confidence [High/Medium/Low]. Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

**Agent I: Docker & Container Behavioral Bugs**

> Investigate `<TARGET>` for Docker and container behavioral bugs. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) `ARG` vs `ENV` confusion — `ARG` values are available only during build; if a value needs to persist at container runtime, it must be promoted with `ENV VAR=${VAR}`; find ARG-only vars that are referenced in CMD or ENTRYPOINT scripts; (2) COPY/ADD ordering that invalidates the Docker layer cache unnecessarily — frequently-changing files copied before installing dependencies means every file change triggers a full reinstall; (3) health check scripts that always exit 0 or that check the wrong condition, causing the container to report "healthy" when it's not actually ready; (4) ENTRYPOINT/CMD that uses the shell form instead of exec form (`CMD command arg` vs `CMD ["command", "arg"]`) — shell form means signals (SIGTERM) are not forwarded to the process; (5) volumes that shadow important files — `VOLUME /app` declared before `COPY . /app` means the copy is hidden by the volume mount at runtime; (6) base image instructions that reset build-time state — `FROM` in a multi-stage build that discards ARG values not explicitly re-declared; (7) healthcheck `--interval` and `start_period` values that don't match the actual startup time of the service (too tight = premature unhealthy; too loose = late detection of actual failures). For each: file:line, the container bug, what scenario reveals it, confidence [High/Medium/Low]. Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

**Agent J: Documentation-Behavior Divergence**

> Investigate `<TARGET>` for divergences between documentation and actual behavior. PROJECT_CONTEXT. CURRENT_DATE. PRIOR_SLEUTH_NOTE. Hunt for: (1) example commands in README or CLAUDE.md that fail when actually run — wrong syntax, missing required args, deprecated flags, wrong order; (2) env var documented with one default value but code uses a different default; (3) workflow step in docs that skips a required prerequisite — e.g., "run make build" before "start the service" but the docs omit "you must export PORT first"; (4) description of a function/command that says it's safe to call multiple times but the code has side effects that compound; (5) documented output format that has changed — code produces JSON, docs show a table; (6) settings documented as optional but code panics or silently corrupts state when absent; (7) CLAUDE.md Claude Code Tooling section that lists commands or hooks that don't exist or work differently than described. For each: file:line (documentation) vs file:line (code), what's documented vs what actually happens, confidence [High/Medium/Low], impact of the divergence on a developer following the docs. Write your full findings to `$OUTPUT_FILE` using the Write tool. Return only: 'Complete — [N] findings.'

---

## Step 3: Synthesize Investigation Report

After all agents complete, spawn ONE synthesis agent with a fresh context. Before spawning, replace every placeholder with its actual value: `<SLEUTH_DIR>` → the actual sleuth dir, `<REPORT_PATH>` → the actual report path, `<DATE>` → today's date, `<TARGET>` → the target path, `<PROJECT_CONTEXT>` → the detected stack string, `<PRIOR_DATE>` → prior sleuth date or "none", `<INSPECT_DATE>` → prior inspect date or "none".

> You are synthesizing behavioral bug findings into a master report. Read these raw investigation files (skip any that don't exist):
> - `<SLEUTH_DIR>/raw/A.md` — Logic & Conditions
> - `<SLEUTH_DIR>/raw/B.md` — Silent Failures
> - `<SLEUTH_DIR>/raw/C.md` — Contracts & Interfaces
> - `<SLEUTH_DIR>/raw/D.md` — Cross-Component
> - `<SLEUTH_DIR>/raw/E.md` — Edge Cases
> - `<SLEUTH_DIR>/raw/F.md` — Shell & Scripts
> - `<SLEUTH_DIR>/raw/G.md` — Environment & Config
> - `<SLEUTH_DIR>/raw/H.md` — Timing & State
> - `<SLEUTH_DIR>/raw/I.md` — Docker & Containers
> - `<SLEUTH_DIR>/raw/J.md` — Doc-Behavior Divergence
> - `<SLEUTH_DIR>/raw/K.md` — Cross-Surface Divergence (twes-in lens)
>
> Synthesize all findings into the report below, then write the complete result to `<REPORT_PATH>` using the Write tool.
>
> Confidence guide: **High** = provable from code alone (condition always true/false, variable always wrong type). **Medium** = likely a bug but requires runtime context. **Low** = a smell worth reviewing.
>
> Report format:
>
> # /sleuth Report — <DATE>
> Investigated: <DATE> | Project: <TARGET> | Stack: <PROJECT_CONTEXT>
> Prior sleuth: <PRIOR_DATE> | Prior inspect: <INSPECT_DATE>
>
> ## Executive Summary
> [3-5 sentences: dominant failure pattern, highest-confidence finding, most prevalent bug class]
>
> ## Findings by Category
> [For each finding, sorted High first, then Medium, then Low:]
> ### [High|Medium|Low] — [Short title] — Category [A-K]
> **Location**: `path/to/file:line`
> **Type**: [Logic Trap / Silent Failure / Contract Violation / Cross-Component / Edge Case / Shell Bug / Config Bug / Timing Bug / Container Bug / Doc Divergence / Cross-Surface Divergence]
> **Confidence**: [High / Medium / Low] — [1 sentence why]
> **What's wrong**: [1-2 sentences]
> **Reproduction scenario**: [specific trigger]
> **Fix sketch**: [correct behavior]
> ---
>
> ## Summary Table
> | Category | High | Medium | Low | Total |
> |----------|------|--------|-----|-------|
> | A: Logic & Conditions | | | | |
> | B: Silent Failures | | | | |
> | C: Contracts & Interfaces | | | | |
> | D: Cross-Component | | | | |
> | E: Edge Cases | | | | |
> | F: Shell & Scripts | | | | |
> | G: Environment & Config | | | | |
> | H: Timing & State | | | | |
> | I: Docker & Containers | | | | |
> | J: Doc-Behavior Divergence | | | | |
> | K: Cross-Surface Divergence | | | | |
> | **TOTAL** | | | | |
>
> Return only: 'Synthesis complete.'

## Step 4: Save Report

The synthesis agent has already written the report to `$REPORT_PATH`. Write a JSON summary:
```json
{"date": "<DATE>", "target": "<TARGET>", "high": N, "medium": N, "low": N, "total": N, "report": "<path>"}
```

Announce: "Sleuth report saved to `$REPORT_PATH`"

## Step 4b: Self-Reflection (about this command, not the project)

Spawn ONE agent to reflect on this command's own definition using the just-saved report as evidence:

> Read `.claude/skills/sleuth/SKILL.md` in full (repo-native — this skill is committed, not installed). You are auditing this command — not the project it just investigated. Then read the report at the path provided to you. Based on what was actually found this run, produce ONLY this block:
>
> ---
> ### Command Self-Reflection — `/sleuth`
> *Proposals only. Nothing auto-applies — user reviews and accepts or rejects each.*
>
> **Blind Spots** *(findings this run that no current agent prompt, A–K, was designed to catch)*
> For each: finding summary → which agent's stated scope it fell under → why the prompt missed it.
> Write "None detected" if all findings were anticipated by existing prompts.
>
> **Prompt Drift** *(agents that overreached or underdelivered vs. their stated scope)*
> For each: agent letter → what drifted → proposed one-sentence fix.
> Write "None detected" if all agents stayed on scope.
>
> **Missing Coverage** *(behavioral bug categories with no agent at all in A–K)*
> For each: missing category → why it matters → 1-sentence agent prompt sketch.
> Write "None" if all key behavioral bug classes are covered.
>
> **Proposed Changes** *(max 3 — P1=high value / P2=quality improvement / P3=nice-to-have)*
> | # | Priority | Change | Rationale |
> |---|----------|--------|-----------|
> Write "No changes proposed this run." if nothing to add.
> ---
>
> After drafting your block above, write ONLY the self-reflection block (starting from `---` through the closing `---`) to `$REPORT_PATH.reflection` using the Write tool. Do NOT read or rewrite the full report. Return only: "Self-reflection complete — [N] proposals."

Pass the actual `$REPORT_PATH` value to the agent. After the agent returns, run:
```bash
[ -f "$REPORT_PATH.reflection" ] && cat "$REPORT_PATH.reflection" >> "$REPORT_PATH" && rm -f "$REPORT_PATH.reflection"
```
Announce: "Self-reflection complete — see `$REPORT_PATH`"

## Step 5: Present Findings — Non-Blocking Close

Show in conversation:
- Executive summary
- All High-confidence findings in full (from Findings by Category, sorted first)
- Summary table

**Close with a non-blocking offer** (twes-in adaptation — no interrupts). State plainly: `N findings
(High: X | Medium: Y | Low: Z). Nothing has been changed — every finding above is a proposal.` Then give
the report path and one actionable line the developer can ignore, e.g. *"Say which IDs to fix (e.g. A1,
A3) and I'll take them."* Do NOT block the turn on a question, and never auto-apply a fix.

*Never auto-fixes anything. The user decides what to act on.*

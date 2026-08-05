---
name: converge
spotlight: true
description: Run the project's MAXIMAL certification ladder (CLAUDE.md § "Certification ladder"), or a deeper tunable convergence sweep, over an audit/migration/gate. Defaults ARE the twes-in ladder — 3 adversarial evidence-based lenses, TWO consecutive fully-clean rounds, cap 5 rounds, certified by fresh-context reviewer subagents. Override with --cycles/--converge/--angles/--certify. Runs AUTONOMOUSLY by default (twes-in) and reports progress every cycle; --ask restores the approval gate. Escalates in PLAIN TEXT if it cannot converge.
user-invocable: true
args: "[--cycles=N] [--converge=K] [--scope=ladder|3C|6C|custom] [--angles='angle1;angle2;angle3'] [--certify=reviewer|self] [--ask] [--auto-cap=N]"
side-effects: None — read-only analysis loop; findings incorporated into conversation context only.
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

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then immediately STOP — do not execute any other steps. (`--help` takes precedence over all other flags.)
>
> ```
> /converge — Run the project's MAXIMAL certification ladder (3 adversarial evidence-based lenses, TWO consecutive clean rounds, cap 5, fresh-context reviewer subagents), or a deeper tunable convergence sweep. Every parameter is overridable. Runs autonomously by default; --ask restores the approval gate.
> ```
>
> Then output the complete flag table from the **"Flags"** section below. Then STOP.

---

# /converge — Convergence Loop

Runs a structured multi-angle convergence loop. **Autonomous by default in twes-in** — it announces its parameters and proceeds; `--ask` restores the upstream approval gate. Progress is reported after every cycle: autonomy suppresses `ask-human` pauses, never output.

**Relationship to the project's Phase 3C/6C gates.** Project `CLAUDE.md` § "Certification ladder" mandates a 3-lens reviewer panel with two consecutive clean rounds at **every** 3C and 6C gate, all task sizes — and today that ladder is hand-rolled from memory each time. Running `/converge` with its defaults **IS** that gate, executed mechanically instead of remembered. Reach for the flags when you want more than the mandated tier: a wider lens set, a higher clean-round threshold, or an enumerated custom scope for a large audit or migration.

## Flags

- `--cycles=N` — maximum total cycles before escalating (default: **5** — the ladder's cap)
- `--converge=K` — consecutive fully-clean cycles required to declare convergence (default: **2** — the ladder's *two consecutive fully-clean rounds*; any finding resets the counter)
- `--scope=ladder|3C|6C|custom` — which lens set to use (default: **`ladder`**). The `3C`/`6C` names describe the angle *content* (expanding-context / adversarial / blast-radius) and are kept for continuity; `ladder` is the project-mandated panel — running it here IS the 3C/6C gate, performed rather than remembered.
  - `ladder` (**default — the project's ratified ladder**): the 3-lens reviewer PANEL, each lens adversarial and **evidence-based** (the reviewer reads the actual diff/tests/specs itself, never the author's narrative). twes-in's three lenses are named, and each spawns as a fresh-context read-only subagent under that name:
    1. **`domain-correctness-reviewer`** — correctness + regression, aimed at what twes-in can silently get *wrong*: money and tax arithmetic (rounding point, rounding mode, minor-unit vs float, multi-currency and the rate stored on the document), invoice/quote/credit state machines and their transition guards, payment application (partial, over-payment, refund, gateway-webhook replay), and DB migration safety including the down path.
    2. **`tenancy-security-reviewer`** — security + multi-tenant isolation + payment/PII safety: every query, aggregate, export, file fetch and background job carrying its tenant filter; authn/authz on API and client-portal routes; gateway secrets, card tokens and PII never reaching logs, exceptions or fixtures; webhook signature verification and idempotency. A plausible cross-tenant read is P0, not a smell.
    3. **`completeness-reviewer`** — completeness + blast radius + **API-contract coverage across the three stacks**: a change to the Symfony API's response shape, field type, enum value, error code or pagination contract is incomplete until the Angular admin and the Flutter client are accounted for (updated, or the change shown to be additive). Docs, fixtures and tests count as part of the radius.
    This is the tier project `CLAUDE.md` mandates at every 3C/6C gate, all task sizes. All three exist as real agent definitions in `.claude/agents/` — spawn them by name via the Agent tool rather than re-describing their charter in a prompt, so the panel's attack surfaces stay in one place.
  - `3C`: pre-implementation-style angles (expanding-context, adversarial, blast-radius)
  - `6C`: pre-completion-style angles (expanding-context on result, failure modes, callers/docs)
  - `custom`: angles provided via `--angles`
- `--angles='A;B;C'` — semicolon-separated angle descriptions when `--scope=custom`; for custom scope, at least one angle **must** be prefixed with `enumerate:` (e.g. `enumerate:list all image dirs`). See Angle Requirements below.
- `--certify=reviewer|self` — how a cycle's findings get judged (default: **`reviewer`**)
  - `reviewer` (**default**): each lens is run by a **fresh-context read-only reviewer subagent** that reads the artefacts itself. `advisor()` does not exist in this environment, so this IS the top of the ladder's availability chain here. Convergence still requires `--converge=K` (2) consecutive fully-clean rounds — independence removes the self-grading blind spot, it does not remove the project's two-round requirement.
  - `self`: self-graded CLEAN/RESET/STUCK comparison against the previous cycle. Last resort — a restricted subagent context with no ability to spawn reviewers. **Using it obliges you to state in the output that certification was self-graded and why** (project CLAUDE.md's disclosure rule).
- `--ask` — opt IN to the Step 0 approval gate (autonomous is the twes-in default; this restores the upstream stop-and-confirm behaviour)
- `--auto-cap=N` — hard safety ceiling for autonomous mode (default: **30**, max: **30**); overrides `--cycles` when autonomous and N > auto-cap; prevents runaway token burn

---

## Angle Requirements

These rules apply to every angle in every cycle, regardless of scope.

### Evidence gate (all scopes)

Every angle result **must** include at least one of:
- A command and its actual output (grep, find, ls, read, wc — something that ran and produced text)
- An explicit enumerated list of items checked with a total count
- A file path + line number citation pointing to the specific location of the finding

**Pure prose reasoning fails the evidence gate.** "I believe X is covered" or "X looks correct" without a supporting command or citation is not a valid angle result. If an angle produces only prose, it must be re-run with concrete evidence before the cycle result is recorded.

### Enumeration angle (custom scope — mandatory)

When `--scope=custom`, at least one angle must be designated `enumerate:`. This angle:

1. **Runs an explicit enumeration command** (`ls`, `find`, `grep` on an index file, or equivalent) to list every member of the set being audited
2. **States the total count** — "N members found: [list]"
3. **Cross-checks coverage** — after all other angles complete, compares members visited this cycle against the total enumerated. Any member not visited by any angle is a scope gap.
4. **Scope gaps are findings** — an unvisited member triggers a RESET with the finding "scope gap: <member> not covered"

The enumeration angle cannot be satisfied by memory or assumption. It must show the command that produced the member list.

**Example** — for an audit that must cover every payment-gateway adapter (the whole coverage surface of a payments change):
```
enumerate: run `git ls-files | grep -i gateway` to get the full adapter list (N files found —
           on a greenfield tree ZERO is a valid answer, and saying so IS the evidence), then
           cross-check which of those files were read or grep'd by the other angles this cycle
```

---

## Step 0 — Announce and run (autonomous by DEFAULT — twes-in adaptation)

**twes-in runs this loop autonomously.** Upstream (and phorj) stop here for approval; that is an
interrupt, and the developer's standing directive for this repo is no interrupts. So: parse flags,
take the ladder defaults for anything missing (`--scope=ladder`, `--cycles=5`, `--converge=2`,
`--certify=reviewer`), **print the parameter block, then proceed immediately** — do not wait.

```
[converge] ladder | certify=reviewer | cycles=5 | converge=2 | auto-cap=30
[converge] lenses: 1 domain-correctness-reviewer  2 tenancy-security-reviewer  3 completeness-reviewer
```

`--ask` opts back INTO the approval gate: print the block plus numbered options (recommended first)
as plain text per `/ask-human`, then STOP and wait. Use it when the scope is large enough that the
token cost itself deserves a decision.

**Autonomous mode is therefore the default**: `autonomous = true` unless `--ask` was passed and the
developer chose a non-autonomous option. The ONLY guaranteed stop in autonomous mode remains the cap
escalation in Step 5 — a stuck independent review is not something autonomy may silently override.

With no `--ask`: `autonomous = true`, proceed straight to Step 1.

If user selects "Change parameters": ask two follow-up questions (max cycles, convergence threshold), then re-display the updated config and confirm once more before proceeding.

If user selects "Skip": exit immediately, report "Convergence loop skipped by user."

---

## Step 1 — Initialize state

```
TOTAL_CYCLES  = N                          # from approved config (default 5 — ladder cap)
CONVERGE_REQ  = K                          # from approved config (default 2 — ladder clean rounds)
CERTIFY       = reviewer | self            # from approved config (default reviewer)
AUTO_CAP      = min(auto-cap, 30)          # hard safety ceiling for autonomous mode
autonomous    = true                       # twes-in DEFAULT (--ask can turn it off)
counter       = 0                          # consecutive clean cycles so far (self mode only)
cycle_num     = 0                          # total cycles run
prev_findings = []                         # findings from the immediately preceding cycle
```

---

## Step 2 — Run one cycle

Increment `cycle_num`.

**Autonomous safety cap check**: If `autonomous == true` AND `cycle_num > AUTO_CAP` → go to Step 5 (autonomous safety cap).

Run all angles against the current context. For each angle:
1. Execute the angle (grep, read, enumerate, or reason with evidence)
2. **Apply evidence gate**: confirm the result includes a command + output, enumerated list, or file citation. If not, re-run the angle with concrete evidence before proceeding.
3. List findings as bullet points. A finding is anything unresolved — a risk, gap, side-effect, inconsistency, or scope gap.

**If `--scope=custom` and an `enumerate:` angle is present:**
After all other angles complete, run the cross-check: compare the enumerated member list against members visited in this cycle. Any unvisited member → add as a scope gap finding before recording the cycle result.

**After running all angles, emit a progress line:**

```
[converge] Cycle cycle_num/TOTAL_CYCLES | counter/CONVERGE_REQ clean | <status>
```

Where `<status>` is one of:

- `CLEAN (counter/CONVERGE_REQ)` — no findings at all this cycle
- `RESET (counter → 0) — new: <one-line finding>` — something appeared that was not in prev_findings
- `STUCK — persistent: <one-line finding>` — findings identical to prev_findings, nothing new

*This progress line is always emitted, even in autonomous mode. Autonomous mode suppresses ask-human pauses, not output.*

---

## Step 3 — Evaluate and act

**If `CERTIFY == reviewer`** (default): the reviewer subagents' verdicts ARE the evaluation — do not self-compare. Spawn one read-only reviewer per lens — `domain-correctness-reviewer`, `tenancy-security-reviewer`, `completeness-reviewer` — each given the artefacts (diff, files, tests, spec) and told to **read them itself** and to try to REFUTE the work:
- Every lens returns zero findings → **Case A (CLEAN)**, `counter += 1`. **Do NOT jump straight to converged** — CLAUDE.md § "Certification ladder" requires TWO consecutive fully-clean rounds, so a single clean round is `counter = 1`.
- Any lens raises something not in `prev_findings` → **Case B (RESET)**, `counter = 0`.
- A lens repeats a point after a resolution attempt → **Case C (STUCK)**.
`prev_findings` is still tracked, and is what tells the next round's reviewers what changed.

**If `CERTIFY == self`** (last-resort fallback — `reviewer` is the default): evaluate using the original self-graded comparison, and say in the output that certification was self-graded and why:

**Case A — CLEAN:**
- `counter += 1`
- `prev_findings = []`
- If `counter == CONVERGE_REQ` → go to Step 4 (converged)
- Else → go to Step 2 (next cycle)

**Case B — RESET (new finding appeared):**
- `counter = 0`
- `prev_findings = current_findings`
- Incorporate the new finding into context/plan

- **If `autonomous == true`**: emit one line and continue without pausing:
  ```
  [converge] ↺ RESET cycle_num — autonomous: <finding summary>. Incorporating and continuing.
  ```
  Go to Step 2.

- **If `autonomous == false`**: **print as plain text and STOP until answered**:
  ```
  Question: "New finding detected in cycle cycle_num. Counter reset to 0.
             Finding: <description>
             Continue the loop or escalate now?"
  Options:
    1. "Continue — incorporate and retry (Recommended)"
    2. "Continue autonomously — run rest of loop silently (no more ask-human calls)"
    3. "Escalate — surface to user and stop"
    4. "None of these / challenge the premise — e.g. the finding is not real, the lens is
        mis-scoped, or the scope should be narrowed. Say so and I will re-run differently."
  ```
  Option 4 is REQUIRED, not optional garnish: `ask-human` § "The five required parts" and
  `CLAUDE.md` § "Questions are plain text" both mandate a visible escape on every option set, and a
  template that omits it is the thing sessions will copy.
  - If "Continue": go to Step 2
  - If "Continue autonomously": set `autonomous = true`, go to Step 2
  - If "Escalate": go to Step 5 (cap escalation)

**Case C — STUCK (same findings, nothing new):**
- `counter` unchanged (neither increments nor resets)
- `prev_findings` unchanged
- Attempt deeper resolution of the persistent finding
- Emit: `[converge] STUCK on cycle cycle_num — attempting deeper resolution`
- Go to Step 2
- *(No ask-human call for STUCK — deeper resolution is attempted automatically in both modes)*

**Case D — Cycle cap reached (`cycle_num == TOTAL_CYCLES` and `counter < CONVERGE_REQ`):**
- Go to Step 5 (cap escalation)

---

## Step 4 — Converged

Emit:
```
[converge] ✓ CONVERGED — cycle_num cycles total, counter/CONVERGE_REQ consecutive clean cycles.
```

Report a one-line summary of what was verified across all clean cycles. Exit.

---

## Step 5 — Cap escalation (could not converge)

**Determine cap type:**
- If reached via autonomous safety cap (`cycle_num > AUTO_CAP`): emit `[converge] ✗ AUTONOMOUS SAFETY CAP — {AUTO_CAP} cycles reached.`
- Otherwise: emit `[converge] ✗ CAP REACHED — cycle_num/TOTAL_CYCLES cycles, counter/CONVERGE_REQ clean.`

In both cases:
- List all remaining findings accumulated so far
- Exit autonomous mode: `autonomous = false`

**Print as plain text and STOP until answered** — this is the one guaranteed question in autonomous mode, and per project CLAUDE.md the 5-round cap NEVER silently proceeds:
```
Question: "Could not converge in cycle_num cycles (counter/CONVERGE_REQ clean).
           <If autonomous safety cap: 'Autonomous safety cap of AUTO_CAP cycles reached.'>
           Remaining findings:
             • <finding 1>
             • <finding 2>
           How do you want to proceed?"
Options:
  1. "Rerun — N more cycles (Recommended)"          → restart Step 1 with same K, new N
  2. "Rerun autonomously — N more cycles"           → restart Step 1 with autonomous = true
  3. "Decompose — split task and converge each part"
  4. "Escalate manually — I will review and decide"
  5. "None of these / challenge the premise"        → e.g. accept the remaining findings as
     documented risk, drop the clean-round requirement for this scope, or stop the loop entirely
     because the artefact is not worth further rounds. State which, and I will record it."
```

Wait for direction. This is the only guaranteed ask-human call in autonomous mode.

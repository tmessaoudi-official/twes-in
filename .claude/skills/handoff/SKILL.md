---
name: handoff
spotlight: true
description: Use at the end of a session to save current state so the next session can continue cleanly without losing context about what was done, what is pending, and any non-obvious gotchas.
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
  3. REPORTS GO TO `var/claude/…` in the repo — gitignored via `/var`, survives
     compaction inside the session, never committed. NOT `~/.claude/projects/…`: that is wiped when
     the container is reclaimed, so a report written there is lost. Never `git add` a report regardless
     — being ignored is what keeps them out of history, not what makes staging them harmless.
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

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP — do not execute any other steps.
>
> ```
> /handoff — Use at the end of a session to save current state so the next session can continue cleanly without losing context about what was done, what is pending, and any non-obvious gotchas.
>
> No flags — invoked without arguments.
> ```

---

Save session state for clean continuation next session.

Write a handoff note so the next session can continue cleanly. Use your knowledge of the current session — you were here. Write in first person ("I").

**Path:** `var/claude/handoff/latest.md`, in the repo — resolve it as
`"${CLAUDE_PROJECT_DIR:-$PWD}"/var/claude/handoff/latest.md`. Create the directory if absent.

Upstream wrote to `~/.claude/projects/<slug>/memory/sessions/handoff.md`. **Do not.** That path is
wiped when the container is reclaimed, so a handoff written there is lost precisely when it is
needed. `var/claude/` is gitignored — it survives compaction *inside* a session and dies with the
container, which is the correct lifetime for session state.

Also append a timestamped copy at `var/claude/handoff/handoff-$(date +%Y-%m-%d-%H%M%S).md`, matching
what the PreCompact hook (`scripts/claude-bootstrap/hooks/precompact-handoff.sh`) already writes, so
manual and automatic handoffs land in one place and read the same way.

**A handoff is never committed.** If something genuinely needs to survive the container, it belongs
in `CLAUDE.md` § Gotchas or in `docs/plans/<topic>.plan.md` — both are real changes, proposed in
plain text, not smuggled in as a note.

Format:

```
# Handoff

## State
{What's done, what's not. Files modified, decisions made, branch state. 2-4 lines max.}

## Next
{What to pick up. Priority order. 1-3 items.}

## Context
{Non-obvious gotchas, blockers, env state from this session. Skip section entirely if nothing.}

## Memory Updates
{Any user/feedback/project memories worth creating or updating based on this session.
 Format: "- [type] description" (types: user, feedback, project, reference).
 Skip section entirely if nothing new to persist.}
```

Rules:
- Under 25 lines total
- Specific: file paths, branch names, command names, variable names — and when work spanned more than
  one stack, say which (API / Angular / Flutter), because the next session cannot infer it from a diff
  stat alone
- Forward-looking — next session doesn't care about the journey, only the current state
- "Memory Updates" is advisory — the next session will see it and decide whether to act
- If nothing meaningful to hand off, write: "No active work."

After writing the file, append `<!-- manual -->` on its own line at the very end. This marker tells the stop hook that a human explicitly saved state — it will skip overwriting with an auto-generated handoff.

Say "Saved." when done — nothing else.

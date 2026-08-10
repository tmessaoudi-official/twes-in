---
name: cross-check
description: Deep standalone validation of a spec or doc — hunts contradictions, undefined terms, unstated assumptions, missing sections and ambiguities, then certifies the analysis with fresh-context reviewer subagents. Use it on a doc before building from it, or with --drift to detect doc-vs-reality drift.
user-invocable: true
args: "<spec-file> [--drift] [--dry-run]"
disallowed-tools: AskUserQuestion
---

<!-- ═══════════════════════════════════════════════════════════════════════════════════
  twes-in CONTAINER ADAPTATION (2026-08-06). Imported from the developer's machine bundle
  `claude-setup-global-20260722` by way of the already-container-adapted `stack` port (where `--drift`
  was invented) and the `rent-watch` port (which is where this file's shape comes from). twes-in was
  the THIRD bundle integration (2026-07-29) and predates both, which is why this skill was missing
  here while phorj, stack and rent-watch all had it.

  WHY THIS SKILL MATTERS MORE HERE THAN ANYWHERE ELSE. twes-in's single most recurring defect, filed
  in eight consecutive certification rounds, is doc-vs-reality drift: prose asserting a control that
  nothing implements, a correction that does not reach the sentence it corrects, and `[Verified: …]`
  figures that silently stopped being true. § Gotchas records that shape more than any other. `--drift`
  is the mechanised form of the check those rounds were doing by hand.

  These deltas OVERRIDE the body below wherever they conflict:

  1. QUESTIONS ARE PLAIN TEXT. `AskUserQuestion` TIMES OUT in this container, so a gate that "asks"
     cannot fire. Every "ask" below means: print the question, a minimal concrete example, numbered
     options and the recommendation FIRST with its reason, plus a visible "none of these / challenge
     the premise" escape — then STOP and wait. Protocol: `.claude/skills/ask-human/SKILL.md`. Every
     reply ends with a `❓ QUESTION` / `⏹ NO QUESTION` marker as its literal last line.
  2. NO `advisor()` HERE. Independent certification = fresh-context read-only reviewer subagents — the
     three twes-in lenses in `.claude/agents/`: `domain-correctness-reviewer`,
     `tenancy-security-reviewer`, `completeness-reviewer`. Spawn them BY NAME rather than
     re-describing their charter inline. Self-grading is the last resort and MUST be DISCLOSED as
     self-graded in the output.
  3. REPORTS GO TO `var/claude/reports/…` in the repo — gitignored by the blanket `/var` rule,
     survives compaction inside the session, never committed. NOT `~/.claude/projects/…`, which dies
     when the container is reclaimed, and NOT `<spec-file>.validation.md`, which would be tracked.
  4. THE JIRA MODE IS DELETED (inherited from the `stack` port). There is no Jira and no Jira MCP
     server here, so the mode could never run — a documented mode that cannot execute is worse than an
     absent one.
  5. THE LICENSING INVARIANTS AND § GOTCHAS ARE RULINGS, NOT DRAFTS. `CLAUDE.md` § "Licensing
     invariants" and § Gotchas are this project's decision register: an entry there is SETTLED. A
     constraint being inconvenient is not a contradiction, and "this would be easier as a fork of
     Invoice Ninja" is not a finding — it is the ruling being disagreed with (see `/forge`'s
     Chesterton gate). Legitimate findings are the doc contradicting ITSELF, a term used before it is
     defined, an unstated assumption a builder would have to guess at, or a requirement that cannot be
     satisfied as written.
  6. A DOC MAY LEGITIMATELY DESCRIBE THE TARGET. `CLAUDE.md` says outright that anything describing
     what does not yet exist is the *target*, and `docs/plans/build-waves.plan.md` is authoritative
     for where the build actually is. So a claim about an unbuilt tier is only STALE where the doc
     asserts it EXISTS — check the tense before filing.
  7. PROJECT RULES WIN on any conflict: `/home/user/twes-in/CLAUDE.md`.
═══════════════════════════════════════════════════════════════════════════════════ -->

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then immediately STOP — do not execute any other steps. (`--help` takes precedence over all other flags.)
>
> ```
> /cross-check — Deep standalone validation of a spec or doc: contradictions, undefined terms,
>                unstated assumptions, missing sections, ambiguities. Certified by fresh-context
>                reviewer subagents.
>
> Usage: /cross-check <spec-file> [--drift] [--dry-run]
> ```
>
> Then output the complete flag table from the **"Flags"** section below. Then STOP.

---

# /cross-check — Doc validation

Parse `$ARGUMENTS`:

## Flags

| Flag | Behavior |
|------|----------|
| `<spec-file>` | Path to the doc to validate (required) |
| `--drift` | Also verify every mechanically checkable claim against the actual repo state (Mode B) |
| `--dry-run` | Print findings to conversation only; no output file written |

If `<spec-file>` is not provided: report the error and stop.

Natural targets in this repo, roughly in descending drift risk: `CLAUDE.md` (by far the largest and
the one whose § Architecture and § "Quality gate" tables make the most checkable claims),
`docs/plans/build-waves.plan.md`, the other `docs/plans/*.plan.md`, `infra/README.md`,
`scripts/claude-bootstrap/README.md`, `LICENSING.md`, `THIRD-PARTY-NOTICES.md`, `README.md`,
`VISION.md`, the tier READMEs (`admin/`, `mobile/`), and `api/composer.json`'s
`scripts-descriptions`, which is prose that ships inside a machine-readable file.

---

## Mode A — internal consistency (default)

### Step 1 — Read the doc fully

Read `<spec-file>` completely before forming any judgement. Do not skim: a contradiction between
§ Architecture and § Gotchas 900 lines apart is invisible to a partial read, and that is precisely the
class this skill exists for. `CLAUDE.md` has shipped exactly that defect repeatedly — a correction
landing hundreds of lines below the false sentence it was meant to replace.

### Step 2 — Independent check

Investigate the three angles yourself, then certify with **fresh-context read-only reviewer subagents**
that read the doc themselves. Loop: investigate → certify → repeat until a round raises nothing new;
cap at 5 rounds, then ask in plain text — never silently proceed.

- **Angle 1** (expanding-context): implicit requirements not stated? assumed context a reader might
  not share?
- **Angle 2** (adversarial): what internal contradictions exist? what claim in one section is
  contradicted in another?
- **Angle 3** (blast-radius): what is missing? what should be specified but isn't? which edge cases
  are unaddressed?

Give the reviewers the doc and the analysis so far. If any raises something new, resolve it and re-run.

### Step 3 — Categorise findings

- **CONTRADICTION** — a claim in section A directly contradicts a claim in section B
- **UNKNOWN** — a term or concept used without definition or reference
- **ASSUMPTION** — an implicit prerequisite not stated
- **MISSING** — a section that should exist but doesn't (error handling, rollback, security…)
- **AMBIGUOUS** — a statement that can be read more than one way
- **STALE** — true once, contradicted by the current tree (only with `--drift`)

---

## Mode B — `--drift`: doc vs reality

**This is the mode this repo needs.** twes-in's docs make an unusual number of mechanically checkable
claims, and a stale one is worse than a missing one because it is trusted and re-cited. For every such
claim, verify it and record the command you ran as the evidence.

| Claim shape | How to verify | Why it drifts here |
|---|---|---|
| **A COUNT of anything** — gates, skills, agents, tests, roles, packages, refusal routes | Run the derivation the doc prescribes, and if it prescribes none, that is itself a finding | The highest-yield check by a wide margin. `CLAUDE.md` has shipped a wrong count at least six times, and twice the CORRECTION was wrong too. Prefer `ls`/`grep -c` over any number written in prose |
| "a gate enforces X" | `grep -n` for the code, not the prose. A gate's own header claiming a rule is not the rule | THE signature defect. A control asserted in prose and enforced nowhere is recorded in § Gotchas at least five times |
| A `[Verified: …]` citation | **Re-run the exact command in it.** | A `[Verified]` figure that silently stopped being true is treated here as a first-class defect, and several have shipped |
| "the meta-suite reports N passed" | `bash scripts/gates/test-gates.sh \| tail -1` | Read the FINAL LINE, not the shrinkage-ratchet line above it — the ratchet prints its own pre-increment value, and three commit messages quoted that as the total |
| "N tests, N assertions" | `cd api && php tools/bin/phpunit-13.phar \| tail -3` | Moves with every commit; strip ANSI before grepping, since `phpunit.xml` forces colour |
| "`composer gate` is green" | Run it. `COMPOSER_ALLOW_SUPERUSER=1`, and `gate:schema` needs `TWES_SCHEMA_DSN` carrying `user=`/`password=` against a MIGRATED database plus `TWES_SCHEMA_USER` | PostgreSQL does not persist in this container — a wholesale integration failure means the server is down (`pg_ctlcluster 16 main stop && pg_ctlcluster 18 main start`), not a regression. And a gate that needs Docker behaves differently with the daemon down |
| "every gate is wired into `composer gate`" | Parse `api/composer.json`'s `scripts` object — never grep the file, since a gate's name also appears in `scripts-descriptions`, which is prose that runs nothing | Caught a real unwired gate once |
| "N locked packages, 0 without source" | A script over `api/composer.lock` | The figure in § "Quality gate" reproduced for weeks and then silently stopped |
| "N PostgreSQL roles" | `grep -c '^        CREATE ROLE' scripts/dev/provision-test-database.sh` — **anchored**, or an unanchored count includes comment lines | Written as three, nine, fourteen and twelve in successive rounds; a prescribed derivation that returns the wrong answer is worse than a prose count |
| "N tenant-owned tables are policed" | `php scripts/gates/schema-tenancy.php` with its env vars | Needs a real migrated schema; it FAILS rather than skipping when it cannot look, which is deliberate |
| "both Dockerfiles" / any enumeration of files | `git ls-files \| grep -c 'Dockerfile$'` | There are three. An enumeration written beside the thing it enumerates is the first thing to drift |
| A tool is claimed installed or uninstallable | `command -v` / run it. `php`, `python3`, `git`, `docker` and `psql` are present; `deptrac` is genuinely absent | § Gotchas records a twenty-round "cannot install" claim whose BOTH diagnoses were wrong. Treat every impossibility claim in this repo as untried until you spend ten minutes on it |
| "worker mode is blocked by <gate>" | `grep -n` the named gate for the actual routes | The enforcing gate changed three times while five documents kept naming the old one |
| A path or file layout claim | `ls` / `git ls-files` the path | Check the TENSE first — `CLAUDE.md` explicitly says anything describing what does not exist yet is the target |
| "N skills / N agents / N hooks" | `ls .claude/skills/`, `ls .claude/agents/`, `ls scripts/claude-bootstrap/hooks/` | And `scripts/gates/lib/` holds LIBRARIES, not gates — an inventory that counts them as gates is a finding |
| A rule set's size claimed in prose | `<gate> --dump-rules` | Every architecture gate answers it; a rule set counted in prose diverges from the one that exists |

Report each as **STALE** with: the claim, the command, its actual output, and the corrected value.
Do **not** silently fix the doc — report first. Docs are this project's memory, and a correction the
developer has not seen is indistinguishable from a new error.

**When a claim is stale, check whether the correction reaches the ORIGINAL sentence.** § Gotchas
2026-07-29 records this three times in one session: a new paragraph saying the opposite, left above or
below the false one, leaves a reader with two contradictory statements and no way to tell which is
current. Filing "corrected below" as closed is itself a finding.

---

## Step 4 — Write output

- `--dry-run`: print to conversation only, then stop.
- Otherwise: write to `var/claude/reports/crosscheck-<basename>-<date>.md` (gitignored).

State in the output whether certification was by reviewer subagents or **self-graded** (and if
self-graded, why no reviewer was available). Also state which claims you could **not** check and why —
a doc validated with unverifiable claims silently marked OK is the failure mode this skill exists to
catch, and it is the same shape as a gate that skips quietly.

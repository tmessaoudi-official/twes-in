---
name: retrospective
spotlight: true
description: Use at the end of a long or complex session for deliberate end-of-session learning extraction and memory capture across hidden dependencies, naming surprises, behavioral quirks, and decision rationale.
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
  3. REPORTS GO TO `var/claude/…` in the repo — intended to be gitignored (`/var`), survives
     compaction inside the session, never committed. NOT `~/.claude/projects/…`: that is wiped when
     the container is reclaimed, so a report written there is lost. Caveat found while adapting: this
     repo has NO `.gitignore` yet (2026-07-29), so `/var` must be added to one before a commit —
     until then these reports are merely untracked, not ignored, and must never be `git add`ed.
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
> /retrospective — End-of-session deliberate learning extraction and memory capture across hidden dependencies, naming surprises, behavioral quirks, and decision rationale.
> ```
>
> Then output the complete flag table from the **"Flags"** section below. Then STOP.

---

# /retrospective — Session Learning Capture

Manual trigger for end-of-session learning extraction. Companion to the automatic Phase 8 learning prompt — use this for a deliberate sweep after long or complex sessions.

**Flags**:

| Flag | Behavior |
|------|----------|
| `--quick` | Skip to the 2 highest-signal lenses only (Failure pattern + Decision rationale); skips all 6-lens scan; output is a compact 2-question pass. |
| `--source=project\|all` | (default: `all`) — `all` enables cross-project index enrichment (Step 2.5): before saving, scan all other projects' MEMORY.md indices to detect duplicates and flag promotion candidates; `project` uses old single-project behavior. |

---

## Step 1: Reconstruct what happened

Review the session by scanning:
```bash
git diff --stat
git log --oneline -10
```

If git shows nothing (e.g. the session only touched gitignored paths), fall back to recency rather
than to a reference file — twes-in has no single manifest to compare against (three stacks, and on
2026-07-29 none of them present):
```bash
find "${CLAUDE_PROJECT_DIR:-$PWD}" -mmin -720 -type f \
  \( -name '*.php' -o -name '*.ts' -o -name '*.dart' -o -name '*.md' -o -name '*.sh' \
     -o -name '*.json' -o -name '*.yaml' -o -name '*.yml' \) \
  -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path '*/build/*' \
  -not -path '*/.git/*' -not -path '*/var/*' 2>/dev/null | head -20
```
Also check the conversation context directly — it is the authoritative record of what was done.

Summarize in one paragraph: what was the core task, what approach was taken, what changed.

---

## Step 2: Extract non-obvious discoveries

**If `--quick` flag was passed**: scan only "Failure pattern" and "Decision rationale" lenses. Skip all others and jump directly to Step 3 with those 2 results.

For each of these lenses, ask the question and answer honestly — skip any where the answer is "nothing surprising":

| Lens | Question |
|------|----------|
| **Hidden dependency** | Did anything turn out to depend on something that wasn't documented? |
| **Naming surprise** | Was anything named differently than expected (script, var, path, command)? |
| **Behavioral quirk** | Did a tool, command, or system behave in a non-obvious way? |
| **Failure pattern** | What broke, and why — and would it be easy to repeat the mistake? |
| **Workaround** | Was something fixed with a workaround that future sessions should know about? |
| **Decision rationale** | Was a design choice made that isn't obvious from the code alone? |

---

## Step 2.5: Cross-project index enrichment (skip if `--source=project` or `--quick`)

**Compute current project slug:**
```bash
CURRENT_SLUG=$(echo "${CLAUDE_PROJECT_DIR:-$PWD}" | sed 's|^/|-|; s|/|-|g')
```

**Index scan** — read the memory indices, text only, no full file reads:
```bash
# In this container there is exactly ONE memory home: the repo's own gitignored var/claude/memory.
# Other projects' memories lived under ~/.claude/projects/<slug>/ upstream and are not reachable here.
ls "${CLAUDE_PROJECT_DIR:-$PWD}"/var/claude/memory/*.md 2>/dev/null
```

**Honest scope note (twes-in adaptation):** with a single memory home, this step degrades from
cross-*project* enrichment to a within-repo duplicate check — it catches an entry you already wrote in
an earlier session of this repo, and nothing more. Say so rather than implying a fleet-wide scan; the
`[SEEN in N other projects]` annotation below can only fire if a genuinely separate memory home exists.

For each proposed entry from Step 2, compare its description + key terms against the index lines already present:

- **No match in any other project** → proceed normally, save as project memory in Step 4
- **Match found in ≥1 other project** → annotate with `[SEEN in N other projects: slug1, slug2]` and mark as **PROMOTION CANDIDATE**

Annotation format for Step 3 preview:
```
[2] type: feedback | file: feedback_<slug>.md
    name: <name>
    description: <one-line description>
    body preview: <first 3 lines>
    ⚡ PROMOTION CANDIDATE — also seen in: <other-slug>, <other-slug> [2 other projects]
```

Be conservative on matching — only flag when there is strong textual overlap in the description. When uncertain, do not annotate (saving as project memory is safe; false promotion flags are noise).

---

## Step 3: Present proposed memory entries — confirm before saving

For each non-trivial discovery from Step 2, draft the memory entry but **do not write it yet**.
Present each proposed entry as a numbered preview:

```
Proposed memory entries (N total):

[1] type: project | file: project_<slug>.md
    name: <name>
    description: <one-line description>
    body preview: <first 3 lines of content>

[2] type: feedback | file: feedback_<slug>.md
    ...
```

**Write them, then report (twes-in adaptation — no interrupts).** Upstream stops here for
per-entry approval. That gate existed because the upstream target was the developer's real
`~/.claude/projects/<slug>/memory/`. Here the target is **`var/claude/memory/`, which is
gitignored and dies with the container** — so an unwanted entry costs nothing and needs no
confirmation. Write all entries, then state plainly what was written:

```
[retrospective] wrote N entries → var/claude/memory/
  1. project  — <name>
  2. feedback — <name>
```

Two things this does NOT license:

- **Never write into the repo proper.** No `CLAUDE.md` edit, no `docs/` file, no committed artifact
  from this skill. A discovery worth keeping permanently is a **CLAUDE.md § Gotchas** entry, and that
  is a real change — propose it in plain text with the exact diff and let the developer rule on it.
- **Never invent a discovery to fill the report.** If nothing non-obvious came up, write nothing and
  say `No discoveries worth persisting.` A padded retrospective is worse than an empty one.

---

## Step 4: Save confirmed entries

For each confirmed entry (all, or the numbered subset the user approved):

- If it's about **the project** (a quirk, a hidden dep, a workaround): save to `project_*.md` memory
- If it's about **how to collaborate** (a preference revealed, an approach that worked well): save to `feedback_*.md` memory
- If it's about **the user** (a skill gap revealed, a domain they know deeply): save to `user_*.md` memory

Write each discovery as a standalone memory entry — not a bullet in an existing file unless it naturally extends one. Keep entries focused: one fact, one "Why:", one "How to apply:".

Update `MEMORY.md` index for any new files.

---

## Step 5: Report

Print a summary:
```
Retrospective complete
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Session scope : [1-sentence summary]
Discoveries saved : N
  - [file] → [one-line description]
  ...
Nothing to save : [list lenses that returned no findings]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

If Step 2 found nothing for any lens: report "No non-obvious discoveries — session was routine." and stop.

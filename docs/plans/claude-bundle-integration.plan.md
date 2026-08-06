# Claude bundle integration Plan

Bring the developer's Claude Code setup into this repo in the form that works in a **cloud
container**, modelled on the two already-container-adapted ports in
[`pdfturbo`](https://github.com/tmessaoudi-official/pdfturbo) and
[`phorj`](https://github.com/tmessaoudi-official/phorj).

pdfturbo was the primary source: it is the newer port, it had already removed a credential-leak
vector present in the earlier one, and it carries `disallowed-tools: AskUserQuestion` on every skill —
a mechanical guarantee phorj lacks.

---

## Decisions Log

- [2026-07-29 08:45] AGREED: the integration targets the **repo**, not `~/.claude`. Cloud sessions load the repo's `CLAUDE.md`, `.claude/{settings.json,skills,agents}` and load **none** of `~/.claude/`, which is a fresh empty directory every session. Anything the framework needs there has to travel in the repo and be reinstalled at session start.
- [2026-07-29 08:50] AGREED: source of truth is **pdfturbo's port**, with phorj consulted for the pieces pdfturbo lacks (its `hooks/test-precompact-handoff.sh`, and its numbered-invariants CLAUDE.md shape). Not the raw machine bundle — the ports already carry the container adaptations.
- [2026-07-29 08:55] AGREED: `install.sh` is ported **one-directional only** (`cp -u` three docs into `~/.claude`, create `var/claude/`). phorj's copy also ran `cp -R /root/.claude /root/.claude.json` into its working tree at every SessionStart, with a commented-out `git push --force-with-lease` beneath it. `~/.claude.json` holds the OAuth account, `userID` and `machineID`, and the working tree is one `git add -A` from history. Not reproduced. `/claude-bundle/` is gitignored as a belt-and-braces guard.
- [2026-07-29 09:00] AGREED: `AskUserQuestion` is **forbidden project-wide** — it times out in this container, so a question asked that way can hang the turn and vanish. Every question is plain text: context + minimal example + numbered options + recommended first with its reason + a visible escape, then STOP. Every skill declares `disallowed-tools: AskUserQuestion`. Honest limit: that binds per-turn and clears on the next user message.
- [2026-07-29 09:05] AGREED: **12 skills**, pdfturbo's set unchanged in roster — `ask-human`, `converge`, `sweep`, `expanding-context`, `sleuth`, `inspect`, `gaps`, `aggregate-findings`, `pre-commit`, `handoff`, `retrospective`, `forge`. phorj's `cross-check` is not imported (it validates formal specs against Jira; neither exists here).
- [2026-07-29 09:10] AGREED: **three reviewer agents**, one per certification lens, renamed and re-chartered for this domain rather than copied: `domain-correctness-reviewer` (money arithmetic, tax, state machines, migrations), `tenancy-security-reviewer` (multi-tenant isolation, auth, payment/PII safety), `completeness-reviewer` (evidence actually delivered, and the change reaching all three client tiers). pdfturbo's names (`export-fidelity-reviewer`, `safety-promises-reviewer`) describe a PDF editor's promises and would have been theatre here.
- [2026-07-29 09:15] AGREED: certification tier is **MAXIMAL by default** — three lenses, two consecutive fully-clean rounds, any finding resets the counter, cap 5 then ask. Rationale specific to this domain: a wrong number is a wrong legal document and a cross-tenant read is a reportable data breach, and neither is caught by a green test suite. The one carve-out is mechanical: no application source in `git diff --name-only` → STANDARD.
- [2026-07-29 09:20] AGREED: **zero `deny` rules**, inheriting pdfturbo's developer ruling. In a cloud session a denied command is an unrecoverable dead end — there is no terminal in which to run it by hand. The control is discipline, recorded in `CLAUDE.md` § "Git autonomy".
- [2026-07-29 09:25] AGREED: **plans live in the repo** at `docs/plans/<topic>.plan.md`, each with its own `## Decisions Log`. The container is reclaimed and only committed state survives, so an out-of-repo plan file is never the record of truth.
- [2026-07-29 11:20] FOUND — **deviation from pdfturbo's documented behaviour**: `.claude/settings.json` is **writable by Claude in this container.** pdfturbo documents it as classifier-blocked and ships a `settings.json.pending` + `apply-pending-settings.sh` relay for the developer to apply locally. Here the direct `Write` **succeeded**. [Verified: the `Write` call returned success and the file is tracked in `git status`.] So no pending file was created and none exists. AGREED: keep `apply-pending-settings.sh` anyway — the restriction is environment-dependent, may reappear without notice, and the script is inert when there is no pending file (prints "Nothing to apply", exits 0). Recorded in `CLAUDE.md` § Gotchas so a future session does not conclude the block is gone permanently.
- [2026-07-29 11:25] FOUND+FIXED: the bulk project-name rename (`pdfturbo` → `twes-in`, `PDFTURBO_` → `TWES_`) left **four passages that were still factually about a PDF editor** while now claiming to describe this project. Rule 6's visual-evidence amendment referenced `npm run test:browser`, jsdom-vs-canvas, and `exportPipeline.ts`'s raster path; the Large-task plan gate referenced a `SCHEMA_VERSION` ceiling. A mechanical rename produces text that *reads* adapted without *being* adapted — the more dangerous failure, because nothing looks wrong. Rewritten: the visual-evidence amendment now names this project's three real rendered surfaces (Angular admin, Flutter client, generated PDF documents) with the specific way each fails invisibly, and the plan gate now names the real one-way doors (a documented invariant, a licensing boundary, the API contract the Flutter client depends on).
- [2026-07-29 11:30] FOUND+FIXED: Rule 10 in the global framework carried a **direct contradiction with this container's harness** — it pinned the commit author to `Takieddine Messaoudi <takieddine.messaoudi.official@gmail.com>` and forbade a `Co-Authored-By` trailer outright, while the harness instructs the opposite. Both are imperative; both cannot be honoured silently. AGREED: recorded as an **OPEN RULING** in Rule 10 and in `CLAUDE.md` § "Git autonomy", following the harness default until the developer rules. The sibling repos' rule earned its place because their history has zero such trailers; this repo has no history to be consistent with yet, so there is nothing to preserve — which is what makes deferring it safe rather than lazy.
- [2026-07-29 11:35] AGREED: the distinctive addition for this project is `CLAUDE.md` § **"Licensing invariants"** — numbered rules making the clean-room boundary a hard gate rather than an intention (eight at the time of this entry; ten after the 12:40 licence and branding rulings — `grep -cE '^[0-9]+\. \*\*' CLAUDE.md's invariants section is the count, never a number written here). The sibling repos have no analogue because neither is derived from someone else's source-available product. This is the one section that, if violated, changes what this repository legally *is*, so it lives in `CLAUDE.md` (where Claude must read it) and not in `docs/`.
- [2026-07-29 11:50] AGREED (developer, explicit): **`master` is the only branch.** Work is committed and pushed directly to it; no feature, topic or `claude/*` branches. This **overrides the harness prompt** that designated `claude/hello-3g42jj` for this session — the harness names a branch per session, and that instruction is superseded by this standing ruling. Recorded in `CLAUDE.md` § "Git autonomy" and § "Git & CI", and in global Rule 10, so a future session does not re-follow the harness default. The three bundle commits were moved to `master` unchanged (same SHAs, `master` created at the existing tip — no history rewritten) and the stray branch deleted. Matches the sibling repos, which are both single-branch (`master`) single-developer.
- [2026-07-29 12:45] RULED (developer, closing the open item): **commit identity is `Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>` for author and committer, with NO `Co-Authored-By` and NO `Claude-Session` trailer.** This **overrides the container harness**, which mandates both. The OPEN RULING recorded on 2026-07-29 11:30 is therefore closed in favour of the sibling repos' convention, and Rule 10 plus its "No Co-Authored-By" sub-clause are reinstated in `scripts/claude-bootstrap/CLAUDE-global.md` rather than deferred. Also recorded: the container's SessionStart sets the git identity to `Claude <noreply@anthropic.com>`, so a session must set `git config user.name`/`user.email` **before its first commit** — the default is wrong, and that is the mechanism by which the first six commits got the wrong author.
- [2026-07-29 12:45] DONE: the six bootstrap commits were **retroactively re-authored** at the developer's explicit request, via `git filter-branch` (author + committer rewritten, both Claude trailers stripped from the messages) followed by one authorised `--force-with-lease` push. [Verified: `git diff --stat refs/original/refs/heads/master master` was **empty** — tree content byte-identical before and after, only metadata changed; `git log --format='%(trailers:only)' | grep -i claude` returns nothing.] Note the `--force-with-lease` first failed with `stale info` because `filter-branch` also rewrites `refs/remotes/origin/*`, making the lease compare against a rewritten tracking ref; fixed by `git fetch` then passing the real remote SHA as an explicit lease. **The force-push authorisation was for this fix only and does not generalise** — `CLAUDE.md` § "Git autonomy" still forbids it otherwise.

## Formal Plan

| Phase | Deliverable | Status |
|---|---|---|
| 1 | `scripts/claude-bootstrap/` — `install.sh` (one-directional), `README.md`, `apply-pending-settings.sh`, `CLAUDE-global.md`, `THINKING.md`, `BLAST-RADIUS.md`, `hooks/{log-helpers,precompact-handoff,test-precompact-handoff}.sh` | built |
| 2 | `.claude/skills/` × 12, container-adapted + re-grounded on this domain, each with `disallowed-tools: AskUserQuestion` | built |
| 3 | `.claude/agents/` × 3 — one per certification lens, re-chartered for a billing domain | built |
| 4 | `CLAUDE.md` — Routing / Questions are plain text / **Licensing invariants** / API contract / Certification ladder / Git autonomy / Plans / Quality gate / Gotchas | built |
| 5 | `.claude/settings.json` — `defaultMode: auto`, `deny: []`, the two bootstrap hooks (written directly; no relay needed here) | built |
| 6 | `.gitignore` — Claude block, reference-clone guards, three-stack ignores | built |
| 7 | `docs/plans/reimplementation-strategy.plan.md` — the licensing findings and build-vs-fork analysis | built |
| 8 | Developer rules the open items — commit trailers, and Questions 1–4 plus licence in the strategy plan | **all ruled 2026-07-29** |
| 9 | The six bootstrap commits re-authored to the developer, both Claude trailers stripped | built |

### Rejected, with reasons

- **Hooks from the machine bundle** — the upstream set is one of: an interrupt (the ask-human gates,
  the question guard), a hard deadlock (`advisor-completion-guard` needs a tool that does not exist
  here), terminal-only output nobody can see in a web session (statusline, banner, context-bar,
  git-status, subagent-status), or writes to a filesystem that evaporates (`edit-log`,
  `session-remember`). Two hooks are registered: SessionStart install, PreCompact handoff.
- **Repo-local `PostToolUse` lint hooks** — pdfturbo wires `oxlint-on-write.sh` and
  `locale-sync-check.sh`. Both are good patterns (`jq` the payload from stdin, early `exit 0` guard,
  `exit 2` + stderr so Claude fixes it in the same turn), and the equivalents here would be a
  PHP-CS-Fixer/PHPStan hook and a translation-key-parity hook. **Deferred, not rejected**: there is no
  source tree and no toolchain to lint yet, and a hook pointing at an absent binary is worse than no
  hook. Add them with the first application code.
- **`.mcp.json` / MCP servers** — none in either sibling repo, nothing this project needs yet.
- **`cross-check` skill — REJECTED 2026-07-29, ADOPTED 2026-08-06.** The original reason was correct at the
  time: the machine bundle's version validated formal specs against Jira, and neither Jira nor its MCP server
  exists here. What changed is the SKILL, not this repo — the `stack` port deleted the Jira mode and invented
  a `--drift` mode (doc-vs-reality verification), and `rent-watch` carried that forward. That version is worth
  more here than in any sibling: doc-vs-reality drift is twes-in's most-filed defect across eight consecutive
  certification rounds — prose asserting a control nothing implements, corrections that do not reach the
  sentence they correct, `[Verified]` figures that silently stopped being true. Ported to
  `.claude/skills/cross-check/` with a `--drift` table built from this repo's own recurring drift shapes.
  Edited in place rather than annotated below, per CLAUDE.md § Gotchas 2026-07-29.
- **`settings.json.template` — rejected WHOLESALE, not cherry-picked**, and this is the most dangerous omission
  the cross-repo comparison found (2026-08-06: the `rent-watch` port records it and twes-in's list did not). Four
  separate landmines, each tested upstream: its `PreToolUse: rtk hook claude` would block **every** Bash call
  (`rtk` is absent here, and a non-zero `PreToolUse` exit blocks before permission rules are even evaluated); its
  `deny: Bash(git push *)` would **revoke this repo's push authorisation**, contradicting § "Git autonomy"; its
  `"model": "opus"` would override the session model; and its 16 `enabledPlugins` are user-scoped and do not
  transfer. `.claude/settings.json` here is written from scratch instead.
- **The machine bundle's `bin/` (34 scripts) and `mcp/` (48 files)** — neither travels. The `bin/` scripts assume a
  persistent `~/.claude` and the developer's own paths; the `mcp/` set configures servers this session has no
  credentials for. Nothing here needs either, and a wrapper pointing at an absent server fails at the worst moment.
- **A CI workflow** — deliberately not written yet. There is nothing to build or test, and a workflow
  that runs no real gate is a green tick that means nothing. `CLAUDE.md` § "Quality gate" records the
  per-tier commands to wire when each stack lands.
- **phorj's `.editorconfig` / toolchain pinning / git hooks** — all stack-specific (Rust). The
  *patterns* (tracked hooks via `core.hooksPath`, tiered fast/slow gates, a `test-*.sh` companion for
  every script) are worth reproducing per-stack once there is a stack.

### Known limits carried into the result

- **New skills need one session restart.** Claude Code watches an existing `.claude/skills/` live, but
  a newly created top-level skills directory is not watched until the CLI restarts. The `CLAUDE.md`
  sections bind immediately; the slash commands appear next session.
- **`allow` rules are inert in cloud sessions** — they need an accepted workspace-trust dialog a cloud
  session never shows (`Ignoring N permissions.allow entries … this workspace has not been trusted`).
  They work locally. `defaultMode` is what takes effect.
- **`disallowed-tools` binds per-turn**, not per-session — it clears on the next user message, so
  outside a skill the plain-text-question discipline is unenforced.
- **No `deny` rules at all**, by ruling — nothing mechanically prevents a force-push.
- **The quality gate is empty** because there is no code. That is stated in `CLAUDE.md` rather than
  papered over with commands that do not exist.
- **`.claude/settings.json` being writable is an observed property of this container**, not a
  guarantee. If a future session finds `Write` denied, the relay in
  `scripts/claude-bootstrap/apply-pending-settings.sh` is the documented path.
- **RESOLVED 2026-07-29 13:20 — the branch situation is clean; the earlier entry here was wrong.**
  The remote now has **only** `master`, and `HEAD` resolves to it. [Verified: `git ls-remote` →
  exactly two lines, `HEAD` and `refs/heads/master`, both `915cfc8`. No `claude/hello-3g42jj`.]
  Nothing is required of the developer — an earlier version of this entry told them to flip the
  default branch and delete the stray, and that instruction was **obsolete when written**.
  **Why it was wrong, because the mechanism is worth knowing:** `git push origin --delete` did return
  `HTTP 403`, but the branch is nonetheless gone server-side — so the 403 was not proof of failure.
  What kept the branch *looking* alive locally was `refs/remotes/origin/claude/hello-3g42jj`, a stale
  tracking ref that `filter-branch` had itself **rewritten** to `59a324a` — a commit that never
  existed on the remote at all. `git branch -r` and `git remote show origin` both read from that
  stale ref, so both lied. The lesson generalises: after a history rewrite, **never trust
  `git branch -r` or `git remote show`** — only `git ls-remote`, which queries the server. The stale
  ref and the three `refs/original/*` backups have been pruned.
  The same superseded entry also cited `49fbfe5` as the shared tip; that is a **pre-rewrite** SHA and
  is not an ancestor of `master`, so the "missing every commit after it" warning was doubly void.

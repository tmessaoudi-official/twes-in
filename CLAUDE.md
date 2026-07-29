# CLAUDE.md — twes-in

> This file holds the RULES for how Claude delivers code here — quality, carefulness, gates, and the
> licensing boundary that governs this whole project. The product itself (roadmap, specs, decisions)
> lives in `docs/`. Boundary test before adding anything: *does Claude need this to deliver correct
> code?* If not, it belongs in `docs/`, not here.

twes-in is an invoicing / billing platform: a **Symfony REST API**, an **Angular admin web client**,
and a **Flutter** mobile/desktop client, over **PostgreSQL**. It is a **clean-room reimplementation
inspired by Invoice Ninja** — never a fork, never a port. That distinction is legal, not stylistic,
and § "Licensing invariants" below is the most important section in this file.

Status: **greenfield.** As of 2026-07-29 this repo contains the Claude bundle and planning docs only —
no application code yet. Anything below describing the stack is the *target*, not the present. Read
`docs/plans/*.plan.md` for where the build actually is.

## Routing

Work here is handled with the **global reasoning framework** (`~/.claude/CLAUDE.md`) — the 8-phase
workflow, the four-dimension Completion Gate, evidence grades, the anti-bandaid gate. A cloud session
gets a fresh `~/.claude/` every time and never reads the developer's own, so the framework travels in
this repo and is reinstalled at session start by `scripts/claude-bootstrap/install.sh` (a SessionStart
hook). See `scripts/claude-bootstrap/README.md`. On any conflict, **this file wins**.

Repo-native slash skills live in `.claude/skills/` and reviewer agents in `.claude/agents/`; both are
read in place, nothing is installed. `ls .claude/skills/` is the authoritative list — a count written
in prose drifts, so none is written here.

## Questions are plain text — `AskUserQuestion` is FORBIDDEN

`AskUserQuestion` **times out in the cloud container**, so a question asked that way can hang the turn
and be lost — a gate that cannot fire is worse than no gate. Every question to the developer is
ordinary prose: context, a minimal concrete example, numbered options, the **recommended option first
with its reason**, and a visible *"none of these / challenge the premise"* escape — then STOP and wait.
Protocol: `.claude/skills/ask-human/SKILL.md`.

Partial mechanical backing: every skill in `.claude/skills/` declares
`disallowed-tools: AskUserQuestion`, which removes the tool from the pool while that skill is active.
The grant clears on the next user message, so outside a skill the discipline is yours.

**Do not ask about routine work.** The standing directive for this repo is *no interrupts*: announce
the task size and the plan, then build it. Asking is reserved for the cases in
§ "When this protocol is mandatory" of that skill — chiefly a genuinely ambiguous request, a
user-visible product decision, or anything that would weaken an invariant below.

## Licensing invariants — the cardinal rule of this project

These are not guidelines. Breaking one changes what this repository legally *is*.

1. **This is a clean-room reimplementation. Upstream code never enters this tree.** Not copied, not
   pasted, not "translated", not lightly renamed. Invoice Ninja's backend (`invoiceninja/invoiceninja`)
   and web UI (`invoiceninja/ui`) are **Elastic License 2.0**, and 2379 of their 2441 PHP files carry
   an explicit copyright header. [Verified: `grep -rl "elastic.co/licensing/elastic-license" app/ | wc -l`
   → 2379; `find app -name '*.php' | wc -l` → 2441.] Translating PHP/Laravel into PHP/Symfony is a
   **derivative work** under copyright law — a translation is not an independent creation. Porting
   their code would silently make this repo ELv2-encumbered.

2. **Build from the contract and the behaviour, never from the source.** The legitimate inputs are:
   the published OpenAPI specification, the database schema shape, documented placeholder/field names,
   the *standards* an invoicing product must implement (EN 16931, UBL, CII, Factur-X, Peppol BIS), and
   observable behaviour. Functionality, interfaces, data formats and programming techniques are not
   copyrightable — expression is. Work from what the system *does*, and write the *how* yourself.

3. **The Flutter client is the one exception, and it is a deliberate one.** `invoiceninja/admin-portal`
   is under the **Attribution Assurance License** [Verified: read `/tmp/xxx/in-flutter/LICENSE.txt`] —
   permissive, but it requires, on **every launch**, a prominent display (splash screen or banner) of
   the author's name (*Hillel Coren*), professional identification (*Invoice Ninja*) and URL
   (*https://www.invoiceninja.com*), plus retention of the licence text in documentation and source.
   If any Flutter code is reused, **that attribution ships and is never removed** — it is the price of
   the licence, and stripping it is a breach, not a branding decision. Reusing it also means honouring
   its API contract; see § "The API contract is a hard constraint".

4. **Never reproduce, disable, or reimplement a licence-key or branding gate.** ELv2 forbids
   circumventing licence-key functionality, and upstream gates its own branding removal behind a paid
   white-label plan (`Account::FEATURE_WHITE_LABEL` / `FEATURE_REMOVE_CREATED_BY` → `isPaid()`, which
   controls whether the vendor logo appears on generated PDFs and emails). [Verified: read
   `app/Models/Account.php:246-307` and `app/Utils/Helpers.php:32-42`.] A clean-room build has no such
   gate to circumvent, which is precisely why it is the safe path. Do not import the pattern in order
   to defeat it.

5. **Reference clones live outside the repo, read-only.** Study clones belong in `/tmp/xxx/**`, never
   in the working tree. `.gitignore` blocks `/reference/`, `/upstream/`, `/vendor-reference/` as a
   belt-and-braces guard. Never `git add` a file whose provenance is an upstream clone.

6. **Third-party libraries are the legitimate shortcut, and they are framework-agnostic.** The genuinely
   hard domain knowledge — Factur-X/ZUGFeRD, UBL, Peppol, XAdES signing, HTML→PDF — is in independently
   licensed PHP packages that Symfony can consume directly (`horstoeko/zugferd`, `josemmo/facturae-php`,
   `setasign/fpdi`, snappdf/Gotenberg, the payment-gateway SDKs). Using the same libraries upstream uses
   is not copying upstream. **Check and record each library's own licence in
   `THIRD-PARTY-NOTICES.md` before adding it** — that file is a deliverable, not an afterthought.

7. **`invoiceninja/dockerfiles` is GPL-2.0** [Verified: read `/tmp/xxx/in-docker/LICENSE`] — a different
   and *stronger* copyleft than ELv2. Copying a Dockerfile or Helm chart from it makes that artifact
   GPL-2.0 and obliges source disclosure for the derivative. Deployment topology is an *idea* (php-fpm +
   nginx + db + redis + queue worker + scheduler) and free to reuse; the files are not. Write our own.

8. **When a licensing question is genuinely unclear, STOP and ask in plain text.** Do not resolve a
   licence question by picking the convenient reading. This is a one-way door — Rule 18's
   `[Speculative]` grade is not good enough to build on.

## The API contract is a hard constraint

The Flutter client is intended to be kept. That makes the API contract **load-bearing in a way a
greenfield backend usually is not**: a shipped mobile client updates on app-store timelines, not ours.
Whatever the contract ends up being, it is pinned by tests and versioned deliberately, and a change to
a field name, an enum value, an envelope shape, an error format or an auth header is a **breaking
change with a migration plan**, never an incidental edit. `completeness-reviewer` treats an API change
that does not reach every client tier as a P0. The concrete constraints (auth scheme, response
envelope, ID format, date formats, pagination, error shape) belong in
`docs/plans/reimplementation-strategy.plan.md`, decided once and recorded there.

## Certification ladder — governs every 3C/6C gate

`advisor()` does not exist in this environment, so independent certification comes from
**fresh-context, read-only, adversarial reviewer subagents** in `.claude/agents/` — that is the TOP
rung here, not a fallback. Three lenses, one agent each:

| Lens | Agent |
|---|---|
| correctness + regression | `domain-correctness-reviewer` |
| security + multi-tenant isolation | `tenancy-security-reviewer` |
| completeness + blast-radius | `completeness-reviewer` |

Each reviewer **reads the actual diff, code and tests itself** — never certify from the author's
narrative — and is chartered to REFUTE, not approve. `/converge` runs the panel mechanically.

**Tier: MAXIMAL by default** — all three lenses, **two consecutive fully-clean rounds**, any finding
resets the counter, cap 5 rounds → then ask in plain text (never silently proceed). Rationale: in a
billing system a wrong number is a wrong legal document and a cross-tenant read is a reportable data
breach. Neither is caught by a passing test suite alone, and neither is confined to one subsystem.

**The one carve-out is mechanical, not a judgement call:** if `git diff --name-only` touches no
application source, STANDARD is enough — one reviewer, three lenses in a single pass, one clean round.
Docs, `CLAUDE.md` and planning-file edits qualify. Anything touching application code does not.

Availability chain: reviewer subagents → (if subagents are unavailable) three distinct-lens self-passes
**with mandatory disclosure that certification was self-graded**. Never silently skip a gate. The
quality gate below is always the floor, never the certification.

## Git autonomy — overrides global Rule 10

Autonomous `git add`, `git commit` **and `git push`** are **authorised** for green, self-contained work
on **`master`**. Asking permission for them violates the no-interrupts directive. Limits:

- **`master` is the ONLY branch** (developer ruling, 2026-07-29): commit and push directly to it, and
  do not create a feature, topic or `claude/*` branch even when a harness prompt names one as the
  session's "designated branch" — that instruction is superseded here. If a session starts on some
  other branch, move the work to `master` and delete the stray.
- **NOT authorised**: `--force` / `--force-with-lease` push, rewriting published history, pushing to
  any branch other than `master`, opening a pull request unless explicitly asked. There is no `deny`
  list to stop you — the discipline is the control.
- Commit only when the quality gate is green and the change is self-contained; never a broken build.
- Commit style: `feat:` / `fix:` / `refactor:` / `docs:` / `chore:` / `test:`, imperative subject.
- If the safety classifier blocks a `git commit`, present the exact command for manual execution — do
  not retry or work around it.

**Two open items the developer must rule on** — recorded in
`docs/plans/claude-bundle-integration.plan.md`, not silently decided here:
- **Commit trailers.** The sibling repos (phorj, pdfturbo) forbid `Co-Authored-By` outright and pin the
  author to `Takieddine Messaoudi <takieddine.messaoudi.official@gmail.com>`. This container's harness
  instructs the opposite (append `Co-Authored-By` + a `Claude-Session` trailer). This repo has no
  history to match, so there is nothing to be consistent with yet. The harness default is being
  followed until ruled otherwise.
- **`deny` rules.** pdfturbo ships `deny: []` deliberately — in a cloud session a denied command is an
  unrecoverable dead end, because there is no terminal in which to run it by hand. That ruling is
  inherited here for the same reason.

## Plans live in the repo

Every plan or spec produced here is persisted at **`docs/plans/<topic>.plan.md`**, each carrying its
own `## Decisions Log` (`- [YYYY-MM-DD HH:MM] AGREED: <one-sentence decision>`), appended in the same
change as the ruling. The container is reclaimed and only committed state survives, so an out-of-repo
plan file is never the record of truth. There is no plan-location sentinel to ask about.

A ruling that outlives its plan file graduates into a **§ Gotchas** entry below — which is what makes
that section this project's real decision register. Transient review output (reports, handoffs, memory)
goes to `var/claude/**`, which is gitignored.

Current plans:
- `docs/plans/reimplementation-strategy.plan.md` — the build-vs-fork analysis, licensing findings,
  scope decisions, and the target architecture. **Read this before writing any application code.**
- `docs/plans/claude-bundle-integration.plan.md` — how this bundle got here and what was rejected.

## Quality gate

There is no application code yet, so there is no gate to run — and **that is a fact to state, not a
row to skip**. When a stack lands, its gate is defined here in the same change, and "green" means all
of them, per tier:

| Tier | Green means (define on landing) |
|---|---|
| Symfony API | `composer validate`, `vendor/bin/phpstan` (max level), `vendor/bin/php-cs-fixer --dry-run`, `vendor/bin/phpunit`, `bin/console doctrine:schema:validate`, `bin/console lint:container` |
| Angular admin | `npm run lint`, `npm run test`, `ng build --configuration production` |
| Flutter client | `flutter analyze`, `flutter test` |
| Infra | `docker compose config`, `bash -n` on every shell script |

Derive the real command names from `composer.json` / `package.json` / `pubspec.yaml` rather than
trusting the table above — a command written in prose drifts from the one that exists.

Rule 7 (TDD) applies from the first commit of application code, and infra changes satisfy it with
`docker compose config` / `bash -n` / `--dry-run` output. Money arithmetic, tax rules and state
transitions get their failing test written **first**, every time — that is where this product's bugs
are expensive.

## Gotchas

*(This section is the decision register. It is empty because the project is greenfield — entries land
as rulings are made and as the codebase teaches us things. Do not delete this heading.)*

- **2026-07-29 — `.claude/settings.json` is writable in THIS container.** pdfturbo's bundle documents
  the file as classifier-blocked for Claude, and ships a `settings.json.pending` +
  `apply-pending-settings.sh` relay to work around it. Here the direct `Write` **succeeded**
  [Verified: `Write` to `.claude/settings.json` returned success, file present in `git status`]. The
  relay script is kept anyway — the block is environment-dependent and may reappear, and the script is
  inert when there is no pending file. Do not delete it, and do not assume the block is gone
  permanently.
- **2026-07-29 — money is never a float.** Recorded here on day zero because it is unfixable later.
  Upstream stores amounts as floats on models and reaches for `bcmath` only in places; its own tax
  helper mixes `BcMath::mul` with native float arithmetic in adjacent methods, and skips rounding
  entirely on one path when Peppol is enabled. [Verified: read `app/Helpers/Invoice/Taxer.php`.] Use
  integer minor units or a decimal money type (`brick/money`) and Postgres `NUMERIC` from the first
  migration. A `float` on a money column is a P0 for `domain-correctness-reviewer`.
- **2026-07-29 — tenancy scoping must be default-on.** Upstream's `company_id` scope is an *opt-in*
  Eloquent scope every query has to remember, with no global enforcement. [Verified: read
  `app/Models/BaseModel.php:115`; a search for a global-scope registration found none.] Use a Doctrine
  filter that is enabled by default so forgetting is impossible, not a helper that must be called.

## Git & CI

- Single developer, **single branch `master`**, commits direct, no PR review gate. See
  § "Git autonomy" — this means the local quality gate is the only safety net before history, so
  never commit red.
- CI is not yet configured. When it is, it goes in `.github/workflows/`, mirrors the quality-gate table
  above tier by tier, and every job carries a comment explaining **why it exists and what breaks
  without it** — that comment style is house convention, not decoration.
- Local git hooks, when added, are tracked in-repo and wired with `core.hooksPath`, and the hook
  scripts are the SSOT of their own steps — never restate a hook's contents in prose here.

## Claude config in this repo

- `.claude/settings.json` — `defaultMode: auto`, pre-approved read-only/build commands, `deny: []`,
  and the two bootstrap hooks (SessionStart install, PreCompact handoff). Note: `allow` entries are
  **inert in cloud sessions** (they need a workspace-trust dialog a cloud session never shows), so
  `defaultMode` is what actually takes effect. Don't grow the allow list expecting cloud effect.
- `.claude/skills/**` — repo-native slash skills, read in place.
- `.claude/agents/**` — the three certification-lens reviewers.
- `.claude/settings.local.json` is gitignored — machine-local overrides go there.
- `scripts/claude-bootstrap/**` — the global framework, carried in-repo and installed into the
  ephemeral `~/.claude/` at session start. See its `README.md`.

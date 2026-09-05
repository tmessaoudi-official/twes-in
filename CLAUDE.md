# CLAUDE.md — twes-in

> **`docs/SPEC.md` is the single source of truth for WHAT twes-in is, what has been ruled, what
> exists today and what is still owed. READ IT FIRST.** It carries the product definition, the
> architecture and domain rulings, the API contract, the verified current state, the environment
> recipes, the gate and certification protocol, the ONE open register, and the roadmap.
>
> **This file carries the RULES for HOW work is delivered here** — quality, carefulness, gates, git
> discipline, and the licensing boundary that governs the whole project. Boundary test before adding
> anything here: *does Claude need this to deliver correct code, independent of what the product
> is?* If not, it belongs in `docs/SPEC.md`.
>
> On any conflict between the global framework (`~/.claude/CLAUDE.md`) and this file, **this file
> wins**. On any conflict between this file and `docs/SPEC.md` about the PRODUCT, the spec wins.

twes-in is an invoicing / billing platform: a **Symfony REST API**, an **Angular admin web client**,
and a **Flutter** client for all six targets, over **PostgreSQL**. It is a **clean-room
reimplementation inspired by Invoice Ninja — never a fork, never a port**, and it is
**AGPL-3.0-or-later plus a commercial licence**. Everything else about the product is in the spec.

---

## Routing

Work here is handled with the **global reasoning framework** (`~/.claude/CLAUDE.md`) — the 8-phase
workflow, the four-dimension Completion Gate, evidence grades, the anti-bandaid gate. That framework
is the developer's own persistent install; this repo never writes it.

The repo carries exactly TWO skills, both repo-specific by name and content (global-is-reference
ruling, 2026-08-18 — a repo may not duplicate anything that exists in `~/.claude/`):
`/twes-ask-human` (the question protocol with this repo's extra rules) and `/twes-lenses` (the
mandatory review dimensions + sleuth lens K). Every other skill — `/sweep`, `/sleuth`, `/inspect`,
`/gaps`, `/forge`, `/cross-check`, `/converge`, `/pre-commit`, `/aggregate-findings`, `/handoff`,
`/retrospective`, `/expanding-context` — comes from the developer's global install. **Before running
ANY of those global review skills here, load `/twes-lenses` first.** Reviewer agents stay in
`.claude/agents/` (read in place, nothing is installed).

## Questions — `AskUserQuestion`, sparingly

Questions to the developer use the **`AskUserQuestion` tool**, per the global framework: options with
the recommended one FIRST (labelled, with its reason) and a visible *"none of these / challenge the
premise"* escape. Protocol details: `.claude/skills/twes-ask-human/SKILL.md`.

> The container-era plain-text protocol and the `❓`/`⏹` end-of-reply markers are **RETIRED**
> (2026-08-18). They existed because `AskUserQuestion` timed out in the dead cloud container; on this
> machine it works and `askUserQuestionTimeout` is `"never"` globally.

**Do not ask about routine work.** The standing directive for this repo is *no interrupts*: announce
the task size and the plan, then build it. Asking is reserved for a genuinely ambiguous request, a
user-visible product decision, or anything that would weaken an invariant below.

---

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

3. **There is no exception. The Flutter client is written from scratch too** (developer ruling,
   2026-07-29: *"I want my own version that is 100% mine, same for all the rest"*). This closed the
   one loophole the earlier plan carried. `invoiceninja/admin-portal` is under the **Attribution
   Assurance License** [Verified: read `/tmp/xxx/in-flutter/LICENSE.txt`], which would have required —
   on **every launch**, forever — a prominent display of *Hillel Coren* / *Invoice Ninja* /
   *invoiceninja.com*. Writing our own client means that duty never attaches. **Do not reuse
   admin-portal code "just for the transport layer"**: the obligation follows the code, not the
   quantity of it, and reintroducing it would also drag back the API contract we are now free of.

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

8. **twes-in itself is AGPL-3.0-or-later plus a commercial licence**, copyright wholly
   Takieddine MESSAOUDI (developer ruling, 2026-07-29: open source, but sellable by the author). Three
   consequences that bind day-to-day work — the first two are obligations 1–2 of `LICENSING.md`
   § "Three obligations", the third is its § "Notices"; that file's third obligation (AGPL binds *us*
   too once we distribute) is not restated here because it constrains distribution rather than
   day-to-day code: **(a)** every dependency must be
   **permissive**, and recorded in `THIRD-PARTY-NOTICES.md` in the same change that adds it. The permitted
   set is **exactly nine identifiers for anything we DISTRIBUTE** (developer ruling, 2026-07-29, after a
   certification round found the gate enforcing more than this invariant allowed): **MIT, Apache-2.0,
   BSD-2-Clause, BSD-3-Clause, ISC, 0BSD, MIT-0, CC0-1.0, BlueOak-1.0.0.** Every one is non-copyleft AND
   imposes no obligation that could survive into a commercial sublicence, which is the test that matters
   rather than "is it open source". The four beyond the original five were each argued on merit, not adopted
   for convenience: **0BSD** is Zero-clause BSD — permissive with *no* attribution requirement at all, and
   the only one of the four that a **runtime** dependency actually needs (`tslib`, TypeScript's own helper
   library, unavoidable in an Angular build); **MIT-0** is MIT with the notice requirement removed;
   **CC0-1.0** is a public-domain dedication, so there is no licence to comply with; **BlueOak-1.0.0** is
   OSI-approved and written as a modern MIT/BSD replacement that also grants patent rights.

   **A DEV-ONLY dependency may additionally carry `CC-BY-4.0` or `CC-BY-3.0`**, and that exception is narrow
   on purpose. Those are Creative Commons *content* licences: no copyleft, so they do not threaten the
   commercial branch, but they do impose **attribution** — and this project has already refused a dependency
   for exactly that reason (invariant 3: `admin-portal`'s Attribution Assurance License). They are permitted
   only where no obligation can attach, i.e. build-time reference *data* absent from the shipped artifact
   (`caniuse-lite`, `spdx-exceptions`). If either ever appears as a **runtime** dependency the nine-identifier
   list applies and `scripts/gates/dependency-licences.php` fails — which is why the gate keeps **FOUR
   separate lists** rather than one wider one, and asserts a **MAXIMUM** on each. (It said "three" until
   2026-08-21 and "two" for a commit before that, each time while the paragraph beside it added one — the same
   count-written-next-to-the-thing-it-counts defect, now on its third instance in this one sentence.) (This sentence said "two
   lists" for a commit while the paragraph four lines below it added the third; round 8 found it. A count
   written next to the thing it counts is worth re-reading whenever that thing changes.)

   **A DEV-ONLY TOOLING dependency may additionally carry `MPL-2.0`** (developer ruling, 2026-08-21) — a fourth
   narrow category, and the reason it is fourth rather than folded into the CC-BY pair is that those cover build-time
   *data* while this covers build-time *code*. The Mozilla Public License is copyleft, but **file-level**: it reaches
   the MPL-licensed files themselves and not the code that merely uses them, which is what separates it from
   GPL/AGPL/LGPL and is why it can be tolerated at all. It stays **refused for anything DISTRIBUTED**, because a
   copyleft grant of any strength cannot be relicensed to a customer buying an escape from source disclosure — the
   test that matters here, not "is it open source". The dependency that forced the question is **`lightningcss` and
   its eleven per-platform binaries**, the CSS transformer inside **Angular's own build chain** rather than anything
   this project chose; it runs while compiling `admin/` and no byte of it reaches a browser. It entered
   `admin/package-lock.json` at `f18c296` and `gate:licences` had been red ever since [Verified 2026-08-21: identical
   failure on a pristine `HEAD` worktree carrying no local changes]. An MPL-2.0 **runtime** dependency is still a P0,
   and so is an MPL-2.0 package anywhere in `api/` or `mobile/`, which ship what they depend on.

   **A vendored FONT ASSET may carry `OFL-1.1`** (developer ruling, 2026-07-30) — now the third of four narrow
   categories (it was "a third" when there were three; the sentence is renumbered in place rather than left to
   contradict the paragraph above it), for
   the same reason the CC-BY pair is quarantined rather than added above. The SIL Open Font License is not
   copyleft for our purposes and imposes nothing on our code; its one real obligation is the **Reserved Font
   Name** clause, which binds only somebody who *modifies* a font and redistributes it under its original name.
   Vendoring unmodified triggers none of it, and the licence text travels beside the file. An OFL-1.1 *code*
   package is still refused — the categories do not leak into one another. Needed because Flutter Web's engine
   fetches Noto fallback fonts from `fonts.gstatic.com` for any script the bundled fonts do not cover, and `ar`
   is a first-class locale: pinning the origin stopped the transfer to Google, but rendering Arabic at all
   requires the font to be ours.

   Adding an identifier to any of the three lists is a **licensing decision under invariant 10**, not a build
   fix: amend
   this paragraph, `LICENSING.md`, the reviewer charter and the gate together, or the four disagree — which is
   precisely what round 6 found. **"AGPL-compatible" is the wrong test**:
   a GPL/AGPL/LGPL dependency satisfies our AGPL branch and *kills the commercial one*, because a third
   party's copyleft code cannot be relicensed to a customer buying an escape from source disclosure. A
   needed copyleft-only library is an `ask-human` decision, never a silent one;
   **(b)** copyright must stay wholly owned or the commercial licence dies,
   so no outside contribution may be merged without a CLA (none exists yet — one is required before the
   first external patch); **(c)** new source files carry
   `SPDX-License-Identifier: AGPL-3.0-or-later`, machine-readable, never a pasted paragraph.

9. **All branding is ours and configurable from day one** (developer ruling, 2026-07-29). No upstream
   name, logo, asset, colour or copy anywhere. Deployment starts as Docker-only with no public domain,
   so hostname, product name, logo and e-mail identity are **configuration, never hardcoded** — a
   later public deployment must be a config change, not a code change. There is no white-label "gate"
   to build, because there is no vendor branding to gate: contrast upstream, which put its own logo on
   generated PDFs behind a paid plan.

10. **When a licensing question is genuinely unclear, STOP and ask via `AskUserQuestion`.** Do not resolve a
    licence question by picking the convenient reading. This is a one-way door — Rule 18's
    `[Speculative]` grade is not good enough to build on.


---

## Git autonomy — overrides global Rule 10

Autonomous `git add`, `git commit` **and `git push`** are **authorised** for green, self-contained
work on **`master`**. Asking permission for them violates the no-interrupts directive. Limits:

- **`master` is the ONLY branch** (developer ruling, 2026-07-29): commit and push directly to it, and
  do not create a feature, topic or `claude/*` branch even when a harness prompt names one as the
  session's "designated branch" — that instruction is superseded here.
- **Push with plain `git push`. Never `-u` / `--set-upstream`.** Upstream is set once and `master` is
  the only branch, so `-u` re-asserts a `master`→`master` tracking relationship on every push —
  redundant, and it renders in the developer's UI as though a branch relationship were proposed.
- **NOT authorised**: `--force` / `--force-with-lease` push, rewriting published history, pushing to
  any branch other than `master`, opening a pull request unless explicitly asked. There is no `deny`
  list to stop you — the discipline is the control. **There is nothing to open a pull request FROM:**
  one branch means a PR here would be `master` into `master`.
- Commit only when the quality gate is green and the change is self-contained; never a broken build.
- Commit style: `feat:` / `fix:` / `refactor:` / `docs:` / `chore:` / `test:`, imperative subject.
- If the safety classifier blocks a `git commit`, present the exact command for manual execution.

**Commit identity — RULED, 2026-07-29, no longer open.** Every commit is authored *and* committed as:

```
Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
```

- **Never a `Co-Authored-By` trailer, and never a `Claude-Session` trailer.** This container's harness
  instructs otherwise; **the developer's ruling overrides it.**
- A harness may set a different default identity, so the repo identity must be **verified**, not
  assumed: `git config user.name` / `user.email` before the first commit of any session — and check
  the CASE too, because a `Takieddine Messaoudi` that differs from `Takieddine MESSAOUDI` is exactly
  the kind of near-match a glance passes over.

**`deny` and `ask` rules stay empty, permanently** (developer ruling, 2026-08-06): *"there should be
no permissions denies! in this env claude code in the web! because if you are denied to do something
i can't run it myself!"* A denied command is an unrecoverable dead end. The ruling stands.

---

## Certification — per-gate, and the ONE panel at a wave boundary

> The full protocol, the reviewer charters, the `UNCERTIFIED-BY-EXECUTION` surface table and the
> sabotage rules are in **`docs/SPEC.md` § 7**. What follows is the short form Claude must not get
> wrong.

- **Per-task 3C/6C gates: ONE `advisor()` call, never a panel** (developer ruling, 2026-08-19). This
  supersedes the older "MAXIMAL by default". `advisor()` is available on this machine.
- **The refuting between gates comes from executable evidence**: failing test FIRST, confirmed
  failing **for the stated reason**, then implement, then a **sabotage/mutation check** proving the
  suite would NOTICE the guarantee breaking. **Sabotage the INVARIANT, not the diff.** Where a mutant
  already exists in the suite or in `test-gates.sh`, re-running it IS the check.
- **A wave boundary gets MAXIMAL**: all three lenses, **two consecutive fully-clean rounds**, any
  finding resets the counter, cap 5 → then ask via `AskUserQuestion`. **Freeze first** — a round on a
  moving tree cannot count.
- **Spawn reviewer subagents UNNAMED.** Passing `name:` routes their return through `SendMessage`,
  which is denied — they run, go idle, and their reports vanish, indistinguishable from
  unavailability. Probe with one unnamed agent before concluding subagents are unavailable.
- **A subagent returning a large result to a near-saturated parent freezes the session.** Instruct
  every reviewer to write its findings to `var/claude/` and return ONE LINE.
- **Say plainly, in the completion report, which parts were certified by execution and which were
  not**, naming each unverified dimension. A clean verdict hiding an unexercised dimension is a false
  certification.
- If `advisor()` is unavailable, fall back to a **disclosed self-graded three-lens pass** — never an
  autonomously-spawned reviewer subagent, since not spawning them is the point of the ruling.

**Never commit red**, and never chain a verification step onto `git commit` through a pipe or `&&`.

---

## Plans and the record

**There is no `docs/plans/` any more.** `docs/SPEC.md` § 10 is the ONE live Decisions Log; a ruling
made anywhere lands there in the same change, dated, one sentence. The five historical plan files are
archived VERBATIM under `docs/archive/plans/`, with every dated ruling dispositioned in
`docs/archive/plans/RECONCILIATION.md` — nothing in the archive is current unless `SPEC.md` carries
it. `docs/archive/specs/` is the home for a spec retired later; create it when first needed.

Transient review output (reports, findings, handoffs) goes to `var/claude/**`, which is gitignored.

Root documents that are not the spec and are not governed by that rule, because each is a
conventional root-level artefact readers and tooling expect to find there: `README.md` (the entry
point), `VISION.md` (direction that is explicitly **not** a commitment — nothing in it may be cited
as a reason to defer a decision), `LICENSING.md` (**read before adding any dependency**) and
`THIRD-PARTY-NOTICES.md` (every dependency and its licence, recorded in the change that adds it).

---

## The floor: never commit red

`composer gate` is the API tier's gate and the client tiers have their own. **The gate table, what
each gate enforces, which need a database or a pub cache, which FAIL rather than skip, and the
verbatim invocations are in `docs/SPEC.md` §§ 6–7.** Derive the chain from `api/composer.json` rather
than from any prose, and derive the gate inventory from `ls scripts/gates/` minus `lib/`.

Rule 7 (TDD) applies from the first commit of application code; infra changes satisfy it with
`docker compose config` / `bash -n` / `--dry-run` output. **Money arithmetic, tax rules and state
transitions get their failing test written first, every time** — that is where this product's bugs
are expensive.

---

## Craft rules — the process gotchas

*These are lessons this project paid for. Product and domain gotchas moved to `docs/SPEC.md`; what
remains here is about HOW to work, and it applies whatever the task. Never write a count in this
section — `grep -c '^- \*\*20' CLAUDE.md` is the only trustworthy tally.*

- **2026-07-29 — `.claude/settings.json` is writable in THIS container.** pdfturbo's bundle documents
  the file as classifier-blocked for Claude, and ships a `settings.json.pending` +
  `apply-pending-settings.sh` relay to work around it. Here the direct `Write` **succeeded**
  [Verified: `Write` to `.claude/settings.json` returned success, file present in `git status`]. The
  relay script was kept for a while against the block reappearing, then **deleted with the whole
  container-era bootstrap in `b1d2069` (2026-08-18)** — see § "Claude config" below, which records that
  directory as gone. This entry ordered it preserved for eighteen days after that commit removed it
  (row 24 of `docs/SPEC.md` § 11, corrected in place 2026-09-05 under § 0 rule 2). What survives of the
  lesson: the block is environment-dependent, so do not assume it is gone permanently — if it returns,
  the route is a hand-off script the developer runs, not a relay file in the tree.
- **2026-07-29 — NEVER commit while a certification panel is reading. Freeze means freeze.** Round 5 of
  the bundle certification was told the artefact was frozen at `653e211`; a commit landed mid-round and
  it rewrote **exactly** the files the reviewer had been assigned. The reviewer caught it, but only after
  reading a `README.md` row that does not exist at the frozen commit — **it was one step from filing a
  fabricated finding against a file the frozen tree did not contain.** A round run on a moving tree is
  not evidence and cannot count toward the two-clean requirement, regardless of what it reports. The
  discipline: commit, then freeze, then spawn the panel, then **do not touch the tree** until it
  reports. If something urgent must change, kill the round and restart it — a discarded round is cheap;
  a fabricated finding is not.
- **2026-07-29 — a guard on one write path is not a guard.** The `<!-- manual -->` handoff protection was
  implemented on the PreCompact hook's default write and *not* on its opt-in LLM write, so with
  `TWES_HANDOFF_LLM=1` a hand-written note was still destroyed — and `log_obs` recorded "latest.md is
  manual — kept" two lines before clobbering it, so the observability trail actively lied. Five
  assertions passed throughout, because none of them set the env var and the LLM assertion only grepped
  the source instead of executing the path. **Two lessons, both general: decide a condition ONCE into a
  variable that every path reads, and a test that greps source instead of running code proves nothing.**
  The fix was verified by running the new test against the pre-fix hook — 1 failed — then against the
  fixed one — 35 passed.
- **2026-07-29 — a correction appended below a false statement is not a correction. THREE times in one
  session.** Round 1 flagged it, I fixed those instances, and then did it again twice more: `a18aa9d`'s
  commit message claimed it "closes a real contradiction in the spec" while the contradicting sentence sat
  84 lines below the new ruling, unannotated; the owed table still listed the rate-quantisation item as
  "needs a developer ruling" with the superseded numbers, while the ruling was at line 48 of the same file;
  and two documents asserted a control was "recorded in `infra/README.md`" when `git log` showed that file
  had never been touched. **The rule, since evidently stating it once was not enough: when a decision is
  superseded, edit the ORIGINAL sentence in place. Adding a new section that says the opposite leaves a
  reader with two contradictory statements and no way to tell which is current** — and every one of these
  was caught by a reviewer, not by me, because I was reading my new text rather than grepping for the old.
  A claim in a commit message that a file was amended is worth exactly nothing without a `grep` first.
- **2026-07-29 — a meta-gate needs its OWN adversary. `test-gates.sh` reported 33/33 for a gate that
  detected nothing.** It was written to protect the gates and was itself the weakest link: its
  `assert_gate` accepted *any* non-zero exit as "caught the violation" and never asserted output, so a gate
  replaced with `throw new RuntimeException` passed all 33 cases while printing `ok — catches time`. Its
  fixture also never copied `api/composer.json`, so the licensing check it was supposed to cover ran zero
  times. Two general lessons: **assert on the MESSAGE, not just a non-zero exit code** — a crash and a
  detection are indistinguishable otherwise — and **a test fixture that omits an input makes every
  assertion about that input vacuous while the suite stays green.** Both are the same shape as the
  handoff-hook failure already recorded above, which is why they belong here rather than in a plan.
- **2026-07-29, CORRECTED 2026-08-01 — `composer install` DOES run. The blocker was never network
  egress, and I misdiagnosed it for twenty certification rounds.** The original entry said *"GitHub
  egress is restricted, so `composer install` cannot run"* and prescribed *"the environment's network
  policy"* as the only remedy. General egress is OPEN, `git clone` over HTTPS works, and only GitHub's
  API and archive hosts are authorization-scoped — so the fix was Composer configuration, not a new
  environment. **The working recipe is in `docs/SPEC.md` § 6**, along with the `--no-dev` history and
  the lock property that replaced a `[Verified]` package count which silently stopped being true.
  **The lesson is not about Composer.** I read a 403, reached for the most structural explanation
  available ("the environment forbids it"), wrote it down as `[Verified]` on the strength of four
  `curl` calls that were all consistent with it, and then cited my own note for twenty rounds instead
  of trying the operation. Four `curl`s against API hosts cannot distinguish *"the network is closed"*
  from *"these three hosts are authorization-scoped"* — and the one command that would have told me
  apart, `git clone`, takes five seconds. This file records *"say 'not covered, here is what it would
  take', never 'cannot be covered'"* four times over; this is that failure applied to an environment
  rather than a test, and it was the most expensive one of the project so far, because it shaped the
  build order of twelve waves.
- **2026-07-29 — NEVER record a coverage gap as an impossibility. I did it twice in one round and both were
  refuted in minutes.** Closing round 5 I disclosed two residues and, in each case, explained them with a
  claim that they *could not* be tested: `bind()`'s read-back throw "would need PostgreSQL to lie about its
  own `set_config` return value", and the single-half `session_user`/`current_user` mutants were "equivalent
  mutants". Round 6 killed both. PDO substitutes the statement class natively, so a nine-line `PDOStatement`
  subclass drives the read-back branch on a real connection; and "equivalent mutant" means **no input
  distinguishes it**, which was true of the `current_user` halves and false of the load-bearing `session_user`
  halves — the distinguishing input needed only the DSN `options='-c role=…'` trick already used elsewhere in
  the same file and roles the fixture already provisioned. **An admitted gap gets re-tried; a documented
  impossibility gets read once and never re-tested**, so the false claim is the more expensive artifact. Say
  "not covered, here is what it would take" and never "cannot be covered" unless the obstacle is a law of
  the system rather than the limit of the afternoon.
- **2026-07-29 — a control asserted in prose and enforced nowhere is not a control, and round 4 found the
  most expensive instance of it.** `assertConnectionCannotBypassPolicies()` read two role attributes and was
  named as though it answered the whole question. Bypass #0 — *the runtime role must not own the policed
  tables, and must not hold `TRUNCATE`* — was written in that same class's docblock, in `infra/README.md`,
  and in this file, and **checked by nothing**. A role that is neither superuser nor `BYPASSRLS` was
  therefore certified while being two statements from every tenant's data. The compounding failure is the
  one to remember: **the test suite demonstrated the bypass while certifying against it**, running
  `DISABLE ROW LEVEL SECURITY` and `TRUNCATE` on the very connection it had just certified, with a fixture
  comment admitting *"the application connects as the owner"* against the requirement that it must not. When
  a document states a security precondition, grep for the code that enforces it before believing it — and
  provision the test environment to match production's topology, because a fixture that cannot express the
  dangerous shape cannot detect it.
- **2026-07-29 — a fix is not delivered until a MUTANT proves it load-bearing.** Rounds 1–3 each closed real
  findings and each closed them without the test that pins the fix, so round 4 found three of round 3's four
  tenancy fixes revertible with the suite still green — and the round-3 record contained a bracketed
  `[Verified against live roles]` that was a one-off manual run no fresh clone could reproduce. A passing
  suite after a fix proves nothing; the fix must be **reverted** and the suite must go **red**. Round 4's
  eighteen closures are each backed by a re-run mutant recorded in `build-waves.plan.md`. Corollary already
  learned twice and now three times: **assert on the message, not just a non-zero exit or a green run** — and
  strip ANSI before grepping a test runner's output, because `phpunit.xml` forces colour and `^OK \(` never
  matches, which fooled a reviewer's harness in round 3 and my own in round 4.
- **2026-07-30 — a permission that nothing consults permits everything. `PERMISSIVE_FOR_FONT_ASSETS` was
  declared, documented and dumped for one commit with no code path reading it.** All 260 meta-cases stayed
  green, and they had to: a licence category that permits nothing is indistinguishable from one that permits
  anything when no check consults it. This is the third instance of the same shape in this file (the handoff
  hook's one guarded write path, `test-gates.sh` reporting 33/33 for a gate that detected nothing) and the
  generalisation is now explicit: **a constant, list or rule added to a gate must be read by that gate's
  failure path in the same change, and a case must prove it fires.** `--dump-rules` exposing it is not
  enforcement — introspection describes a rule, it does not apply one.
- **2026-07-30 — "beside the file" is not "shipped", and the obligation attaches to what is DISTRIBUTED.**
  Both font licence texts sat correctly next to their binaries in the repository and were absent from every
  build, because `pubspec`'s `fonts:` bundles the `.ttf` and nothing next to it while Flutter's generated
  `assets/NOTICES` aggregates LICENSE files from *packages*, not app assets. [Verified: a release web build
  carried both families with `grep -c "SIL Open Font License" build/web/assets/NOTICES` → 0.] Apache-2.0
  § 4(a) and OFL-1.1 § 2 both bind the redistributed artifact, and a web bundle served to a browser is
  redistribution. It was already true of Roboto, so it was a **full-set miss** rather than a new-font one.
  The general rule for any vendored asset: check the built artifact, not the working tree — this is the same
  distinction as CLAUDE.md's own *captured is not delivered*, applied to compliance instead of evidence.
- **2026-07-30 — an exemption inside a cross-check is where the drift hides.** `THIRD-PARTY-NOTICES.md` was
  exempt from the closed-list half of the five-document licence cross-check, on the reasonable-sounding
  grounds that it "discusses the identifiers in prose". It did state the rule — *"Permitted: MIT ·
  Apache-2.0 · BSD-2-Clause · BSD-3-Clause · ISC. That is the whole list."* — the superseded FIVE, four
  narrower than the gate, under a heading reading `PERMISSIVE DEPENDENCIES ONLY`, in the one file invariant
  8(a) names as where a licence must be recorded. The presence half passed it because the four missing
  identifiers appear further down in package rows. **So a document contradicting the gate in its own rule
  statement was certified as agreeing with it, by the very case written to catch that.** When a check
  exempts one member of the set it covers, the exemption needs the same scrutiny as the check.
- **2026-07-30 — FOUR "impossible" claims refuted in one session. Treat every one in this repo as untried.**
  The tally, because the pattern is the point rather than any single instance: `bind()`'s read-back "would need
  PostgreSQL to lie" — killed by a nine-line `PDOStatement` subclass; the `session_user` halves were "equivalent
  mutants" — killed by a DSN trick already used elsewhere in the same file; the Flutter transitive-licence walk
  "cannot be read from `pubspec.lock`" — true of the lock, and every cached package ships its own licence file,
  so 24 of 24 classified on the first attempt; and R2-12's savepoint divergence was "not reachable today (PDO
  forbids nested transactions)" — which confuses a nested `beginTransaction()` with a `SAVEPOINT` issued as
  ordinary SQL, and reproduced in nine lines with no ORM at all. **Every one was written by someone who had
  reasoned rather than tried, and every one hid a real defect** — the last a silent cross-tenant read. The rule
  is already in this file and is restated because restating it once evidently was not enough: say "not covered,
  and here is what it would take", never "cannot be covered", unless the obstacle is a law of the system rather
  than the limit of the afternoon. When you meet an impossibility claim in this repo, spend ten minutes trying
  it before believing it.
- **2026-07-31 — a test cannot kill a mutant in an expression it never REACHES, and a SELECT-list expression is
  only evaluated for rows the WHERE clause produces.** Round 14 reported the `current_user::regrole` →
  `pg_roles` fix as revertible with 559 tests green. I wrote the missing test — a real mixed-case login role,
  connected as it, calling the check — and **the mutant still survived**. The cast sits in the query's SELECT
  list, and `twes_in_test` normally holds zero public relations, so the query returned no rows, the cast never
  evaluated, and a test written specifically to kill that mutant passed against the broken code. It needed a
  candidate view to exist first. Two general lessons: **when a mutant survives a test written to kill it, the
  test is not weak — it is not arriving**, so find the shortest path from the test to the mutated line before
  strengthening assertions; and a value cast in a projection is conditional code, not a statement. Also worth
  keeping: the reviewer's evidence was `SELECT 'twesApp'::regrole` — a *literal* cast — while the code casts
  `current_user`, a `name` value. I checked whether the two behave the same rather than assuming, and here they
  do; that check is the cheap step that stops a wrong fix.
- **2026-07-31 — a control may not derive its own expected value from the input it is validating.**
  `policyExpressionColumn()` read the column name out of the policy being checked and then compared the policy
  to a canonical expression built from *that same name*, so the comparison always agreed with itself — and a
  policy reading `label = current_setting('twes.tenant_id')` was certified as "the canonical tenant predicate".
  [Verified: `policyExpressionIsCanonical()` returned true for `company_id`, `id`, `tenant_id` and `label`
  alike.] The table is then unscoped by tenant while every other check reports clean, and a cross-tenant INSERT
  follows. Round 7 had already closed the sibling half of this — "one column per TABLE rather than one per
  clause" — which is the tell that the underlying mistake was treating the column as a free variable at all.
  Anchored to `TENANT_COLUMN`, known independently of the input. The generalisation is the rule stated at the
  top of this entry, and it is worth checking against every other pure validator in this codebase.
- **2026-07-31 — a gate that walks the filesystem reads whatever OTHER checkouts happen to be inside the
  repo.** A parallel certification round runs reviewer and fix agents in git worktrees, and the harness places
  them at `.claude/worktrees/<agent>/` — **inside** the working tree. Each carries its own `CLAUDE.md`,
  `LICENSING.md` and every source file, so `grep -r` sees several repositories at once: `test-gates.sh`'s
  licence-surface cross-check failed with an "actual" list that was not wrong about this repository, it was
  reading four. The rule: **enumerate from `git ls-files`, never a recursive walk** — it sees tracked paths of
  the current work tree only, which is the set every one of these checks actually means. `shell-syntax.sh` and
  `no-orphaned-docblocks.php` already do. This also disposes of the `node_modules` special case such walks needed.
- **2026-08-01 — `test | tail && git commit` COMMITS ON RED. A pipeline's exit status is the LAST command's.**
  I pushed a commit whose verification run had reported `Tests: 692, Failures: 113`, because the chain was
  `phpunit … | sed … | tail -2 && git add -A && git commit && git push` and `tail` exits 0 whatever phpunit did.
  The gate did not fail to run and did not lie — I simply never read its verdict, and `&&` did not read it either.
  [Verified: the same 113 failures were `SQLSTATE[08006] … Connection refused`, i.e. the container's PostgreSQL
  had stopped again; re-run after `pg_ctlcluster 18 main start` → `OK (692 tests, 2634 assertions)`, so the commit
  content was innocent and the process was not.] Two rules follow, and the second is the one that generalises:
  **never chain `git commit` onto a piped verification command** — capture the status (`set -o pipefail`, or run
  the gate as its own step and read it) — and treat *any* construct that turns a verdict into text as having
  discarded the verdict. This is the same shape as § "Quality gate"'s skip-to-fail fix and the `grep`-the-source
  test, one level up: there, a control silently did not run; here, a control ran, failed, and was silently not
  consulted. **Also note what saved it**: the integration suite FAILS rather than skipping when the database is
  unreachable, so a dead cluster is loud. Read a wholesale integration failure as "the server is down" first —
  `pg_lsclusters`, then `pg_ctlcluster 18 main start` — and only then as a regression.
- **2026-08-02 — the meta-gate suite was RED at the commit that added a gate, and only a later run noticed.**
  `compose-config.sh` shipped in `5b46f69` with no case in `test-gates.sh`, so that suite's own "a gate on disk has
  no case in this suite" rule was failing at the moment the gate was committed — the infra gate, added to stop a
  broken stack, was itself the untested thing. Two fixes rather than one: the four cases it now has, and a third
  DECLARATION FORM (`# clean-case-inline:`) so a gate whose fixture the shared `$WORK/repo` cannot provide has a
  legitimate spelling instead of falling through the crack. The inline form is verified exactly as the existing
  `clean-case-elsewhere:` redirect is — the named marker must actually be printed by a `printf` in the file, proven
  by a mutant that deletes the case and keeps the declaration. **Deliberately a third SPELLING and not an
  exemption**, because § Gotchas already records that an exemption inside a cross-check is where drift hides.
- **2026-08-02 — every questionable thing found in a Symfony-conventions sweep was a real defect, not a naming
  preference.** The developer asked to replace `.env.example` with the Symfony cascade; the sweep that followed
  turned up the four entries above plus a missing `translator.fallbacks` (a key absent from `messages.ar.xlf` would
  have rendered as the raw key — an Arabic-speaking user shown `document.quantity_too_precise` as their entire error
  message) and no `trusted_proxies` at all (behind the stack's own proxy, `getClientIp()` returns the container
  network's address, so an audit log records the wrong actor). **The generalisation worth keeping is about where to
  look, not about Symfony:** a project-specific spelling of a conventional thing (`TWES_SERVER_NAME` for
  `SERVER_NAME`, `twes-entrypoint` for `docker-entrypoint`, `config/packages/test/framework.yaml` for `when@test:`)
  is a reliable marker that the surrounding wiring was reasoned from first principles rather than taken from the
  ecosystem — and first-principles wiring is exactly where a step gets skipped, because nothing external asks for it.
- **2026-08-05 — the FUNCTIONAL SUITE was not hermetic and passed only by accident of the shell.**
  `api/tests/bootstrap.php` required the autoloader and returned, never calling `Dotenv::bootEnv()`, and `phpunit.xml`
  declares no `TRUSTED_PROXIES` or `DEFAULT_LOCALE` although `framework.yaml` reads both. From a clean environment all
  nine `HttpSurfaceTest` cases error with `EnvNotFoundException`; setting `TRUSTED_PROXIES` alone moves the failure on
  to `DEFAULT_LOCALE`, **which is the tell that the defect was the missing loader rather than a missing variable**.
  Fixed with `bootEnv`, which is what Symfony's own bootstrap does, and it is safe because PHPUnit applies `<php><env>`
  BEFORE the bootstrap runs and Dotenv never overrides an already-set variable — so `phpunit.xml` still wins and
  `.env` only fills gaps. [Verified: `env -i HOME=$HOME PATH=$PATH` now gives `OK (9 tests, 35 assertions)`.] The
  general lesson is the one this section keeps relearning: **a suite whose result depends on the shell that launched
  it is not a suite that has been run.** Check with `env -i`, which CLAUDE.md already prescribes for infra tools.
- **2026-08-05 — widening a gate's coverage found a stray file nothing else could see.** `spdx-headers.sh` had no `ini`
  in its EXTENSIONS list, so five tracked `.ini` files carried the identifier by convention and none was enforced; the
  tell was the reported count NOT MOVING when a sixth was staged. Adding `ini` immediately surfaced a tracked
  `admin/infra/api/conf.d/dev/50-dev.ini` — a duplicate of the dev PHP config committed by accident into the ANGULAR
  tier in `9c31864`, and already the STALE copy, still naming a `compose.dev.yaml` that has never existed. Two copies
  that already disagreed, in a tier where the file has no meaning. **An unenforced convention is not a convention, and
  the cheapest way to find out what a gate is not looking at is to make it look at one more thing.**
- **2026-08-05 — a READ-ONLY reviewer broke `composer gate` without touching a tracked file, by regenerating an
  autoloader through a SHARED `vendor/`.** Three certification agents were told to mutate only a
  `git clone --no-hardlinks --shared` copy, and none of them modified a tracked path — `git status --porcelain`
  was empty throughout, exactly as instructed. Then `composer gate` died `Class "Twes\Kernel" not found` at
  `bin/console:43`, and `api/vendor/composer/autoload_psr4.php` read
  `$baseDir = dirname(dirname($vendorDir)).'/claude-0/…/scratchpad/work/api'` — a path that no longer existed. One
  reviewer had run `composer dump-autoload` in a working copy sharing our `api/vendor/`, and the regenerated file
  landed in OUR tree pointing at its scratchpad. [Verified: `$baseDir` back to `dirname($vendorDir)` after
  `composer dump-autoload`; `class_exists("Twes\Kernel")` → true; gate EXIT=0.] **The lesson generalises past
  Composer: "do not modify the working tree" is not the same instruction as "do not modify build state", and
  `git status` cannot see the difference because the damage is entirely in gitignored paths.** A freeze protects
  what git tracks; it protects nothing about `vendor/`, `node_modules/`, `.dart_tool/`, `build/` or a pub cache. So
  a panel prompt must say *copy or point `COMPOSER_VENDOR_DIR`/`PUB_CACHE` elsewhere*, and a green
  `git status --porcelain` at the end of a round is NOT evidence that the tree still builds — re-run the gate
  before believing it. The fix is local (`composer dump-autoload`) and nothing was committed wrong, which is the
  only reason this cost minutes instead of a wrong diagnosis of my own diff.
- **2026-08-06 — the gates split on ONE INVISIBLE FLAG, and a file Claude has just CREATED is untracked, so four of
  them cannot see it at all.** § Gotchas 2026-07-31 rules that a gate enumerates from `git ls-files` rather than a
  recursive walk, because a parallel certification round places reviewer worktrees INSIDE the working tree. Every gate
  obeys it. What none of them says out loud is that they obey it in **two different ways**, and the difference is the
  whole behaviour:

  | enumeration | gates | sees a new untracked file? |
  |---|---|---|
  | `ls-files --cached --others --exclude-standard` | `spdx-headers.sh`, `shell-syntax.sh`, `no-forgeable-tenancy-in-production.sh` | **yes** |
  | `ls-files` (cached only) | `no-orphaned-docblocks.php`, `no-owner-connection-in-application.php`, `worker-mode-blocked.sh`, `compose-config.sh` | **no** |

  **DERIVE THAT PARTITION RATHER THAN TRUSTING IT** — `grep -n 'ls-files' scripts/gates/*.sh scripts/gates/*.php`,
  and read which invocations carry `--others`. The row above named TWO gates for a year of commits while three
  qualified: `no-forgeable-tenancy-in-production.sh` landed 2026-08-05 and this table was written 2026-08-06, so it
  was stale on the day it was authored. That is this file's hand-written-enumeration defect landing on the very
  paragraph whose subject is a rule with a silent exception — which is why the command is now written down beside
  the answer, and why no COUNT appears in the row.

  Found by writing the `PostToolUse` hooks: the hook's own test suite reported the SPDX gate catching a brand-new file
  and `no-orphaned-docblocks.php` reporting clean on the same file, with a textbook stranded doc comment in it — the
  defect three successive certification rounds filed. At `composer gate` time this is invisible, because by then the
  file has been `git add`ed. **So the blind spot is exactly the window in which new code is written**, and a hook that
  says "clean" there is the *"a control that silently does not run"* shape this section already records four times.
  The fix is the gates' own mechanism rather than a workaround: `GIT_INDEX_FILE` pointed at a throwaway index holding
  `HEAD`'s tree plus the one path, which `git ls-files` honours, leaving the real index untouched. Contents are always
  read from the working tree, so the index only ever contributes the NAME — which is why a stale `HEAD` tree is
  harmless. Two alternatives were rejected: `git add --intent-to-add` on the real index (a hook must leave no state —
  the developer would find a path staged that they never staged), and teaching the four gates `--others` (four gates
  changed to serve a hook, and in `worker-mode-blocked.sh` it would widen a security control's scope for an unrelated
  reason). Pinned by a mutant: replacing the `export GIT_INDEX_FILE` line with a no-op turns the hook suite's own
  tally into **exactly one fewer pass and one failure**, and the case that flips is the orphaned-docblock one, whose
  fixture is left deliberately UNTRACKED for that reason. [Verified 2026-08-23 — the DIRECTION is stated and the
  totals are not, because the totals were written as `27`/`26, 1 failed` and the suite reports 28: § "Claude config"
  says of that very suite *"it reports its own case count; none is written here"*, and this citation wrote one four
  lines from the rule forbidding it. Re-measure with `bash .claude/hooks/test-hooks-on-write.sh`.] **The generalisable part is not about git:** two implementations of one documented rule
  is one rule and one silent exception, and the exception is invisible precisely because the rule is written down.
- **2026-08-06 — ONE OF THE THREE REMEDIES A PLAN OFFERED DID NOT EXIST, and finding that out took five minutes of
  reading vendor code rather than a design argument.** `build-waves.plan.md` reserved two decisions for Wave 1 on
  the savepoint tenant-binding divergence, and ranked the options for the second: drive the re-check from the
  savepoint-emitting seam, **or forbid savepoint-backed nested transactions in configuration**, or check in every
  repository (*"the weakest of the three"*). The middle option is not available: DBAL 4's
  `setNestTransactionsWithSavepoints(false)` throws `InvalidArgumentException` — *"no longer supported"* — the method
  is `@deprecated No replacement planned`, and `beginTransaction()` above nesting level 1 calls `createSavepoint()`
  unconditionally. [Verified: `vendor/doctrine/dbal/src/Connection.php:1005-1012` and `:1048-1064`.] **So a plan that
  offers N options is offering an untested claim about each of them**, and the cheapest thing to do before choosing
  is to confirm the ones you are about to reject still exist — otherwise "we chose the middleware" reads as a
  preference when it was the only option.
  Three further findings, each of which changes what the surviving remedy has to do:
  **(1) Only the ROLLBACK half of "release or rollback" matters.** `RELEASE SAVEPOINT` does not revert a
  transaction-local setting [Verified on a live connection: after `RELEASE SAVEPOINT sp1` a value set inside the
  savepoint was still present], so a re-check there could never fire — the vacuity shape this section already
  records four times, arriving this time from a plan's own prescription rather than from code.
  **(2) A FULL rollback must NOT be re-checked, and getting that wrong makes the guard worse than absent.** `ROLLBACK`
  discards the binding legitimately on every rolled-back request, so a check would throw on entirely correct code and
  the first person to hit it would delete the middleware. The distinction is safe only because DBAL's `rollBack()`
  reaches the driver as a method rather than as exec'd SQL — which is worth knowing rather than relying on.
  **(3) PostgreSQL accepts FOUR spellings and the `SAVEPOINT` keyword is OPTIONAL** — `ROLLBACK TO sp1`,
  `ROLLBACK TRANSACTION TO SAVEPOINT sp2`, `ROLLBACK WORK TO SAVEPOINT sp3`, plus the form Doctrine emits [Verified:
  all four accepted]. `stripos($sql, 'ROLLBACK TO SAVEPOINT')` matches only Doctrine's own and misses three that
  application code can write, so the predicate is derived from the GRAMMAR instead — begins with `ROLLBACK`, carries
  a `TO` clause. That is the same polarity inversion `scripts/gates/worker-mode-blocked.sh` needed three defeats to
  learn, and it is the second time in this project that **enumerating accepted spellings of a thing the database or
  the shell defines** has been the defect.
- **2026-08-06 — A RULING CAN BE UNBUILDABLE, and the gate is what tells you which of two rulings wins.**
  `DocumentIdentity`'s docblock recorded, as a settled decision, that *"the repository takes the tenant as an explicit
  argument"* — and that is impossible here: `TenantId` lives in `Infrastructure/Tenancy/`, so a `Domain/` port naming
  it is an outward dependency and a P0. [Verified: a probe interface in `Domain/Document/` declaring `f(TenantId $t)`
  produced *"Domain references Twes\Infrastructure\Tenancy\TenantId, which is outward"*, exit 1.] Two recorded
  rulings collided — that one, and § Architecture's zero-outward-dependency rule — and the tie-break was not a
  judgement call: one of them is enforced by a gate and the other was prose. **The prose loses.** The remedy is also
  the more interesting half: the adapter is constructed with the request's `TenantContext` and refuses when none is
  bound, which is STRONGER than a parameter, because a parameter is satisfied by whatever tenant id the caller happens
  to hold — including the wrong one — while a context resolved once cannot be forged at the call site. And the
  reductio § Gotchas 2026-07-31 applied to a FIELD on every type applies identically to a PARAMETER on every method:
  if `find()` needs it, so do `save()` and every query method the interface ever grows.
  Two further findings from the same change, both measured:
  **(1) A whole-rewrite through Doctrine's UnitOfWork is IMPOSSIBLE, not merely inefficient.** `remove()` on the child
  row at position 0 plus `persist()` of a new row at the same composite primary key raises
  `EntityIdentityCollisionException` from the identity map, **before any SQL is emitted** [Verified against the
  migrated schema]. That is the same immutability mismatch that put the mapping on a separate model, one level down —
  so the repository writes with DBAL, and what the ORM mapping is FOR (schema validation, typed rows, future
  `migrations:diff`) now has to be stated explicitly, or the mapping reads as unused.
  **(2) A `Domain/` port gets no auto-alias.** Symfony aliases an interface to its single implementation only when the
  INTERFACE is in an autowired resource, and `Twes\Domain\` is deliberately excluded. So every domain port needs its
  binding written in `services.yaml`; without it the failure is a message about an unresolvable argument, which points
  at the consumer rather than at the missing line. [Verified: `debug:container` before and after.]
- **2026-08-06 — AN IN-PROCESS CACHE AMORTISES ACROSS NOTHING IN PHP, and the sentence explaining why the design
  worked is where it became visible that it did not.** Wiring the acquire-time tenancy guards needed the ~10.8 ms of
  catalogue queries [measured: 7.33 + 3.14 + 0.36 ms, 50 runs each] to stop being paid per request. The obvious
  implementation is `private array $verified = []` on the middleware, and it is worthless: **PHP is shared-nothing**,
  so under PHP-FPM every request gets a fresh execution context — a `static` does not survive between requests, and a
  fresh kernel rebuilds the service anyway. The cache would have amortised across a single request and paid the full
  cost on every one, **while looking optimised**. That is worse than not caching at all, because the code then claims
  a property it does not have and the next reader believes it. A PSR-6 pool is the only thing in the ecosystem that
  crosses a request boundary. **What caught it was writing the property's docblock** — the paragraph justifying the
  design contradicted the design, three lines in. Worth generalising: when a comment explaining WHY something works
  is hard to finish, that is evidence about the code, not about the comment.
  Two more findings from the same change:
  **(1) A cached security check must be cached in NEITHER direction on failure.** Not as success — one bad start-up
  disables the guard for the whole window. Not as failure either — the fix for a wrongly-provisioned database is to
  fix the database, and a cached failure keeps rejecting one that was just repaired. So the write happens only after
  every applicable assertion passes, while the re-entrancy marker is set before.
  **(2) `ArrayAdapter::getValues()` retains a placeholder for a key merely LOOKED UP**, so asserting an empty pool
  fails on correct code. `isHit()` is the property that matters and the only one the consumer acts on — a fixture
  detail masquerading as a defect, and the second time in one day that an assertion about a *representation* rather
  than a *property* produced a false failure (the other was comparing a `NUMERIC(21,6)` quantity by string).
- **2026-08-07 — A DEFENSIVE STATEMENT IS UNOBSERVABLE ON A DEFAULT CLUSTER, so the fixture has to leave the default
  state before any mutant can reach it.** `provision-dev-database.sh` carries four statements that describe a
  PostgreSQL a modern default is not: `REVOKE CREATE ON SCHEMA public FROM PUBLIC`, `REVOKE CREATE … FROM <runtime>`,
  `GRANT USAGE ON SCHEMA public TO <runtime>` and `ALTER SCHEMA public OWNER TO pg_database_owner`. **Every one of
  them could be deleted with the whole suite green**, because on PostgreSQL 15+ `PUBLIC` already lacks `CREATE` and
  already holds `USAGE` [Verified on 18.4: `has_schema_privilege('public','public','CREATE'), … 'USAGE'` → `f, t`], and
  a freshly created database already has `public` owned by `pg_database_owner`. Same shape as the tenancy gate's
  *a probe's reach is bounded by its fixture's value space*, arriving from the other side: here the fixture was in the
  state the code defends against reaching, so the defence had nothing to do. Fixed by a case that BREAKS all four
  properties first and asserts the break — a detector before the repair — which is what Rule 7 asks of an infra change.
  Three further findings from the same work, each cheap to re-learn the hard way:
  **(1) `psql -c` DOES NOT INTERPOLATE `:'VAR'`.** It hands the string straight to the server, so a `--set` variable
  arrives literally and comes back as `syntax error at or near ":"`. Interpolation happens in psql's lexer, which runs
  on stdin and `-f` only. That is not a detail: `:"VAR"` as an identifier and `:'VAR'` as a literal are what let psql
  do the QUOTING, so a database name or password containing a quote character cannot end the statement early — the
  `-c` alternative is shell interpolation, which can.
  **(2) TWO FIXTURE AXES IN ONE CASE MADE ONE OF THEM UNTESTABLE.** Granting `CREATE` to a role that already OWNS the
  schema adds no distinguishable ACL entry — the privilege is implicit in ownership — so reassigning the owner carried
  it away and the mutant deleting `REVOKE CREATE … FROM <runtime>` survived a case that appeared to cover it
  [Verified by hand: the grant appears as `<runtime>=C/pg_database_owner` and persists when `public` is owned by
  `pg_database_owner`, and does not appear at all when the runtime role owns it]. Split into two cases. The
  generalisation: when a fixture sets several properties the code under test also sets, one of them can silently
  cancel another, and the symptom is a surviving mutant rather than a failing test.
  **(3) `bash -n`'s "unexpected EOF while looking for matching `"`" meant exactly that** — `x="$(cmd <<'H' … H
)`
  with the closing `)` on its own line never closes the opening quote; it needs `)"`. I spent two hypotheses on exotic
  heredoc-inside-command-substitution parsing, both refuted by a five-line reproduction, before reading the message
  literally. The write-time `lint-on-write.sh` hook caught it before it could reach a commit, which is what that hook
  is for.
- **2026-08-23 — `git restore` IS THE WRONG WAY TO UNDO A MUTANT, AND IT SILENTLY REVERTS THE FIX WITH IT.** The
  mutation discipline this repository runs on is *apply a mutant → run → restore*, and the obvious restore is
  `git restore <file>`. It restores the file to **HEAD**, not to the state before the mutant — so on a file that
  also carries the UNCOMMITTED fix you are testing, it throws that away too, without a word. It happened **three
  times in one session**, and each failure looked different:
  - On `DocumentCalculator.php` it made the NEXT mutant vacuous. The restore removed the fix, the following
    `python … s.replace(old, new)` found no anchor and changed nothing, and the run that followed was the
    *unfixed baseline* reported as a killed mutant. A mutant that does not apply proves nothing — the same class
    as a `sed` that exits non-zero, which also happened here, and as § Gotchas 2026-07-31's *the test is not
    arriving*.
  - On `Invoice.php` it reached the COMMIT. The comment fix was reverted mid-mutant-loop, `git add` staged four
    files instead of five, and the commit landed the pinning test while leaving the false rationale it was written
    against still in the tree. Caught only because `git status --porcelain` was printed in the same command as the
    commit and the file was visibly absent.
  - On `messages.fr.xlf` it made the gate go red immediately, which is the *harmless* version and the only one
    that announced itself.

  **Three practices, and the third is the general one.** Back the file up first (`cp -a "$F" /tmp/…`) and restore
  from THAT, so "restore" means "before the mutant" rather than "at HEAD". Make the mutant script ASSERT its
  anchor is present before writing — a `replace()` that silently matches nothing is indistinguishable from a
  mutant that survived. And **print `git status --porcelain` in the same command as `git add`/`git commit`**, so
  the staged set is visible beside the result: this is § Gotchas 2026-08-23's `head -1` rule for the message file,
  applied to the file list. `git restore` is safe only on a file with no uncommitted work of its own, which
  during a fix is exactly the file you are not testing.
- **2026-08-23 — A DENIED COMPOUND COMMAND LEAVES ITS HEREDOC UNWRITTEN, AND `git commit -F` DOES NOT FAIL ON A
  STALE PATH.** A `cat > /tmp/msg2.txt <<EOF … EOF && git add … && git commit -F /tmp/msg2.txt && git push` was
  refused by the safety classifier as one unit, so the message file was never created. Re-running just the
  `git add` + `git commit -F /tmp/msg2.txt` then committed the correct FILES under a completely unrelated message —
  `docs(serve): rule S3.3d's shape … (DEC-455.10)`, left in `/tmp` by a **phorj** session. `/tmp` is shared across
  every project on this machine, `-F` takes any readable file, and a commit that succeeds prints no warning. It was
  caught only because `git log --oneline -3` was in the same command and the subject did not match what had just
  been written; one `git push` later it would have been published history. Three practices follow, and the third is
  the general one: write a message file with a **session-scoped name** (`/tmp/twes-msg2-<date>.txt`, never
  `msg2.txt`); `head -1` the file in the SAME command as the commit, so the subject is visible beside the result;
  and treat **every artefact a denied command was supposed to produce as absent**, because a refusal aborts the
  whole compound — the parts that "already ran" did not. Same family as § Gotchas 2026-08-01's `test | tail &&
  git commit`, inverted: there a verdict was produced and never consulted, here an input was never produced and was
  consulted anyway. Note the classifier refuses the compound rather than `git commit` itself — the same commit as
  a plain `git add <paths>` then `git commit -F <file>` was accepted, and only the trailing `&& git push` re-triggered
  it, so a refusal is not evidence that autonomous committing is disallowed here (§ "Git autonomy" authorises it).
- **2026-09-02 — `self::fail()` IS AN EXCEPTION, AND `catch (\RuntimeException)` SWALLOWS IT.** PHPUnit's
  `AssertionFailedError` extends `RuntimeException`, so the standard mutant-verification shape — *do the thing,
  `self::fail()` if it did not throw, `catch (\RuntimeException $e)` and assert on the message* — catches its own
  failure and then asserts against **that** message rather than the code's. The case still went red under the
  mutant, **naming the wrong cause**, which is worse than a false green: a red reads as a killed mutant and its
  message is then read as evidence about the code. Fixed by capturing the exception inside the `try` and asserting
  entirely OUTSIDE the `catch`, so no assertion ever runs inside the guarded block. Two generalisations, each
  already in this file in a different dress: **assert on the MESSAGE and check it is about the right thing** — the
  `test-gates.sh` entry above is the same defect one level down, where a crash and a detection were
  indistinguishable, and here a detection and a *test bug* were — and a `catch` whose class is broader than what
  you expect will eventually catch the framework rather than the subject.
- **2026-09-02 — A STATUS LINE IS A SEPARATE SURFACE FROM A DECISIONS LOG, and closing a round updates both.**
  The fix pass for round 6 corrected eleven record defects, added a § 10 ruling and wrote a full closure into the
  commit message — and left **two** status surfaces (`docs/SPEC.md` § 5's Wave row and § 9's Wave 1 paragraph)
  still saying *"what remains is the certification round"* and *"the cap decision returns to the developer after
  it"*, both written before the round they describe had run. A reader landing on either concludes a round is owed
  that has already run and been dispositioned. It is the append-instead-of-amend defect committed by the pass
  whose subject was that defect — the third time this project has caught it — and the mechanism is always the
  same: **a ruling is recorded where rulings live, and the places that state the CURRENT STATE are not searched.**
  The cheap habit: after any ruling that changes a phase, `git grep -n -i "<wave or milestone name>"` and read
  every hit for TENSE, not for content. Found by `advisor()` at the 6C gate, not by the pass itself.

---

## Claude config in this repo

- `.claude/settings.json` — `defaultMode: auto`, pre-approved read-only/build commands, an empty
  `deny`, no `ask` key at all, and **exactly one hook family: the `PostToolUse` write-time pair
  below**. `deny` is present and EMPTY; `ask` is ABSENT ALTOGETHER — behaviourally identical, and the
  two spellings are what a reader compares when checking the claim. Both stay that way permanently
  (see § "Git autonomy"). `rent-watch`'s four `deny` entries over `.env` files are **not** ported
  here and porting them would break the tier: `api/.env`, `api/.env.prod` and `infra/.env` are
  committed templates the gates read on every run.
- `.claude/hooks/**` — the repo-local `PostToolUse` hooks, on `Edit|Write`, plus their own test suite
  (`bash .claude/hooks/test-hooks-on-write.sh` — it reports its own case count; none is written
  here). **That suite is NOT in `composer gate`, and saying so is the point rather than an
  omission**: it drives a hook against a sandboxed `HOME`/`CLAUDE_PROJECT_DIR`, so gating it would
  put a step that rewrites the environment inside the build. `shell-syntax.sh` covers the hooks
  parsing; the behaviour is covered by running the suite after any edit to a hook. That is the whole
  protocol, and it is a weaker guarantee than a gate — stated so nobody has to discover it.
  - `lint-on-write.sh` — `php -l` then php-cs-fixer `check` (`--path-mode=intersection`, run from
    `api/` because the fixer reads `composer.json` from the cwd), or `bash -n` for a `.sh`. **PHPStan
    is deliberately out** at ~11 s per file; `gate:static` covers it. **Nothing is auto-formatted** —
    `check`, never `fix` — because a doc comment's position is part of its truth here.
  - `gates-on-write.sh` — **owns no rules.** Every check invokes a real script in `scripts/gates/`,
    so a write-time verdict is the gate's own verdict with the gate's own message. Routing is biased
    towards over-running; `test-gates.sh`, `schema-tenancy.php` and `compose-config.sh` are excluded
    and each exclusion is pinned.
- `.claude/skills/**` — the two repo-native slash skills, read in place.
- `.claude/agents/**` — the three certification-lens reviewers.
- `.claude/settings.local.json` is gitignored — machine-local overrides go there.
- **`scripts/claude-bootstrap/` is GONE (removed 2026-08-18).** It existed because cloud containers
  started with an empty `~/.claude/` each session; that environment is dead. Session handoffs are the
  GLOBAL PreCompact hook's job.

---

## Git & CI

- Single developer, **single branch `master`**, commits direct, no PR review gate — so the local
  quality gate is the only safety net before history. Never commit red.
- **CI is not yet configured** and there is no `.github/`. When it lands it mirrors `docs/SPEC.md`
  § 7 tier by tier, and every job carries a comment explaining **why it exists and what breaks
  without it** — that comment style is house convention, not decoration.
- Local git hooks, when added, are tracked in-repo and wired with `core.hooksPath`; the hook scripts
  are the SSOT of their own steps — never restate a hook's contents in prose here.

---

> **Remember**: read `docs/SPEC.md` first — it is what to build and where the build actually is.
> Work on **`master`** only. Ask via **`AskUserQuestion`**, sparingly. Never commit red, and never
> let a green suite stand in for a guarantee nothing exercises.

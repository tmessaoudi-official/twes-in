# CLAUDE.md — twes-in

> This file holds the RULES for how Claude delivers code here — quality, carefulness, gates, and the
> licensing boundary that governs this whole project. The product itself (roadmap, specs, decisions)
> lives in `docs/`. Boundary test before adding anything: *does Claude need this to deliver correct
> code?* If not, it belongs in `docs/`, not here.

twes-in is an invoicing / billing platform: a **Symfony REST API**, an **Angular admin web client**,
and a **Flutter** client for all six targets — Android, iOS, Linux, Windows, macOS and Web — over
**PostgreSQL**. The Flutter Web build is a **second admin interface** alongside `admin/`, so twes-in ships
two of them, deliberately.

It is a **clean-room reimplementation inspired by Invoice Ninja** — never a fork, never a port. That distinction is legal, not stylistic,
and § "Licensing invariants" below is the most important section in this file.

It is intended to run **both as the developer's own internal invoicing and as a product sold to
others**, so ELv2's hosted-service prohibition genuinely bites and clean-room is mandatory rather than
merely preferable. twes-in is **AGPL-3.0-or-later plus a commercial licence** (`LICENSING.md`).

A future rewrite of the core in [phorj](https://github.com/tmessaoudi-official/phorj) — the developer's
own statically-typed PHP-inspired language — is **vision, not a target**: the language is unfinished and
nothing here is built for it. Do not treat it as a requirement, do not design around its unknowns, and
do not defer a decision waiting for it. See `VISION.md`.

Status: **Wave 0 landed, not yet certified; Wave 1's PURE DOMAIN landed** (2026-07-31). Wave 1's document
kernel, lifecycle, numbering and `Invoice` aggregate are under `api/src/Domain/Document/` — framework-free,
which is why they landed while Composer is blocked. Wave 1's persistence, migrations, schema gate and HTTP
surface remain blocked; `docs/plans/build-waves.plan.md` § Wave 1 is authoritative.

`api/` holds a framework-free `Domain/` (money, pricing, **documents**) and `Infrastructure/` (tenancy, clock,
identifiers), four PHPUnit suites, and the gates in
`scripts/gates/` with their own test suite (`ls scripts/gates/` and `scripts/gates/test-gates.sh` each report
their own count; no number is written here, because a count in prose drifts). **`admin/` and `mobile/` are scaffolded** — each with its
tier's official generator (`ng new`, `flutter create`), never by hand — and each is green on its own toolchain.
Each also holds the **application code its own invariants already demanded**, which this sentence used to deny:
the branding seam licensing invariant 9 requires (`admin/src/app/branding.ts`, `mobile/lib/branding.dart`),
the Flutter font-fallback and same-origin controls in `mobile/lib/main.dart` and `mobile/web/`, and executed
tests for all of them. What neither tier holds is any **domain or transport** code — no invoicing, no models,
no API client — which is the accurate claim and the one the build-wave plan is written against. Both tier
READMEs described that branding code as implemented while this line said the tiers were empty; round 11
found the contradiction.

**Not yet built:** the Symfony application itself (`bin/`, `config/`, `public/`, `.env`), Doctrine,
PHPStan/deptrac — all blocked, see § Gotchas on GitHub egress — and the `infra/` tier. That blocker is why the
API tier has no skeleton while the two client tiers do: `ng new` and `flutter create` fetch from npm and
pub.dev, which are reachable; `composer create-project symfony/skeleton` fetches from GitHub, which is not.
Anything below describing those is the *target*. Read `docs/plans/build-waves.plan.md` for where the build
actually is.

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

**EVERY reply ends with ONE of exactly two markers** (developer instruction, 2026-07-30). Without one, the
developer cannot tell a question from a pause, and both look like prose that stopped. No exceptions, including
short replies:

- `❓ QUESTION — <one line>` followed by the numbered options. **I am blocked and waiting on a decision.**
- `⏹ NO QUESTION — <what I am waiting on, or why I stopped>`. **Nothing is being asked of the developer.**

The marker is the LAST line. If a reply would end without one, it is unfinished.

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
   list applies and `scripts/gates/dependency-licences.php` fails — which is why the gate keeps **three
   separate lists** rather than one wider one, and asserts a **MAXIMUM** on each. (This sentence said "two
   lists" for a commit while the paragraph four lines below it added the third; round 8 found it. A count
   written next to the thing it counts is worth re-reading whenever that thing changes.)

   **A vendored FONT ASSET may carry `OFL-1.1`** (developer ruling, 2026-07-30) — a third narrow category, for
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

10. **When a licensing question is genuinely unclear, STOP and ask in plain text.** Do not resolve a
    licence question by picking the convenient reading. This is a one-way door — Rule 18's
    `[Speculative]` grade is not good enough to build on.

## The API contract is ours to design — and still load-bearing

Because all three clients are ours, **the contract is designed, not inherited.** None of Invoice
Ninja's shape is binding: no unix-second integers, no `double` money, no `_method=put` multipart, no
`per_page=999999`, no two mandatory version headers whose absence crashes the client. Design it the way
a 2026 API should be designed, and record it in
`docs/plans/reimplementation-strategy.plan.md` once.

It remains load-bearing for a different reason: **a shipped mobile client updates on app-store
timelines, not ours.** Once the Flutter app is in a store, the contract is effectively frozen for
anyone who hasn't updated. So it is pinned by contract tests and versioned deliberately, and a change
to a field name, an enum value, an envelope shape, an error format or an auth header is a **breaking
change with a migration plan**, never an incidental edit. `completeness-reviewer` treats an API change
that does not reach every client tier — API, OpenAPI spec, Angular, Flutter — as a P0.

## Architecture — DDD, hexagonal, clean

Developer ruling, 2026-07-29: **TDD, DDD, hexagonal and clean architecture**, "really
structured/scalable and flawless and exemplary". That directive is the whole justification and it needs
no other — a billing domain is exactly the case these patterns exist for, because the rules that matter
(money arithmetic, tax, state transitions) are the part that must be testable in isolation and must
outlive every framework decision around them.

One consequence worth naming, but **not a reason for any of these rules**: a framework-free domain
would also be the part that survives a future rewrite in another language, so the phorj idea in
`VISION.md` stays cheap to keep open. That is a side benefit of doing this correctly, not a requirement
driving it — phorj is unfinished and nothing here is designed for it.

**The domain layer is pure. This is the load-bearing rule.** In `Domain/`:

- **No framework, and no ambient I/O.** No Symfony, no Doctrine, no HTTP, no SQL, no filesystem. Also
  **no clock, no randomness, no environment** — time and randomness are injected through a port
  (`ClockInterface`, an ID generator), never read ambiently. Note the detection differs by kind, which
  is why they are listed separately: a framework dependency shows up as a `use` statement, but
  `time()`, `random_int()`, `getenv()`, `file_get_contents()` and `date()` are **bare function calls
  with no `use` at all** — so a `use`-grep does not see them. Both are P0; the second needs a
  banned-function rule (PHPStan) rather than an import check.
- **No Doctrine attributes on entities.** Mapping lives in XML (or PHP) mapping files under
  `Infrastructure/`. This is the single most common way a "hexagonal" PHP codebase quietly becomes
  framework-coupled. Detection is a plain grep — `#[ORM\` anywhere under `Domain/` is a P0.
- **Dependencies point inward.** `Domain` knows nothing. `Application` knows `Domain`.
  `Infrastructure` and `UI` know both. Never the reverse — an **outward**-pointing `use` from `Domain`
  to `Infrastructure`, `UI` or `Application` is a P0.
- **Ports are domain interfaces; adapters implement them in `Infrastructure/`.** A repository interface
  belongs beside the aggregate it serves, not beside its Doctrine implementation.
- **Conservative, strongly-typed PHP in the domain.** `declare(strict_types=1)` everywhere, explicit
  types on every parameter, property and return. No magic (`__get`, `__call`), no dynamic properties,
  no arrays where a value object belongs. This is good practice regardless; it also keeps the domain
  inside the subset a stricter language can express, which is the point.

**Money is our own value object**, in the domain, immutable, carrying an amount plus its currency, with
an **explicit rounding mode on every operation that can lose precision**. Built in Wave 0:
`api/src/Domain/Money/Money.php`, with `Currency` holding each currency's ISO 4217 scale and refusing an
unknown code rather than assuming two decimals.

**The domain's arithmetic is `bcmath`, and `Domain/` therefore has literally zero Composer
dependencies** (ruled Wave 0, 2026-07-29 — this resolves what used to be a contradiction in this file,
where the layout comment said "ZERO dependencies" while this paragraph contemplated a decimal library).
`bcmath` is a PHP *extension*, so using it adds no package. Two alternatives were rejected on merit:
**scaled integers**, because `NUMERIC(19,4)` reaches 10^19 when scaled to ten-thousandths and PHP's
integers silently become floats past 9.22×10^18 — an intermediate product overflows far sooner;
and **`brick/math`**, which is MIT and perfectly good, but unnecessary once bcmath is doing the exact
arithmetic. What bcmath does *not* provide is rounding — every bcmath function truncates — so all of
it lives in `Domain/Shared/Decimal::applyRounding()`, written once and tested exhaustively across all
eight modes including negative ties. `scripts/gates/layer-dependencies.php` enforces the zero-dependency
rule with an empty allowlist; adding an entry is an argument to be made in a commit message.

**The default currency is TND, which has THREE decimal places** (1 dinar = 1000 millimes), so **a
2-decimal assumption is a bug for the default currency, not an edge case**. No `round($x, 2)`, no
`× 100` to reach minor units, no "cents" in a name or a comment. `Money` carries each currency's own
scale and refuses to assume one; the column type is `NUMERIC(19,4)` **plus a companion `currency` column on
every persisted amount** (added at round 12, which found that argument made for the `product` table alone
— a `Money` is *(amount, currency)*, so a bare NUMERIC cannot reconstitute one) — 3 decimals exact, plus a digit of
headroom for unit prices and rates. Tunisia's stamp duty of `0.100 TND` is 100 millimes and must
represent exactly. [Verified: ISO 4217 — TND, BHD, JOD, KWD, OMR, LYD and IQD are the 3-decimal set.] A decimal library may be an
implementation detail inside it; it may never leak into a signature. Rationale: this is the crown-jewel
logic, a wrong number here is a wrong legal document, and upstream's version of it is the single worst
defect in the product we are learning from — floats on models, `BcMath::mul` and native float
arithmetic in *adjacent methods* of the same helper, and rounding skipped entirely on one Peppol path.
Owning the type outright, rather than exposing a third-party money class across the domain, is also
what keeps that logic swappable later.

**Tax, discounts and rounding order are one implementation, never two.** Inclusive versus exclusive tax
is a *parameter*, not a parallel class hierarchy. Upstream maintains
`InvoiceItemSum`/`InvoiceItemSumInclusive` and `InvoiceSum`/`InvoiceSumInclusive` as four separate
classes that must be kept numerically in step by hand; that duplication is a large part of why their
test suite is 167k LOC. One code path, parameterised.

**State transitions go through a guard, never a direct assignment.** A status written by assignment is
how illegal transitions enter a billing system. `domain-correctness-reviewer` looks for exactly this.

**Repository layout — monorepo** (developer ruling, 2026-07-29). Four top-level tiers, so one commit
can change the API and every consumer together; that is what makes the API-contract rule enforceable
at all:

```
api/          # Symfony REST API (layout below)
admin/        # Angular admin web client
mobile/       # Flutter client — Android, iOS, Linux, Windows, macOS, Web. ALL SIX ruled in scope, so
              # desktop is not "later"; the Web build is a SECOND admin interface alongside admin/.
infra/        # Dockerfiles, compose, deployment. WRITTEN FROM SCRATCH — never copied from
              # invoiceninja/dockerfiles, which is GPL-2.0 (licensing invariant 7).
docs/plans/   # plans, each with its own Decisions Log
```

Inside the API:

```
api/src/
  Domain/          # entities, value objects, domain events, port interfaces. ZERO dependencies.
  Application/     # use cases / command+query handlers. Depends only on Domain.
  Infrastructure/  # Doctrine, HTTP clients, PDF, gateways, e-invoicing, Doctrine mapping XML.
  UI/              # REST controllers, CLI commands, serializers.
```

**Enforcement landed in Wave 0.** The P0s above are no longer prose — seven gates in `scripts/gates/`
check them, each proven to fail on an injected violation before being trusted:

| Gate | Enforces |
|---|---|
| `layer-dependencies.php` | inward-only dependencies, **and** the domain's zero-Composer-dependency rule |
| `no-ambient-calls-in-domain.php` | no clock, randomness, environment or I/O in `Domain/` |
| `no-orm-attributes-in-domain.sh` | no `#[ORM\` or any Doctrine reference under `Domain/` |
| `spdx-headers.sh` | licensing invariant 8(c), on every source file — **and that the search roots COVER every tracked source file**, which is the direction that was missing when `api/phpunit.xml` sat unscanned with no identifier |
| `dependency-licences.php` | every dependency permissive (licensing invariant 8(a)) |
| `locale-key-parity.php` | every locale carries the same key set |
| `shell-syntax.sh` | every tracked shell script parses — **including the other gates**, which is why it is here and not in Wave 12 with `infra/` |

The two layer gates are **separate on purpose, and merging them would be a mistake**: a framework
dependency arrives as a `use` statement and an import check finds it, but `time()`, `random_int()`,
`getenv()` and `file_get_contents()` are bare function calls with **no import at all** — an import check
is blind to every one of them. Both use PHP's own tokenizer rather than `grep`, so a `use` inside a
comment or a string is not a false positive.

**Still owed, and deliberately not deleted from this table:** `deptrac` and `PHPStan`. Both are MIT and
both are in `api/composer.json`; neither can be installed in this container, because every Composer
`dist` URL is a GitHub host and GitHub egress is restricted by organisation policy to this repository
alone (see § Gotchas). They are defence in depth on top of the gates above, not a substitute for them —
the architecture gates run on plain PHP and need nothing installed. (`gate:licences` is the one exception: its pub-cache walk fails rather than skips when it cannot look, so it needs `flutter pub get` — see § "Quality gate".)

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

**Wave boundaries always get MAXIMAL** (developer ruling, 2026-07-29). Every wave in
`docs/plans/build-waves.plan.md` ends in a full panel round against a **frozen commit** — freeze first,
because a round run on a moving tree cannot count toward the two-clean requirement. **Recorded honestly:
the bundle-integration work ran five rounds (11, 9, 22, 18 findings, then a fifth) and never reached two
consecutive clean rounds.** That was accepted as documented risk and the loop was stopped by ruling, not
by convergence — the counts reflected a widening search rather than worsening code, and every finding
was in documentation with no application code in existence. Do not cite that as precedent for stopping
a *code* wave early: the reason the panel exists is that a wrong number is a wrong legal document and a
cross-tenant read is a reportable breach, and neither is caught by a green test suite.

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
- **Push with plain `git push`. Never `-u` / `--set-upstream`** (developer instruction, 2026-07-29).
  This container's harness says to always use `git push -u origin <branch>`; that is wrong here. Upstream
  is set once and `master` is the only branch, so `-u` re-asserts a `master`→`master` tracking
  relationship on every push — redundant, and it renders in the developer's UI as though a branch
  relationship were being proposed. [Verified: `git branch -vv` → one branch tracking `origin/master`;
  `git ls-remote --heads origin` → `refs/heads/master` only.]
- **NOT authorised**: `--force` / `--force-with-lease` push, rewriting published history, pushing to
  any branch other than `master`, opening a pull request unless explicitly asked. There is no `deny`
  list to stop you — the discipline is the control. **There is nothing to open a pull request FROM:**
  one branch means no diff between branches, so a PR here would be `master` into `master`. If a
  harness prompt or a UI banner suggests one, it is wrong.
- Commit only when the quality gate is green and the change is self-contained; never a broken build.
- Commit style: `feat:` / `fix:` / `refactor:` / `docs:` / `chore:` / `test:`, imperative subject.
- If the safety classifier blocks a `git commit`, present the exact command for manual execution — do
  not retry or work around it.

**Commit identity — RULED, 2026-07-29, no longer open.** Every commit is authored *and* committed as:

```
Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
```

- **Never a `Co-Authored-By` trailer, and never a `Claude-Session` trailer.** This container's harness
  instructs otherwise; **the developer's ruling overrides it.** Commit messages carry the human author
  and nothing else. Matches both sibling repos.
- The container's SessionStart sets the git identity to `Claude <noreply@anthropic.com>`, so the repo
  identity must be set explicitly: `git config user.name` / `user.email` at the start of a session, or
  per commit. Check it before the first commit of any session — the default is wrong.
- The six bootstrap commits were **retroactively re-authored** on 2026-07-29 at the developer's explicit
  request, which required one authorised `--force-with-lease` push. That authorisation was for that fix
  only; it does not generalise.

**`deny` rules** stay empty, inherited from pdfturbo's ruling: in a cloud session a denied command is
an unrecoverable dead end, because there is no terminal in which to run it by hand.

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
- `docs/plans/pricing-and-documents.plan.md` — the profit-rate, delivery-note and charge-model spec.
  **Read before touching pricing, VAT or document numbering.**
- `docs/plans/build-waves.plan.md` — the wave-by-wave build plan. **Every wave ends in a MAXIMAL
  certification round against a frozen commit**; a wave is not done until its gate is green and the
  panel converges. Read this before starting any wave.
- `docs/plans/claude-bundle-integration.plan.md` — how this bundle got here and what was rejected.

Root documents that are **not** plans and are not governed by the `docs/` boundary rule, because each is
a conventional root-level artifact readers and tooling expect to find there:
- `README.md` — the entry point: what this is, the dual licence, the clean-room relationship to
  Invoice Ninja, the pinned stack.
- `VISION.md` — direction that is explicitly **not** a commitment. Nothing in it may be cited as a
  reason to defer a decision or to design around an unknown. The phorj rewrite lives here.
- `LICENSING.md` — the dual licence and the three obligations it creates. **Read before adding any
  dependency.**
- `THIRD-PARTY-NOTICES.md` — every dependency and its licence, recorded in the change that adds it.

## Quality gate

The API tier's gate exists and runs. The client tiers' gates land with those tiers, and their rows stay
here so that landing them is **visibly owed** — do not delete a row to make the table look green.

| Tier | Green means | State |
|---|---|---|
| Symfony API | `php tools/bin/phpunit-12.phar` (all four suites), `php tools/bin/php-cs-fixer.phar check`, `composer validate` | **Runs** |
| **Architecture fitness** | the seven gates in `scripts/gates/` — see § "Architecture" for the table and why two of them are separate — **plus `scripts/gates/test-gates.sh`, which tests the gates.** A gate that cannot fail is a false assurance: round 2 proved that suite was too weak and round 3 proved it again, so it was strengthened twice. It now asserts each gate's own **message** rather than only its exit code, and — because hand-picked cases pin the fixture's instances rather than the rule sets — every gate answers `--dump-rules` and the suite **generates** one case per banned function, superglobal, instantiation, layer pair, SPDX root, extension and lock section, backed by a committed baseline that fails if any rule set shrinks, and by committed minimum rule-set SIZES, because generating a case from the data means deleting an entry deletes its own case. The suite reports its own case count; none is written here | **Runs** |
| **Licensing** | `scripts/gates/dependency-licences.php` — every dependency permissive **and present in `THIRD-PARTY-NOTICES.md`**, over `api/composer.lock` and `admin/package-lock.json`, plus **every locked pub package's own licence read out of the pub cache** (`mobile/pubspec.lock` records no licence field, so the cache is the only place they exist — a copyleft grant is vetoed even when the same file also states a permissive one, more than one match is refused as ambiguous, a non-`hosted`/non-`sdk` source is refused, and a version that is not plain semver is refused because it becomes a filesystem path), plus every **vendored font** under `mobile/assets/fonts/`, recursively: a REUSE sidecar declaring exactly one identifier, an acceptable one, **every one of the font's own `name`-table licence records corroborating it**, the licence text beside the binary *and* declared under `flutter:`→`assets:` so it ships, and — the direction that was missing — **every font path the manifest declares must have been examined**, because a forward walk says nothing about the files it never reached. A font arrives as a committed binary rather than a manifest entry, so no lock file can see it; it is not the only such asset (13 of the 37 tracked `.png`/`.ico` files are template-derived and ship too), which is why that sentence no longer says "the one" | **Runs** |
| Symfony API, owed | `vendor/bin/phpstan` (max level), `vendor/bin/deptrac`, `bin/console lint:container`, `bin/console doctrine:schema:validate` | **Blocked** — needs `composer install`; see § Gotchas on GitHub egress |
| Angular admin | `npm run lint`, `npm test -- --no-watch`, `npm run build` | **Runs** (scaffolded 2026-07-29; Vitest + jsdom, so no browser needed) |
| Angular admin, owed | `axe-core` a11y, locale key-parity over `admin/src/locale`, the shared pricing vectors | Wave 8 — `admin/README.md` lists it as gate conditions |
| Flutter client | `flutter analyze`, **`flutter build web --release --no-web-resources-cdn`, then `flutter test`** — in that ORDER | **Runs** (scaffolded 2026-07-29, all six platform directories present). The order is load-bearing, not stylistic: two tests read `build/web` to prove the bundle reaches no external origin, and with `test` before `build` they **skip** and the command still exits 0 with "All tests passed!". A GDPR control that silently does not run is worse than one that is owed. |
| Flutter client, owed | semantics/a11y tests, golden or real screenshots at a **desktop** window size as well as a phone one, the shared pricing vectors, **a build of all six targets** (Android, iOS, Linux, Windows, macOS, Web — not cross-compilable, so three CI runners; matrix in `build-waves.plan.md` § Wave 12), and a real bundle identifier | Wave 11 — `mobile/README.md` |
| **Shell syntax** | `scripts/gates/shell-syntax.sh` — `bash -n` over every tracked shell script, discovered from `git ls-files` plus a shebang sweep rather than a written list | **Runs** (added at round 11, which pointed out that ten scripts already existed and already passed, while this row deferred the check to the wave that would add *more* — so the ones that existed went unchecked, and a syntax error in a gate is the worst place for one: the gate stops detecting and its non-zero exit reads as a detection) |
| Infra | `docker compose config` | Wave 12 — `infra/README.md` |

**The one command to run the API tier's gate**, once Composer works, is `composer gate` — it chains
`gate:licences`, `gate:architecture`, `gate:static`, `gate:style`, `gate:mapping` and `gate:test`. Until
then, `gate:architecture` runs today with no dependencies at all. **`gate:licences` is plain PHP too but is no longer dependency-free**: its pub-cache walk FAILS rather than skips when it cannot look (developer ruling, 2026-07-30), so it needs `cd mobile && flutter pub get` first, or `PUB_CACHE` pointing at a populated cache. That coupling is the accepted cost of not letting a licence check pass quietly on nothing. The gate prints a `counts —` line unconditionally so the meta-suite's anti-vacuity probes still work on a PHP-only checkout:

```
bash  scripts/gates/shell-syntax.sh
bash  scripts/gates/no-orm-attributes-in-domain.sh
php   scripts/gates/layer-dependencies.php
php   scripts/gates/no-ambient-calls-in-domain.php
bash  scripts/gates/spdx-headers.sh
php   scripts/gates/locale-key-parity.php
php   scripts/gates/dependency-licences.php
bash  scripts/gates/test-gates.sh          # the gates' OWN tests — see § Gotchas on why this one matters
cd api && php tools/bin/phpunit-12.phar && php tools/bin/php-cs-fixer.phar check && composer validate
```

**Tooling setup in a fresh container** (nothing here is installed by default):

| Tier | How | Reachable? |
|---|---|---|
| PHP 8.5.8, PostgreSQL 18.4 | sury.org and PGDG apt repositories | yes |
| PHPUnit, php-cs-fixer | `bash scripts/dev/fetch-tools.sh` — official phars, pinned SHA-256 | yes (`phar.phpunit.de`, `cs.symfony.com`) |
| PostgreSQL roles for the tenancy proof | `sudo -u postgres bash scripts/dev/provision-test-database.sh` | n/a |
| Node 26.5.0 | tarball from `nodejs.org/dist`, verified against the published `SHASUMS256.txt` | yes |
| Angular CLI 22.0.9 | `npm install -g @angular/cli@22.0.9` | yes (`registry.npmjs.org`) |
| Flutter 3.44.8 | `flutter_linux_3.44.8-stable.tar.xz` from `storage.googleapis.com` | yes |
| **Composer dependencies** | `composer install` | **NO — GitHub egress, see § Gotchas** |

Two notes that cost time to rediscover. The container's default Node is **22.22.2**, one patch below Angular
22's `^22.22.3` floor, so Angular CLI refuses to install until Node is upgraded. And Flutter warns loudly about
running as root but works; the warning is not a failure. The **integration suite's
database prerequisites** are:

```
sudo -u postgres bash scripts/dev/provision-test-database.sh
```

**NINE roles, not one, and the script explains why each one exists** (nine as of round 14; the script's own
comment block is the tally, because this list has now grown at four separate rounds) — this replaced three `createuser`
lines after round 4 found a **P0** in what they produced. A single role that owns the tenant-owned tables
can `ALTER TABLE … DISABLE ROW LEVEL SECURITY` or `TRUNCATE` them in one statement (`FORCE` stops an owner
*skipping* policies, not *removing* them), so every isolation assertion was being made against a connection
that could step around the thing being asserted — and the suite proved it, running both statements on the
connection it had just certified as unable to bypass. Each role exists to make one refusal branch provable against a real
connection: `twes` (restricted runtime, owns nothing, no `TRUNCATE`, `NOREPLICATION`), `twes_owner` (owns the
tables, **never granted to `twes`** — that grant is the ordinary convenience wiring that reopens the whole
bypass), `twes_bypass` (`BYPASSRLS`), `twes_member` (harmless attributes of its own but a *member* of
`twes_bypass`, proving privileges reached by `SET ROLE`, including from `session_user` while `current_user`
looks clean), `twes_replicator` (`REPLICATION` — which reads the whole cluster through `pg_basebackup` with
row security never involved), `twes_truncator` (granted to the runtime role **`WITH INHERIT FALSE`**, the
shape `has_table_privilege` cannot see) and `twes_probe_owner` (granted to the owner **`WITH ADMIN OPTION`**,
so a test can own a table with a role the runtime role can *reach* but not *inherit*), `twes_unsettable`
(granted **`WITH INHERIT FALSE, SET FALSE`** — held but unreachable, the ONLY shape under which
`pg_has_role(..., 'MEMBER')` and `pg_has_role(..., 'SET')` disagree, without which round 12's `'SET'` fix was
revertible with the whole suite green) and **`twesMixedCase`** (the one role whose name is not all-lowercase,
because `current_user::regrole` DOWNCASES and raised `role "twesmixedcase" does not exist` — an outage, not a
verdict; round 13 fixed it and round 14 found the fix revertible, since eight lowercase roles cannot express the
shape).

**The principle, learned the hard way:** a fixture that cannot express a dangerous shape cannot detect it.
Every role after the first two was added because a certification round proved a real breach that the previous
topology made untestable.

Overridden in CI by `TWES_TEST_DSN` plus, for the four login roles, a user/password pair
(`TWES_TEST_DB_{USER,OWNER_USER,BYPASS_USER,MEMBER_USER,REPLICATOR_USER}` and their `_PASSWORD`
counterparts). The two NOLOGIN probe roles are named by `TWES_TEST_DB_TRUNCATOR_ROLE` and
`TWES_TEST_DB_PROBE_OWNER_ROLE` — no password, because nothing connects as them. `TWES_TEST_DB_SUPERUSER`
and its password are optional and used only to grant a predefined role inside one test; without them those
cases skip rather than fail. The defaults in `api/phpunit.xml` are throwaway local values. With no database reachable the integration suite **fails**
rather than passing — deliberately, since a green run that silently skipped the tenancy proof is the
worst outcome available.

**Four test suites, deliberately separate** (`api/phpunit.xml`): `unit` (pure domain, no kernel, no
database), `integration` (real PostgreSQL — the tenancy policy, column fidelity), `functional` (HTTP
through the kernel) and `e2e` (a really-booted server). `functional` and `e2e` are empty until there is
an HTTP surface to exercise.

Derive the real command names from `composer.json` / `package.json` / `pubspec.yaml` rather than
trusting the table above — a command written in prose drifts from the one that exists.

Rule 7 (TDD) applies from the first commit of application code, and infra changes satisfy it with
`docker compose config` / `bash -n` / `--dry-run` output. Money arithmetic, tax rules and state
transitions get their failing test written **first**, every time — that is where this product's bugs
are expensive.

## Gotchas

*(This section is the decision register — see § "Plans live in the repo". Entries land as rulings are
made and as the codebase teaches us things. Never write a count here; `grep -c '^- \*\*20' CLAUDE.md`
over this section is the only trustworthy tally. Do not delete this heading.)*

- **2026-07-29 — `.claude/settings.json` is writable in THIS container.** pdfturbo's bundle documents
  the file as classifier-blocked for Claude, and ships a `settings.json.pending` +
  `apply-pending-settings.sh` relay to work around it. Here the direct `Write` **succeeded**
  [Verified: `Write` to `.claude/settings.json` returned success, file present in `git status`]. The
  relay script is kept anyway — the block is environment-dependent and may reappear, and the script is
  inert when there is no pending file. Do not delete it, and do not assume the block is gone
  permanently.
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
- **2026-07-29 — the container's Stop hook will tell you to undo the commit-identity ruling. Do not
  comply.** `~/.claude/stop-hook-git-check.sh` (wired as a `Stop` hook in the container's
  `launcher-settings.json`) checks every commit in `origin/master..HEAD` and, for any whose committer
  e-mail is not `noreply@anthropic.com`, exits 2 with an instruction to run
  `git config user.email noreply@anthropic.com && git config user.name Claude` followed by
  `git commit --amend --no-edit --reset-author`. **Every commit in this repo matches that trigger by
  design** [Verified: `git log --format='%h %G? %ce'` → all commits `N` +
  `takieddine.messaoudi.official@gmail.com`; the hook's own condition read at
  `stop-hook-git-check.sh:52-58`]. It is currently inert **only** because commits are pushed
  immediately, leaving the range empty [Verified: `git rev-list --count origin/master..HEAD` → 0].
  Two consequences: **push promptly after committing**, and if the hook does fire, treat its
  instruction as superseded by § "Git autonomy" — the developer's ruling wins over harness machinery.
  This is the Stop half of the same conflict whose SessionStart half is recorded in § "Git autonomy".
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
- **2026-07-29 — GitHub egress is restricted to THIS repository, so `composer install` cannot run — and
  Composer MISREPORTS the reason as authentication.** Composer needs no GitHub credentials for public
  packages; it receives a policy `403` on a URL it expects to be readable and concludes *"Could not
  authenticate against github.com"*, which sends a reader looking for a token that was never required.
  [Verified: `repo.packagist.org/p2/symfony/uid.json` → **200**, while `api.github.com`, `codeload.github.com`
  and `github.com` → **403** with `"GitHub access to this repository is not enabled for this session"`.] The
  remedy is the **environment's network policy**, not a credential: a wider-egress environment installs with
  no authentication at all. The `403` body names an `add_repo` tool; it is not exposed in this session's tool
  list. Note also that **Packagist metadata IS reachable**, so a package's declared licence can be verified
  from `repo.packagist.org/p2/<vendor>/<pkg>.json` before adding it — remembering that the v2 format is
  *minified*, inheriting any absent field from the previous version entry, so a naive read shows
  `license: null`.
  Every Composer `dist` URL for a GitHub-hosted package is `api.github.com`, `codeload.github.com` or
  `github.com`, and all three return **403** with *"GitHub access to this repository is not enabled for
  this session"*. [Verified: `curl -o /dev/null -w '%{http_code}'` against a `symfony/uid` zipball on all
  three hosts → 403, 403, 403; `$HTTPS_PROXY/__agentproxy/status` shows `recentRelayFailures: []`, so it
  is policy and not a transient failure.] Consequences, all of them acted on rather than worked around:
  **(a)** `api/composer.lock` is committed and fully pinned, so a session with reachable dist URLs needs
  only `composer install`; **(b)** PHPUnit and php-cs-fixer publish official phars from `phar.phpunit.de`
  and `cs.symfony.com`, which *are* reachable — `scripts/dev/fetch-tools.sh` fetches them against pinned
  SHA-256 hashes; **(c)** the architecture and licensing gates are written in plain PHP and bash
  precisely so they need nothing installed — with one deliberate exception since 2026-07-30, `gate:licences`,
  whose pub-cache walk needs `flutter pub get` because it fails rather than passing quietly on nothing; **(d)** PHPStan and deptrac ship phars only from GitHub
  releases, so they stay owed. Do **not** resolve this by pointing Composer at a third-party mirror —
  that is a provenance decision this project cannot make casually, given § "Licensing invariants".
- **2026-07-29 — tenant isolation is PostgreSQL row-level security, not (only) a Doctrine filter.**
  The plan called for a default-on Doctrine filter, and the requirement behind it was that forgetting it
  must be *impossible*. A filter cannot deliver that: it scopes queries the ORM builds, and a native
  query, a raw DQL fragment, a migration, a reporting job or a `psql` session all bypass it. An RLS
  policy is applied by the server to every statement whatever issued it. Three things silently defeat it
  and all three are checked, not assumed: a **superuser or `BYPASSRLS`** role (RLS never applies —
  `PostgresRowLevelSecurityIsolation::assertConnectionCannotBypassPolicies()`), the **table's owner**
  unless the table also has `FORCE ROW LEVEL SECURITY`, and a **session-scoped `SET`** on a pooled
  connection, which is why binding uses transaction-local `set_config(..., true)`. The policy compares
  against `current_setting('twes.tenant_id', true)`, which is NULL when unset, so an unbound session sees
  **nothing rather than everything** — that fail-closed direction is the whole design and
  `TenantIsolationTest` asserts it directly, alongside a test that disables the policy and watches every
  tenant leak. When Doctrine lands, the filter becomes a second layer, not the only one.
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
- **2026-07-29 — money is never a float.** Recorded here on day zero because it is unfixable later.
  Upstream stores amounts as floats on models and reaches for `bcmath` only in places; its own tax
  helper mixes `BcMath::mul` with native float arithmetic in adjacent methods, and skips rounding
  entirely on one path when Peppol is enabled. [Verified: read `app/Helpers/Invoice/Taxer.php`.] A
  `float` on a money column, or anywhere near an amount, is a P0 for `domain-correctness-reviewer`.
  **Resolved in Wave 0** — and note the earlier suggestion here of "integer minor units or `brick/money`"
  was superseded: it is a decimal string over `bcmath`, in our own `Money`, with `NUMERIC(19,4)` columns.
  See § "Architecture" for why both alternatives were rejected on merit. `Money::of()` rejects a `float`
  argument as a `TypeError` by signature, which is the cheapest possible enforcement of this rule.
- **2026-07-29 — tenancy scoping must be default-on.** Upstream's `company_id` scope is an *opt-in*
  Eloquent scope every query has to remember, with no global enforcement. [Verified: read
  `app/Models/BaseModel.php:115`; a search for a global-scope registration found none.] Forgetting must
  be impossible, never merely discouraged. **How that requirement is actually met is the RLS entry
  above** — which supersedes this entry's original prescription of a default-on Doctrine filter, because
  a filter is bypassed by native queries, migrations and `psql`.
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
- **2026-07-30 — the integration suite SKIPPED the tenancy proof and reported `OK`, contradicting this very
  file. Two PostgreSQL clusters share port 5432.** The invariant in § "Quality gate" — *"with no database
  reachable the integration suite fails rather than passing"* — was prose; both connection helpers called
  `markTestSkipped()` on any `PDOException`. This container runs clusters **16 and 18 both configured on 5432**
  [Verified: `pg_lsclusters`], so after a restart the one *without* the tenancy roles won the port, every
  connection got `FATAL: password authentication failed for user "twes"`, all 62 integration tests skipped, and
  the run reported `OK, but some tests were skipped!` with **exit 0**. The proof standing between this product
  and a reportable cross-tenant breach did not execute, and I found it by accident while reading an assertion
  count — not from the panel, and not from the exit code. Both helpers now `fail()` with a message naming the
  two-cluster trap, since `password authentication failed` otherwise sends a reader hunting for a wrong password
  that is correct. [Verified: a deliberately wrong password now gives `Tests: 62, Failures: 62` and exit 1,
  where the same input previously gave exit 0.] Fourth instance of the shape this section already records three
  times: **a control that silently does not run is worse than one that is openly owed** — and the first where
  the contradiction was between this file's own words and the code they described. Start the right cluster with
  `pg_ctlcluster 16 main stop && pg_ctlcluster 18 main start`.
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
- **2026-07-30 — PostgreSQL does not persist in this container, and that now FAILS the suite rather than
  skipping it.** The server goes down between commands, and three concurrent reviewer agents each running
  `pg_ctlcluster` made it worse — a self-inflicted hazard worth knowing before telling several agents to manage
  clusters at once. Two consequences of the skip-to-fail fix above, both intended: start the server before the
  API gate every time (`pg_ctlcluster 16 main stop && pg_ctlcluster 18 main start`), and read a red integration
  suite in a fresh session as "the database is down" rather than "the tenancy logic broke" — the failure message
  now says which.
- **2026-07-30 — this container's `LANG` makes Flutter Web render a BLANK PAGE under Playwright.** Headless
  Chromium reports `navigator.language` as `en-US@posix` here, which Flutter's locale parser rejects with
  `RangeError: Incorrect locale information provided` — no failing test anywhere, just an empty screenshot.
  A harness artefact, not an app defect [Verified: the same build renders with
  `newContext({locale: 'en-US'})` and crashes without it]. Always set a locale explicitly when driving the
  Flutter web build, and read a blank Flutter screenshot as "check `pageerror` first", not "the app is fine".
- **2026-07-31 — a document number sequence is GAPLESS, so `nextval()` is FORBIDDEN. Recorded beside
  money-is-never-a-float because it is the same kind of decision: unfixable once data exists.** A PostgreSQL
  `SEQUENCE` is *deliberately* non-transactional — it does not roll back — so every failed or rolled-back issue
  burns its number and leaves a permanent hole in the sequence. That is exactly right for a surrogate primary key
  and disqualifying for a legal document number: a missing invoice number is what a tax authority reads as a
  suppressed sale, and France and Tunisia both audit for it. `SERIAL`, `IDENTITY` and any `CACHE n` are ruled out
  by the same objection. The shape that satisfies the contract is a per-`(tenant, type)` counter **row** taken
  under `SELECT ... FOR UPDATE` inside the same transaction that persists the document. Accepted cost: issues for
  one `(tenant, type)` serialise — two invoices sharing a number is worse than a queued request. The contract is
  a **test class** (`DocumentNumberSequenceContract`) that every adapter must extend, not a docblock, because
  this file already records five times that a rule enforced by memory is not a rule.
- **2026-07-31 — tenancy is AMBIENT CONTEXT, not a field, and the reductio is how you tell.** Round 13 found
  that `DocumentNumber` leaves the TENANT separable — tenant A's Invoice 41 equals tenant B's — and prescribed
  moving `TenantId` into `Domain/` so the value object could carry it. Round 14 reversed that: the prescription
  contradicted `TenantId`'s own standing invariant (which calls a `company_id` in `Domain/` a P0), it would have
  ended the database-per-tenant mode the `TenantIsolationStrategy` seam exists to allow — under that mode the
  tenant *is* the connection and there is no column to carry — and, decisively, **it does not stop at
  `DocumentNumber`**: if a tenant must sit inside a value object for its equality to be safe in a cross-tenant
  collection, it must equally sit inside `Invoice`, `DocumentLine` and `Money`. A field that every type needs is
  not a field. The remedy is a **boundary** rule — *no tenant-less path may hydrate a domain aggregate* — which
  is strictly stronger, because it also stops the cross-tenant total, PDF and export that value-object equality
  would never have caught. Two general lessons: **when a fix must be applied to every type in a layer, the thing
  being modelled is context rather than data**; and a findings-closure note is exactly where a contradiction with
  a standing invariant slips in, because the author is reading the new finding rather than grepping for the old
  ruling — this file records that shape three times already and this was the fourth.

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

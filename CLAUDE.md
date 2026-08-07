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

Status: **Wave 0 landed, not yet certified; Wave 1's domain, Symfony application, Doctrine mapping, first
migration and schema gate all landed** (2026-08-01), and **its persistence closed out on 2026-08-06** — the
rendered-number column, the Doctrine repository with its port, the savepoint tenant-binding guard, and the boundary
rule that no tenant-less path may hydrate an aggregate. Wave 1's document kernel, lifecycle, numbering and `Invoice`
aggregate are under `api/src/Domain/Document/`, framework-free. **Persistence is no longer blocked** — the
twenty-round claim that it was, and why that diagnosis was wrong in kind, is in § Gotchas. **The invoice WRITE path
closed out on 2026-08-07**, which was the last thing Wave 1 owed: `POST /api/invoices` creates a draft and
`POST /api/invoices/{id}/issue` issues it — two single-purpose operations rather than one `issue: true` flag, so an
irreversible act is not reachable two ways. With it came the first `Application/` code (`CreateInvoiceHandler`,
`IssueInvoiceHandler`, and a `TransactionalScope` port whose adapter is the only thing that opens a transaction), the
**Postgres gapless counter** and the boundary the plan named a P0: an application handler never mints a
`DocumentNumber`, it consumes one from `DocumentNumberAllocator`. The tenancy seam and the READ path landed 2026-08-06
(`TenantResolver` port, `RequestTenantBinder` on `kernel.request`, a development-only header adapter refused in
production by a gate, and `GET /api/invoices/{id}` through `InvoiceProvider`). **A create response is the document
READ BACK inside the write transaction, not the aggregate just built** — `NUMERIC(21,6)` returns `2.000000` for a
stored `2`, so returning the aggregate would make `POST` and a later `GET` disagree byte-for-byte on one document.
**Every monetary and quantity field is a decimal STRING on the wire**, never a JSON number, in both directions: the
input DTOs declare `string` and a JSON number is answered with a 422 naming `lines[0].unitNet`, which needed
`COLLECT_DENORMALIZATION_ERRORS` on the operation — without it the answer was an opaque 400. A
functional test asserts the encoded payload rather than the PHP type — the type declaration is the enforcement, so
asserting it again is a dead assertion PHPStan refuses. The connection-lifecycle wiring closed out on 2026-08-06 (`SessionStateReleaser` for release,
`ConnectionProvisioningGuardMiddleware` for acquisition, the latter cached once per (role, database) per TTL window
against a measured ~10.8 ms per connection). Note the repository writes with DBAL rather than through the UnitOfWork, and § Gotchas 2026-08-06
records the measured reason — a whole-rewrite through the identity map is impossible, not merely slow.
`docs/plans/build-waves.plan.md` § Wave 1 is authoritative.

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

`api/` also now holds the **Symfony application** — `src/Kernel.php`, `bin/console`, `config/**`, `public/` and
`.env` — plus Doctrine ORM, DBAL and Migrations, the attribute-mapped persistence model under
`src/Infrastructure/Persistence/Doctrine/Entity/`, and `migrations/Version20260801120000.php`, the first
migration. `Kernel` and `bin/console` are **hand-written rather than Flex-generated**, because `symfony/flex` is a
Composer plugin this container's configuration disables. **The clause that used to follow — *"which is also why
`bin/console` uses the classic bootstrap instead of `vendor/autoload_runtime.php`"* — was false**, and
§ Gotchas 2026-08-02 records the correction: `autoload_runtime.php` was there the whole time, `composer
dump-autoload` regenerates it, and `symfony/runtime` is allow-listed. Both `bin/console` and
`public/index.php` require it today, which is what makes `APP_RUNTIME` — and therefore FrankenPHP worker mode
— **technically reachable, and then DELIBERATELY REFUSED**: `scripts/gates/worker-mode-blocked.sh` blocks worker
mode across every tracked file, and `scripts/gates/compose-config.sh` across the rendered compose configuration,
until the Wave 10 portal token exists (§ Gotchas, 2026-08-05). Reachable and permitted are
different things, and this sentence said only the first for a commit. That correction sat 800 lines below this
sentence for three days — and then this sentence named `compose-config.sh` as the enforcer for two more commits
after the text routes had moved out of it, which is the same defect one level down.

**Not yet built: a wired `deptrac`, and that is now the ONLY entry in this list.** It read *"the Doctrine repository
… and a wired `deptrac`"* for a commit after `DoctrineInvoiceRepository` landed on 2026-08-06 — this file's signature
defect one more time, in the paragraph whose whole job is to say what is absent. **The `infra/` tier LANDED** — three Dockerfiles, three compose files, an entrypoint, a Caddyfile, a database
init script and its own gate — and both the development and the production stack have now been run end to end
(2026-08-05). This sentence listed `infra/` as absent for three commits after it existed, and a reviewer's charter
cites it. **PHPStan RUNS, from a pinned phar, and `composer gate:static` is wired to it** (2026-08-05) — this
paragraph called it *"the one thing still genuinely uninstallable"* until then. **`phpstan/phpstan` is not in
`composer.lock` at all** — it entered only as a dependency of `deptrac` and left with it on 2026-08-02 — so no
locked package lacks a `source` URL, `--no-dev` is no longer required, and the phar is used because it is pinned
by SHA-256 and runs with no vendor tree, not because Composer cannot fetch it. **This paragraph said the opposite
in the present tense for a commit AFTER § "Quality gate" corrected it 520 lines below**, in a correction whose own
text claims it was made *"here rather than below it"* — the § Gotchas 2026-07-29 shape, committed by the sweep that
cites it. [Verified: 74 + 46 locked packages, 0 without source; `phpstan/phpstan in lock? NO`.] Everything
installs — see § Gotchas, which corrects the *"GitHub egress is restricted"* claim this paragraph carried until
2026-08-01. **`deptrac` is the only tool still owed.**
Anything below describing what does not yet exist is the *target*. Read `docs/plans/build-waves.plan.md` for
where the build actually is.

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

## The Symfony ecosystem is the ONLY vocabulary — never a Laravel/Eloquent pattern

**Developer ruling, 2026-07-31: if something is specific to Laravel or Eloquent, find and use its
Symfony / Doctrine / API Platform equivalent. Never transliterate the Laravel mechanism.**

This is not a restatement of the licensing invariants and it binds even where copyright does not. Invariant 1
forbids upstream *code*; this rule forbids upstream *shape*. We legitimately learn behaviour from Invoice Ninja
(invariant 2), and that behaviour is expressed in Laravel idiom — so every time a behaviour is understood, the
question is **"what is the Symfony-ecosystem way to do this?"**, never "how do I write this Eloquent thing in
PHP". A transliterated Laravel pattern is how a Symfony codebase ends up fighting its own framework, and it is
also how a clean-room build starts to *look* like a port even when no line was copied.

The mapping, for the patterns this product actually meets:

| Laravel / Eloquent | What we use instead | Why it is not a straight swap |
|---|---|---|
| Eloquent Active Record (`Model` with persistence on the entity) | **Doctrine ORM, data-mapper**: a framework-free entity plus XML mapping under `Infrastructure/` | This is the whole reason `Domain/` can be pure. An Active Record entity cannot satisfy § Architecture's first rule |
| Global/local **Eloquent scopes** for tenancy | **PostgreSQL row-level security** first, a Doctrine filter second | Ruled already — see § Gotchas. A scope is opt-in per query and a filter is bypassed by native SQL; RLS is applied by the server to every statement |
| **Laravel migrations** (`Schema::` builder) | **Doctrine Migrations** + `scripts/gates/schema-tenancy.php` | The gate is ours regardless: no migration framework enforces the RLS statements a tenant-owned table needs. **Both halves landed 2026-08-01** — `api/migrations/Version20260801120000.php` is hand-written rather than `diff`-generated for exactly this reason, and it takes its policy SQL from `policySqlFor()` so the migration and the checker cannot disagree. This cell read *"does not exist yet"* until then, which was accurate when written |
| **Eloquent pagination** (`paginate()`, `per_page`) | **API Platform's pagination extension** | Already ruled by omission: § "The API contract is ours to design" rejects upstream's `per_page=999999` outright, and pagination is a named contract deliverable in `reimplementation-strategy.plan.md`. A hand-rolled limit/offset on each endpoint is how that ends up inconsistent across resources |
| **Policies / Gates** (`Gate::allows`, `authorize()`) | **Symfony Voters** over a real `role_permissions` table | Ruled already, in `reimplementation-strategy.plan.md` and `build-waves.plan.md`: permissions are DATA, not a closure per ability, because an administrator must be able to change them without a deploy |
| **Laravel throttling** (`throttle` middleware) | **`symfony/rate-limiter`** | Wave 10 commits to rate limiting; naming it here stops the middleware shape being reinvented |
| **Form Requests** / `Validator` facade | **Symfony Validator** on DTOs at the UI boundary — and the domain still refuses invalid state itself | Validation at the edge is a message-quality feature; the invariant lives in the value object. Both, not either |
| **API Resources** / `toArray()` | **API Platform** resources + Symfony Serializer | § "The API contract is ours to design" — the contract is declared, not assembled per endpoint |
| **Eloquent events / observers** | **Doctrine events** for persistence concerns, **domain events** for business ones | Do not put business meaning in a persistence hook; that is how tax logic ends up firing on a flush |
| **Facades** (`DB::`, `Cache::`, `Mail::`) | **Constructor-injected services**, always | A facade is ambient access, which § Architecture forbids in `Domain/` outright and discourages everywhere |
| **Jobs / Horizon** | **Symfony Messenger** | |
| **Blade** for documents | the PDF pipeline in `docs/plans/` (Wave 4) — Twig where a template engine is wanted | |
| **Artisan commands** | **Symfony Console** | |
| `Carbon` for time | our `ClockInterface` port; `DateTimeImmutable` behind it | Ambient time is a P0 in `Domain/` — see § Architecture |
| **Laravel Sanctum / Passport** | Symfony Security, decided in Wave 7 | |

**When a pattern is met that is not in this table**, the rule still applies: find the ecosystem equivalent before
writing anything, and add a row here in the same change. If no equivalent exists, that is worth saying out loud
rather than reaching for the Laravel shape by default.

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
  **banned-function rule, and `scripts/gates/no-ambient-calls-in-domain.php` IS that rule** — not PHPStan,
  which this line named until 2026-08-05 and which configures nothing of the kind (`phpstan.neon.dist` has no
  `banned`, `forbid` or `disallow` key). The distinction matters now that PHPStan runs and is green: a reader
  told the rule belongs to PHPStan would conclude a green PHPStan covers it. It does not, and the gate needs
  nothing installed, which is why it is the primary mechanism rather than the fallback.
- **No Doctrine mapping of any kind on a domain type. Persistence uses a SEPARATE MODEL in
  `Infrastructure/`, mapped with ATTRIBUTES, and a repository translates** (developer ruling, 2026-08-01,
  after challenging the previous wording). `#[ORM\` anywhere under `Domain/` is still a P0 and
  `scripts/gates/no-orm-attributes-in-domain.sh` still enforces it — what changed is where the mapping went and
  *why*.
  - **The previous wording said "mapping lives in XML (or PHP) mapping files" and was stale twice over.** ORM 3
    ships exactly two drivers, `AttributeDriver` and `XmlDriver` [Verified: `ls
    vendor/doctrine/orm/src/Mapping/Driver/`] — `PhpDriver` and `YamlDriver` are gone, so "or PHP" named a
    thing that does not exist.
  - **And its stated reason was weaker than it sounded.** Attributes do NOT couple a class to Doctrine at
    runtime: a `use` statement is compile-time text and PHP resolves an attribute class only when something
    calls `newInstance()` on it, which only Doctrine ever does. [Verified: an attribute-mapped class
    instantiated and ran with no Doctrine autoloadable at all — `class_exists(Doctrine\ORM\Mapping\Entity)`
    false, the object working, and only `newInstance()` throwing.] So "quietly becomes framework-coupled" is a
    discipline argument, not a technical one, and it should not be presented as the latter.
  - **The load-bearing reason is a different one, and it applies to attributes and XML equally.** Every domain
    type here is `final readonly` with a private constructor, and the aggregate's five mutators each
    `return new self(...)` — immutability is a correctness requirement in this project, so an issued document's
    snapshot cannot be moved. Doctrine's ORM is the opposite by construction: an identity map holding one
    MUTABLE instance per row, diffed against a snapshot to emit UPDATEs. A `readonly` property can be
    initialised once and never updated, and a mutator returning a new object hands the unit of work something
    it has never seen. **Mapping the aggregate directly is insert-only and fights the ORM, whichever driver you
    pick** — which is why the driver was the wrong argument to be having.
  - So the mapped classes are ordinary mutable Doctrine entities under
    `Infrastructure/Persistence/Doctrine/Entity/`, attributes are correct THERE (IDE support, refactoring,
    brevity, no DTD), and a repository translates to and from the aggregate. The accepted cost is a mapper per
    aggregate and a real duplication risk if one is careless — paid down by a round-trip contract test, not by
    care. That cost is the price of the immutability ruling and was always going to be paid somewhere.
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
  Infrastructure/  # Doctrine, HTTP clients, PDF, gateways, e-invoicing, the ATTRIBUTE-mapped persistence
                   # model + its mapper (this said "Doctrine mapping XML" until 2026-08-05, contradicting
                   # the ruling 100 lines above it in this same section).
  UI/              # REST controllers, CLI commands, serializers.
```

**Enforcement landed in Wave 0, and grew in Wave 1** (`schema-tenancy.php`, second from last — it could not exist
until a real migrated schema existed to read). The P0s above are no longer prose — the gates in `scripts/gates/`
check them, each proven to fail on an injected violation before being trusted (`ls scripts/gates/` is the tally; no
number is written here, because one written beside the thing it counts is the first thing to drift):

| Gate | Enforces |
|---|---|
| `layer-dependencies.php` | inward-only dependencies, **and** the domain's zero-Composer-dependency rule |
| `no-ambient-calls-in-domain.php` | no clock, randomness, environment or I/O in `Domain/` |
| `no-orm-attributes-in-domain.sh` | no `#[ORM\` or any Doctrine reference under `Domain/` |
| `spdx-headers.sh` | licensing invariant 8(c), on every source file — **and that the search roots COVER every tracked source file**, which is the direction that was missing when `api/phpunit.xml` sat unscanned with no identifier |
| `dependency-licences.php` | every dependency permissive (licensing invariant 8(a)) |
| `locale-key-parity.php` | every locale carries the same key set |
| `compose-config.sh` | every compose configuration RENDERS, and a set of security properties survives rendering — **no count is written here, because this row said "four" while listing six**, and the gate itself deleted its own count for exactly that reason: the owner credential is on `migrate` and nowhere else, the scheduler is pinned to ONE replica, nothing but the API is on the `edge` network, and `internal` really is `internal: true`. **Plus a fifth added 2026-08-02: every Messenger receiver a service consumes must be one the application actually defines**, and a sixth added 2026-08-05: **in the PRODUCTION configuration only, every service declares `cap_drop: [ALL]`.** **Plus a SEVENTH added 2026-08-05: FrankenPHP WORKER MODE is refused in the RENDERED configuration** — `APP_RUNTIME` must be the one permitted runtime and every one of the four Caddyfile seams must be EMPTY, with the seam set DERIVED from the Caddyfile's own `{$…}` placeholders rather than written here (a hand-written list held two of the four for two commits, and the two it omitted were the worst two: `{$CADDY_GLOBAL_OPTIONS}` splices into the global block that CONTAINS `frankenphp { }`, and `{$CADDY_EXTRA_CONFIG}` is where an `import` would live). EMPTY rather than *not-saying-`worker`* because a YAML block scalar carries the directive on lines the key never appears on. **The TEXT routes are NOT here** — they are `worker-mode-blocked.sh`, its own row below, and this row credited itself with them for two commits while `grep -n Caddyfile scripts/gates/compose-config.sh` matched prose only. What stays here is the half no text sweep can do: an overlay, a YAML anchor, an `env_file:`, a value assembled from two files. Both directions are needed and neither is sufficient. Eight rendered mutants, three of them added when the seam set grew from two to four.
Deleted in Wave 10 when the portal token lands, not before (§ Gotchas 2026-08-05). That one is prod-scoped on purpose rather than as an exemption — the dev overlay deliberately does not harden — and it is a FULL-SET check over whatever services the rendered config contains, because the gap that prompted it was `migrate` being missed while the six long-running services were hardened. A one-shot is short-lived, not low-privilege: `migrate` is the ONLY container holding the owner credential, the role that can `DROP POLICY` on every tenant table. Capabilities are orthogonal to `read_only` and `no-new-privileges`, both already present: Docker's default set includes `NET_RAW`, which is raw sockets on the network the database sits on. **The receiver check is not hypothetical hardening either** — `compose.yaml` ran `messenger:consume async` and `scheduler_default` while `config/packages/messenger.yaml` did not exist and no `#[AsSchedule]` provider did either, so BOTH the worker and the scheduler crash-looped on the first `docker compose up`. The receiver set is DERIVED (transport keys from `messenger.yaml`, `scheduler_<name>` from each `#[AsSchedule]`), never written down, and the attribute scan is anchored to the start of a line because the unanchored version matched the literal `#[AsSchedule('<name>')]` inside a DOCBLOCK and admitted `scheduler_<name>` to the allowed set. It needs `docker compose` but no daemon; it is the one gate here that SKIPS when the binary is absent, which is tolerated because every other gate still runs — INCLUDING `worker-mode-blocked.sh`, which covers the worker-mode routes needing no daemon. It does NOT say CI covers the rest: it said so for two commits and there is no `.github/` in this repository, so the rendered half is genuinely unchecked until someone runs it with Docker. `compose-config.sh` itself was corrected in the same diff that left this sentence standing, which is this file's signature defect one more time |
| `makefile-conventions.sh` | **the Makefile's own naming convention: a bare target acts on DEVELOPMENT, `-prod` on PRODUCTION, and no other suffix means an environment** (developer ruling, 2026-08-05). That direction rather than the reverse because of blast radius — muscle memory types the short name, so the short name must be the harmless one. It is a NAME-VERSUS-BEHAVIOUR check and derives what each target actually does (which of `$(DC)` / `$(DC_PROD)` / the target-scoped `$(DCX)` its recipe drives) rather than comparing against a list of expected names, which would drift with the Makefile. It also enforces the SCOPE axis — a bare aggregate must invoke its narrow siblings, which is what `gate` meaning "the API tier only" violated. **It failed its own subject twice while being written**: `build-front-prod` drove the DEV stack, and the `-dev` check sat behind a skip that swallowed the ORIGINAL defect it exists to catch, because that defect's target shared a recipe with a sibling and so had no detected stack. Both are pinned by mutants |
| `no-forgeable-tenancy-in-production.sh` | **`TWES_TRUST_TENANT_HEADER` is `0` in every tracked file.** `HeaderTenantResolver` trusts an `X-Tenant-Id` header verified by nothing, so enabling it on a reachable deployment lets any caller act as any tenant whose id they can produce — a cross-tenant read of every client's invoices. It exists only so Wave 1's HTTP surface could be exercised before Wave 7 writes authentication, and it is **one of three independent controls**: the resolver refuses loudly when not permitted, both committed dotenv files set `0`, and this gate refuses any other value. All three axes are INVERTED, from `worker-mode-blocked.sh`'s three defeats: scope is EXCLUSION-based over every tracked file, and an assignment must carry the single permitted value `0` rather than merely avoid `1` — which closes `true`, `on`, `yes`, `"1"` and a value assembled from two files at once. "Assignment" is itself DERIVED (only closing quotes, brackets and whitespace may sit between the knob and an `=`/`:`) rather than a literal-line comparison, because prose legitimately names the knob — and the gate's FIRST version reported **its own subject's PHP docblock** as configuration. Comment leaders are stripped ONCE into a variable every check reads, and there are THREE of them (`#`, `//`, a docblock `*`): the second and third were added because the meta-suite caught a `//` comment whose knob was followed by a colon. Needs nothing installed, so it can never skip. **Deleted in Wave 7** with the resolver, the variable and its cases — not before, or an authenticated resolver would ship beside a forgeable path to the same privilege |
| `worker-mode-blocked.sh` | **FrankenPHP worker mode is refused across EVERY TRACKED FILE**, and all three of its axes are INVERTED because all three were defeated in their enumerating form. **Scope** is exclusion-based — everything tracked is in scope unless it matches a short list (`*.md`, `docs/`, `tests/`, `.claude/`, the gates themselves) — because an inclusion list of path patterns is fail-OPEN for every file nobody thought of, and version 3 was beaten by `api/.env.prod` (the **committed** half of Symfony's own dotenv cascade, which `infra/.env`'s `APP_ENV=prod` makes the live file, invisible to a pattern anchored `\.env$`), `infra/api/Dockerfile.dev`, and `api/composer.json`'s `extra.runtime.class` — which `symfony/runtime` BAKES into `vendor/autoload_runtime.php`, so it selects a runtime with no environment variable anywhere. **The knob set** is DERIVED from the Caddyfile's own `{$…}` placeholders, minus the one that is a hostname, because a hand-written list let a FIFTH seam through. **The value rule** compares the text from a keyword onwards against committed literals — NOT a "normalised line", which is what round 30's rewrite REMOVED as the shared cause of three P0s (*a transformation set is an enumeration too*) — and requires every seam to be **EMPTY**. Three axes this row omitted: the `SERVER_NAME` STRUCTURAL rule (no brace outside a `${…}` interpolation, closing a reproduced Caddyfile-restructuring injection), the UNBALANCED-QUOTE refusal, and unreadable-file-is-a-violation — that is what makes a YAML block scalar, the legacy `ENV KEY value` form (no `=` in it at all), a quoted `"APP_RUNTIME":` key and a `\`-split assignment fail *at once*, because the gate stopped asking what a value MEANS. **The four seams are KEPT rather than deleted**: they are FrankenPHP's own image's variable names, so removing them would invent a project-specific spelling of a conventional thing — the marker § Gotchas 2026-08-02 gives for first-principles wiring — and requiring them empty closes the route while leaving the convention and the Wave 10 capability intact. It needs no Docker, no rendering and no daemon, which is why it can never skip; `compose-config.sh` covers the rendered half, which sees composition this cannot. The refusal and false-positive route counts are NOT written here — derive them from the `for wm_route in` list in `scripts/gates/test-gates.sh`, splitting on the `OK-` prefix. "Sixteen" was written at three sites while the list held eighteen, because a route was added after the prose. The false-positive half is what keeps the gate usable: the documented worker block is a COMMENT on purpose, a dotenv inline comment is legal, a CRLF file must not produce a message that renders identically to the permitted value, `api/public/index.php` carries the forbidden value verbatim in a PHP docblock, and three more exist because comment text was read as configuration — one message asserted ``APP_RUNTIME is "prod"`` about a line that set a different variable entirely. Comment handling is therefore decided ONCE into a variable every check reads, per § Gotchas 2026-07-29. Deleted in Wave 10 when the portal token lands, not before |
| `shell-syntax.sh` | every tracked shell script parses — **including the other gates**, which is why it is here and not in Wave 12 with `infra/` |
| `no-orphaned-docblocks.php` | no doc comment that attaches to no declaration — two `T_DOC_COMMENT`s with nothing but whitespace, a comment or an attribute between them, because PHP attaches only the LATER one and the first then documents nothing. Added at round 17 after **three** successive rounds filed a stranded doc comment and round 16's own fix created a fresh one. **Rewritten as a tokenizer pass at round 18**, which found the original line-pattern version missing four of five genuine shapes — a blank line between the blocks (the most natural way to author them), an attribute between them, and a single-line second block — and found its comment asserting that a closing and opening delimiter on ONE line "is not this defect", which was false. A positional rule enforces one SPELLING; the defect is a question about tokens. Nothing else can see it: `php -l` treats comments as comments, `php-cs-fixer` reported `0 of 69 fixable` over a tree carrying seven, and PHPStan would catch only the subset that also loses a `@param`/`@return` generic |
| `no-owner-connection-in-application.php` | no code under `api/src/` names the **owner** DBAL connection. `doctrine.yaml` calls the default/owner split a security boundary; round 21 showed it was not one — `debug:autowiring` offers `#[Target('owner')]`, so one line of ordinary application code (the classic "fix the permission error" edit) yields the role that OWNS the tenant tables and can `DROP POLICY`, which `FORCE` does not prevent. A reviewer booted the kernel and disabled row security on `document` in one statement. Chosen over stripping the autowiring alias, which closes the attribute and leaves `$doctrine->getConnection('owner')` open — the connection must stay in the registry for the migrations bundle to resolve it. `PERMITTED_PATHS` is deliberately EMPTY: nothing in `src/` needs it |
| `schema-tenancy.php` | every tenant-owned table in a **real migrated schema** is RLS-enabled, `FORCE`d, canonically policed on **both** halves (`USING` and `WITH CHECK`), `NOT NULL` on its tenant column, and not `TRUNCATE`-able by the runtime role, and **every PRIMARY KEY / UNIQUE / unique INDEX / EXCLUDE constraint / FOREIGN KEY on it includes the tenant column** — uniqueness, exclusion and FK checks run with row security BYPASSED, so a key omitting the tenant is enforced across every tenant, which is an existence oracle and, for a FK with `ON DELETE CASCADE`, a cross-tenant delete. **That key axis was DELETED on 2026-08-01 and RESTORED on 2026-08-02**, and the round trip is the most useful thing in this row: it was removed on the claim that `BehaviouralIsolationTest` covered it, and two certification lenses independently reproduced a cross-tenant oracle without it. The reason is structural rather than a bug — **a probe's reach is bounded by its fixture's value space**, so `UNIQUE (number) WHERE deleted_at IS NULL` (a soft-delete partial unique, the most ordinary shape there is) is invisible to it in BOTH directions because `rowFor()` fills every column, as are predicates over `'cancelled'` or `'credit'` and any range outside the synthesiser's `{1,2}`. An `EXCLUDE ... WHERE` was caught by NO half at ANY commit. **So key shapes are a CATALOGUE property, not an attackable one — the same side of the line as `rolreplication`.** All four of round 22's P0s in this axis are now fixed rather than deleted: it reads `indnkeyatts` (an `INCLUDE` payload is not a key column), `contype = 'x'`, the relation OID rather than `?::regclass`, and `confkey` in ORDINAL order — the tenant column can be present on both sides yet MIS-PAIRED, which a membership test passes. The distinction that decides what lives where: an attack suite MUTATES data, so it can only run against a throwaway and only ever proves things about the MIGRATION's output, whereas every axis here can also DRIFT on a live schema read-only — and `rolreplication` cannot be observed by any attack at all — plus, on **every** table whether tenant-owned or not, that the runtime role does not **own** it. That last one is deliberately unscoped: a non-tenant table owned by the runtime role leaks nothing itself, but it proves migrations are running as that role, so the next tenant-owned table they create is one `ALTER TABLE … DISABLE ROW LEVEL SECURITY` from every tenant's data. Not hypothetical — `doctrine_migration_versions` in the local dev database was exactly this on 2026-08-01, and the scoped version of the check skipped it. Its subject set is **every non-system schema** and includes relkinds `m` and `f` in order to REFUSE them, since a materialized view or foreign table holding tenant data cannot carry a policy at all — both were reproduced leaks while a `public`-only, `('r','p')`-only scope reported OK. **The only gate that needs a database, and the only one that can see an unpoliced tenant table at all**: `assertPolicedTablesAreBeyondThisRolesReach()` derives its subject set from tables that already HAVE row security, so a table missing it is invisible to the runtime check *by construction* (round 7 filed exactly that). It also **fails on a table it cannot classify** rather than ignoring it, because a silent skip is how the next tenant-owned table arrives unpoliced — `customer_id`, `client_id` and `account_id` are deliberately NOT treated as tenant columns, since each names a row's *subject* rather than its owner. Its clean-fixture and violation cases live in `api/tests/Integration/Tenancy/SchemaTenancyGateTest.php` (the database is there, not in the meta-suite) and `test-gates.sh` verifies that redirect points at a test that really exists |
| `BehaviouralIsolationTest` (a TEST, not a gate — listed here because it is the other half of tenancy enforcement) | tenant isolation **proven by attack**, as defence in depth on top of the row above rather than instead of it. Two tenants with real rows, and eight attacker goals attempted as the restricted runtime role against every relation discovery finds: read, write into another tenant, modify, re-parent, delete, `TRUNCATE`, probe by uniqueness collision, reference another tenant's row. Plus `FORCE` attacked as the owning role, `SET ROLE` escalation into every reachable role — probing read, `DELETE` and both `TRUNCATE` forms on every relation, because a grant made `WITH INHERIT FALSE` is reachable only that way and invisible to any check resolved by inheritance — and the unbound-session read, which pins the `NULLIF` in the canonical policy. **Only GOAL 1 applies to a view, materialized view or foreign table**, which cannot be written to. Its uniqueness probe runs in BOTH directions (A's values under B and B's under A) because a PARTIAL index's predicate is evaluated on exactly the columns the two tenants differ in — a single direction missed `UNIQUE (number) WHERE state = 'issued'`. Every goal is proven load-bearing by a mutant except GOAL 4, which **cannot be isolated**: with a SELECT policy present, PostgreSQL requires an UPDATE's new row to satisfy that policy's `USING` clause too, so the canonical `USING` half acts as a second `WITH CHECK` and re-parenting cannot be broken without also breaking GOAL 1 | **Runs** (landed 2026-08-01). In the `integration` suite, not `scripts/gates/`, because it needs the full role topology `provision-test-database.sh` builds — and because it MUTATES data, which is why it can never replace the row above: an attack suite can only run against a throwaway database, so only the gate can check a live one |

The two layer gates are **separate on purpose, and merging them would be a mistake**: a framework
dependency arrives as a `use` statement and an import check finds it, but `time()`, `random_int()`,
`getenv()` and `file_get_contents()` are bare function calls with **no import at all** — an import check
is blind to every one of them. Both use PHP's own tokenizer rather than `grep`, so a `use` inside a
comment or a string is not a false positive.

**Still owed, and deliberately not deleted from this table: `deptrac` ALONE.** **PHPStan landed 2026-08-05** —
`api/phpstan.neon.dist` (level 6 over `src/` and `tests/`), `gate:static` pointed at the pinned phar, and the 49
findings it produced fixed rather than baselined. Four were real defects rather than annotation noise, and one of
them is why this row is worth reading: `treatPhpDocTypesAsCertain` reported a LIVE row-level-security guard in
`PostgresRowLevelSecurityIsolation` as dead code, because the docblock declared `rolsuper: bool|string` while the
code's own comment explains that `bool_or` over an empty set is NULL. The other three were a `@throws` missing
from `Money::plus()`/`minus()` that its own constructor's comment contradicts, a stale `fks[].name` in the one
`BehaviouralIsolationTest` docblock that READS `$fk['name']`, and a half-hollow assertion in the `SET ROLE`
escalation proof whose `escalated:` list was provably always empty. `deptrac` is installable and only waits on
someone wiring it up. This sentence claimed until 2026-08-01 that NEITHER could be installed and that the cause
was network egress; both halves were wrong — see § Gotchas. Both are defence in depth on top of the gates above,
not a substitute for them —
the architecture gates run on plain PHP and need nothing installed. **Two of the gates are exceptions to that**, and
both fail rather than skip when they cannot look, which is the whole reason they are exceptions:
`gate:licences` needs a populated pub cache (`flutter pub get`) for its Flutter walk, and `gate:schema` needs a
migrated PostgreSQL database, because a schema is not readable from source. See § "Quality gate".

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
| **Architecture fitness** | the gates in `scripts/gates/` — `ls` that directory for the tally; see § "Architecture" for the table and why two of them are separate — **plus `scripts/gates/test-gates.sh`, which tests the gates.** A gate that cannot fail is a false assurance: round 2 proved that suite was too weak and round 3 proved it again, so it was strengthened twice. It now asserts each gate's own **message** rather than only its exit code, and — because hand-picked cases pin the fixture's instances rather than the rule sets — every gate answers `--dump-rules` and the suite **generates** one case per banned function, superglobal, instantiation, layer pair, SPDX root, extension and lock section, backed by a committed baseline that fails if any rule set shrinks, and by committed minimum rule-set SIZES, because generating a case from the data means deleting an entry deletes its own case. The suite reports its own case count; none is written here | **Runs** |
| **Licensing** | `scripts/gates/dependency-licences.php` — every dependency permissive **and present in `THIRD-PARTY-NOTICES.md`**, over `api/composer.lock` and `admin/package-lock.json`, plus **every locked pub package's own licence read out of the pub cache** (`mobile/pubspec.lock` records no licence field, so the cache is the only place they exist — a copyleft grant is vetoed even when the same file also states a permissive one, more than one match is refused as ambiguous, a non-`hosted`/non-`sdk` source is refused, and a version that is not plain semver is refused because it becomes a filesystem path), plus every **vendored font** under `mobile/assets/fonts/`, recursively: a REUSE sidecar declaring exactly one identifier, an acceptable one, **every one of the font's own `name`-table licence records corroborating it**, the licence text beside the binary *and* declared under `flutter:`→`assets:` so it ships, and — the direction that was missing — **every font path the manifest declares must have been examined**, because a forward walk says nothing about the files it never reached. A font arrives as a committed binary rather than a manifest entry, so no lock file can see it; it is not the only such asset (13 of the 37 tracked `.png`/`.ico` files are template-derived and ship too), which is why that sentence no longer says "the one" | **Runs** |
| **Schema tenancy** | `scripts/gates/schema-tenancy.php` — every tenant-owned table in a real migrated schema RLS-enabled, `FORCE`d, canonically policed on both halves, `NOT NULL` on its tenant column, and beyond the runtime role's ownership and `TRUNCATE`; a table it cannot classify is a **failure**, not a skip. See § "Architecture" for why nothing else can see an unpoliced tenant table | **Runs** (landed 2026-08-01 with the first migration, which is what it was blocking). Needs `TWES_SCHEMA_DSN` + `TWES_SCHEMA_USER`, or falls back to the integration suite's `TWES_TEST_DSN` / `TWES_TEST_DB_SUPERUSER` pair. Its clean and violation cases are in `api/tests/Integration/Tenancy/SchemaTenancyGateTest.php` because the meta-suite has no database |
| `bin/console lint:container`, `bin/console doctrine:schema:validate --skip-sync` | the service container wires, and the Doctrine mapping agrees with itself, without touching a database | **Run** (both landed with the Symfony application, 2026-08-01 — this row said "owed" until then). `--skip-sync` because the mapped persistence model is deliberately not the whole schema: the migration adds RLS, CHECK constraints and composite FKs that no mapping expresses |
| Symfony API, owed | deptrac | **REMOVED from `require-dev` 2026-08-02, and this row's old claim that it "installs with the dev deps" was false.** `deptrac/deptrac` requires `phpstan/phpstan`, which is dist-only — so deptrac dragged the one uninstallable package in with it and BLOCKED EVERY OTHER DEV DEPENDENCY, including the `symfony/browser-kit` the functional suite needs. Its phar is not at the paths PHPStan's is (the project moved org from `qossmic/`), so re-adding it needs the release asset located first. Until then `layer-dependencies.php` is the enforcement and deptrac would be defence in depth |
| Symfony API | `php tools/bin/phpstan.phar analyse` (`composer gate:static`) | **Runs, and green** (2026-08-05). Unblocked 2026-08-02 as a pinned PHAR, closing a twenty-round "cannot install" claim whose BOTH diagnoses were wrong — it was never network egress, and the VCS `repositories` remedy this row used to prescribe fails for a different non-network reason: `phpstan/phpstan` is a DISTRIBUTION repo carrying the built phar for every release in its history, so `git clone --mirror` blew Composer's 300-second process timeout [Verified: killed at 300s]. The phar is served by `raw.githubusercontent.com`, which IS reachable. **Two things were then still missing and this row's "31 errors at level 5" hid both:** there was no `phpstan.neon.dist` at all, so the tool exited `At least one path must be specified to analyse` however it was invoked; and `gate:static` pointed at `vendor/bin/phpstan`, which `--no-dev` guarantees is never installed. Configuration is **level 6** over `src/` and `tests/` with `checkUninitializedProperties`, `treatPhpDocTypesAsCertain` and `reportUnmatchedIgnoredErrors` on — stricter than the level on the three axes this project has already ruled on. Exactly **two** `ignoreErrors` entries, each with its reason in the file: `property.uninitialized` across `tests/` (a PHPUnit class has no constructor to assign in — `setUp()` is the framework's mechanism and a connection built in a constructor would outlive each test's transaction), and one count-pinned `staticMethod.alreadyNarrowedType` for the `assertNotInstanceOf(\DomainException::class, …)` that keeps `NumberTypeMismatch` a `\LogicException`. Nothing else is suppressed |
| Angular admin | `npm run lint`, `npm test -- --no-watch`, `npm run build` | **Runs** (scaffolded 2026-07-29; Vitest + jsdom, so no browser needed) |
| Angular admin, owed | `axe-core` a11y, locale key-parity over `admin/src/locale`, the shared pricing vectors | Wave 8 — `admin/README.md` lists it as gate conditions |
| Flutter client | `flutter analyze`, **`flutter build web --release --no-web-resources-cdn`, then `flutter test`** — in that ORDER | **Runs** (scaffolded 2026-07-29, all six platform directories present). The order is load-bearing, not stylistic: two tests read `build/web` to prove the bundle reaches no external origin, and with `test` before `build` they **skip** and the command still exits 0 with "All tests passed!". A GDPR control that silently does not run is worse than one that is owed. |
| Flutter client, owed | semantics/a11y tests, golden or real screenshots at a **desktop** window size as well as a phone one, the shared pricing vectors, **a build of all six targets** (Android, iOS, Linux, Windows, macOS, Web — not cross-compilable, so three CI runners; matrix in `build-waves.plan.md` § Wave 12), and a real bundle identifier | Wave 11 — `mobile/README.md` |
| **Makefile conventions** | `scripts/gates/makefile-conventions.sh` — a bare target acts on dev, `-prod` on production, no other suffix means an environment; and a bare aggregate must invoke its narrow siblings | **Runs** (added 2026-08-05 after the developer found the Makefile using the suffix for TWO different questions: `up`/`up-prod` marked the STACK while `build-front`/`build-front-dev` marked the ARTEFACT FLAVOUR, so a bare name meant "dev" in one family and "prod" in the other — and `build-front` was both at once) |
| **Shell syntax** | `scripts/gates/shell-syntax.sh` — `bash -n` over every tracked shell script, discovered from `git ls-files` plus a shebang sweep rather than a written list | **Runs** (added at round 11, which pointed out that ten scripts already existed and already passed, while this row deferred the check to the wave that would add *more* — so the ones that existed went unchecked, and a syntax error in a gate is the worst place for one: the gate stops detecting and its non-zero exit reads as a detection) |
| **Worker mode blocked** | `scripts/gates/worker-mode-blocked.sh` — no tracked file may select a runtime other than `SymfonyRuntime`, declare a non-empty Caddyfile seam, carry an active `worker` or `import` directive in a Caddyfile, or carry ANY `extra.runtime` key in ANY tracked `.json` (not just `class`, and not just a file NAMED `composer.json` — `symfony/runtime` bakes every other key into the generated bootstrap, and `ENV COMPOSER=<file>` makes any file the root package). Scope is EXCLUSION-based and the seam set is DERIVED, for the reasons in § Architecture | **Runs** (added 2026-08-05). It needs nothing installed — no Docker, no vendor tree — so unlike its sibling it can never skip, which is the whole reason it is a separate gate. **This table had no row for it for two commits while § Architecture had one**, which is the direction that keeps going wrong here: a gate is easier to add than to finish adding. Deleted in Wave 10 |
| **Served surface (`e2e`)** | `composer gate:e2e` with `TWES_E2E_BASE_URL` pointing at a running stack — the CSP, the asset file server, the catch-all, the site-wide headers, and that no field is sent twice | **Runs** (added 2026-08-07). **NOT in `composer gate`, and that is the point rather than an omission**: it FAILS rather than skipping when no server is reachable, which is the same call the integration suite makes, so wiring it into the chain would have made every gate run require a built image and a live stack. `gate:test` is `phpunit --exclude-testsuite e2e`; `gate:e2e` is the named, owed step. It found a real defect on its first run — see § Gotchas 2026-08-07 |
| Infra | `scripts/gates/compose-config.sh` — every configuration renders AND holds its security properties | **Runs**; it is in `composer gate` and has its own row in § Architecture, which enumerates the properties — no count is written here, because this row said "Wave 12 — owed" while the gate was already enforcing six, and a seventh landed 2026-08-05 |

**The one command to run the API tier's gate is `composer gate`**, and it works today — it chains
`gate:licences`, `gate:architecture`, `gate:schema`, `gate:static`, `gate:style`, `gate:mapping` and
`gate:test`. **`gate:e2e` is NOT in that chain** and is run separately against a live stack; `gate:test` excludes the
`e2e` suite EXPLICITLY (`--exclude-testsuite e2e`) rather than by listing the other three, so a suite added later is
included by default rather than silently unrun. It needs `COMPOSER_ALLOW_SUPERUSER=1` to run at all as root here, and **`gate:schema` — THIRD in the chain — was for three commits the one step no documented environment made pass**, which is fixed here by writing the invocation down rather than describing it: the `TWES_TEST_DSN` fallback below names PHPUnit `<env>` entries that are invisible to a shell and point at an UNMIGRATED database, so only `TWES_SCHEMA_DSN` against a migrated database works, and the DSN must carry `user=` and `password=` itself (`TWES_SCHEMA_USER` names the role the gate ASKS ABOUT, not the one it connects as). **The whole chain green, verbatim** — the local dev credentials are the throwaway ones already committed in `api/.env`, and the shape is what matters rather than the values:

```
pg_ctlcluster 16 main stop && pg_ctlcluster 18 main start   # two clusters share 5432; see § Gotchas
cd api && COMPOSER_ALLOW_SUPERUSER=1 \
  TWES_SCHEMA_DSN="pgsql:host=127.0.0.1;port=5432;dbname=twes_in;user=twes_owner;password=twes_owner" \
  TWES_SCHEMA_USER=twes composer gate
```

The DSN connects as the **owner** because reading `pg_policy`, `pg_class.relowner` and `pg_authid` needs a
role that can see them, while `TWES_SCHEMA_USER=twes` names the **runtime** role every ownership and
`TRUNCATE` assertion is made ABOUT. Getting those two backwards is the mistake the parameter names invite.
Round 22 filed the missing invocation; the sentence before it claimed `gate:static` was the only failing step, one commit after the same claim was filed as false. **`gate:static` now passes** — see its row above. **Two of seven steps also failed for a different reason for one commit:**
`gate:style` and `gate:test` pointed at `vendor/bin/php-cs-fixer` and `vendor/bin/phpunit` and exited 127 while
this table called the tier green. They now run the pinned phars in `api/tools/bin/`, which is what the tier was
always actually verified with. **The REASON this row gave for that was false when it was written and is corrected
here rather than below it** (round 2 of the PHPStan certification): it said *"`phpstan/phpstan` forces `--no-dev`,
so no dev binary is ever installed"*. `phpstan/phpstan` LEFT `composer.lock` on 2026-08-02 with `deptrac`, which
is the only thing that ever dragged it in — so `--no-dev` has not been required since, every one of the 120 locked
packages carries a `source` URL, and `vendor/bin/phpunit` and `vendor/bin/php-cs-fixer` both exist in a normal
checkout. [Verified: a script over the lock reports `packages: 74, 0 without source; packages-dev: 46, 0 without
source`, `phpstan/phpstan in lock? NO`; `composer install --prefer-source --dry-run` reports *"Installing
dependencies from lock file (including require-dev) … Nothing to install"*; `ls api/vendor/bin` lists both.] The
phars stay anyway, and for a better reason than the false one: a pinned SHA-256 phar is reproducible and runs in a
checkout with NO vendor tree at all, which is what makes `gate:architecture` and `gate:style` independent of a
successful `composer install`. Derive the chain from `api/composer.json` rather than from this sentence. **`gate:architecture` needs nothing installed at all**; two of
its siblings do, and both FAIL rather than skip when they cannot look:

- **`gate:licences`** needs a populated pub cache — `cd mobile && flutter pub get`, or `PUB_CACHE` pointing at
  one (developer ruling, 2026-07-30). It prints a `counts —` line unconditionally so the meta-suite's
  anti-vacuity probes still work on a PHP-only checkout.
- **`gate:schema`** needs a migrated PostgreSQL database, because a schema cannot be read from source. It
  prints its own `counts —` line for the same reason, and refuses a run where `tenant_owned` is 0.

Both couplings are the accepted cost of not letting a check pass quietly on nothing.

```
bash  scripts/gates/shell-syntax.sh
bash  scripts/gates/compose-config.sh   # needs `docker compose`; SKIPS without it
bash  scripts/gates/worker-mode-blocked.sh # needs NOTHING, and so never skips -- that is the point of it
bash  scripts/gates/makefile-conventions.sh
bash  scripts/gates/no-orm-attributes-in-domain.sh
php   scripts/gates/layer-dependencies.php
php   scripts/gates/no-ambient-calls-in-domain.php
bash  scripts/gates/spdx-headers.sh
php   scripts/gates/no-orphaned-docblocks.php
php   scripts/gates/no-owner-connection-in-application.php
php   scripts/gates/locale-key-parity.php
php   scripts/gates/dependency-licences.php
php   scripts/gates/schema-tenancy.php     # needs a migrated database; see the table above for the env vars
bash  scripts/gates/test-gates.sh          # LAST, and the gates' OWN tests -- see § Gotchas on why this one matters
cd api && php tools/bin/phpunit-12.phar && php tools/bin/phpstan.phar analyse --no-progress \
       && php tools/bin/php-cs-fixer.phar check && composer validate \
       && php bin/console lint:container && php bin/console doctrine:schema:validate --skip-sync
```

**Derive this block from `api/composer.json` rather than trusting it.** It listed twelve of the fourteen for a
commit — `worker-mode-blocked.sh` and `makefile-conventions.sh` were absent while both had rows in the tables above
— which is the hand-written-list defect this file records against every other enumeration in it. `ls scripts/gates/` is the inventory EXCEPT for `lib/`, which holds LIBRARIES rather than gates — they have no CLI, no exit code and no `--dump-rules`, and `test-gates.sh` asserts instead that every one of them is sourced, required or executed by a gate (comment lines deleted first, because a mention is not a reference). Every actual gate is wired into `composer gate`; it
runs LAST there for a reason worth knowing, because a Composer script chain stops at the first failure, so with the
meta-suite in the middle the three gates after it never executed on any run where it was red — and it was red at
the commit that added `worker-mode-blocked.sh`, so that gate had never once run under `composer gate`.

**Never chain a verification step onto `git commit` through a pipe.** `phpunit … | tail && git commit` commits
on red, because a pipeline's exit status is the last command's — see § Gotchas, 2026-08-01. Run each step and
read its own status.

**Tooling setup in a fresh container** (nothing here is installed by default):

| Tier | How | Reachable? |
|---|---|---|
| PHP 8.5.8, PostgreSQL 18.4 | sury.org and PGDG apt repositories | yes |
| PHPUnit, php-cs-fixer | `bash scripts/dev/fetch-tools.sh` — official phars, pinned SHA-256 | yes (`phar.phpunit.de`, `cs.symfony.com`) |
| PostgreSQL roles for the tenancy proof | `sudo -u postgres env TWES_TEST_DB_SUPERUSER_PASSWORD=postgres bash scripts/dev/provision-test-database.sh` | n/a |
| **The DEV database (`twes_in`)** | `sudo -u postgres bash scripts/dev/provision-dev-database.sh` — needs no environment at all, and its defaults match `api/.env`. **Run it before the first `doctrine:migrations:migrate`**: it is what makes `twes_in` owned by `twes_owner` rather than by the runtime role, and a database owned by the runtime role hands it `CREATE` on `public` through `pg_database_owner` with no grant anywhere to find. Two roles only — never the test script's twelve, because a `BYPASSRLS` fixture has no business on a developer's own cluster. Re-runnable, and it never overwrites an existing role's password, so it cannot clobber the test fixture | n/a |
| Node 26.5.0 | tarball from `nodejs.org/dist`, verified against the published `SHASUMS256.txt` | yes |
| Angular CLI 22.0.9 | `npm install -g @angular/cli@22.0.9` | yes (`registry.npmjs.org`) |
| Flutter 3.44.8 | `flutter_linux_3.44.8-stable.tar.xz` from `storage.googleapis.com` | yes |
| **Composer dependencies** | `composer config -g use-github-api false && composer config -g github-protocols https`, then `cd api && composer install --prefer-source` | **YES** — see § Gotchas. The plain `composer install` fails; `--prefer-source` clones instead of fetching zipballs, which is the half I got wrong until 2026-08-01. **`--no-dev` and the `composer dump-autoload --dev` that compensated for it were BOTH dropped on 2026-08-05**: they existed only for `phpstan/phpstan`, which left the lock with `deptrac` on 2026-08-02, and as written the recipe WITHHELD the `symfony/browser-kit` the functional suite needs [Verified: `--dry-run` reports "including require-dev … Nothing to install"] |

Two notes that cost time to rediscover. The container's default Node is **22.22.2**, one patch below Angular
22's `^22.22.3` floor, so Angular CLI refuses to install until Node is upgraded. And Flutter warns loudly about
running as root but works; the warning is not a failure. The **database prerequisites** are TWO scripts, and they
are for different databases — running only the first leaves `twes_in` in the shape § Gotchas 2026-08-01 records as a
P0:

```
sudo -u postgres env TWES_TEST_DB_SUPERUSER_PASSWORD=postgres bash scripts/dev/provision-test-database.sh
sudo -u postgres bash scripts/dev/provision-dev-database.sh
```

The second needs no environment and is safe to re-run; it refuses a database holding relations owned by neither of
its two roles, which is what stops it being pointed at something shared. Its own coverage is
`api/tests/Integration/Tenancy/DevDatabaseProvisioningTest.php`, which EXECUTES it rather than reading it.

**The `TWES_TEST_DB_SUPERUSER_PASSWORD` is not optional and the script REFUSES without it** (round 17). It sets
a password on the cluster's pre-existing superuser, and `pg_authid` is a **shared** catalogue — so that one
statement escapes `twes_in_test` and applies to every database on the cluster, including `twes_in`. Setting the
variable explicitly is the consent; a default would be indistinguishable from a choice. The script also refuses
when the target database already holds relations, because the only cluster it is safe to do this to is a
throwaway one. A run that refuses creates nothing — verified atomic.

**MANY roles, not one, and the script explains why each one exists** — **no number is written here any more, and
that is the third fix to this sentence.** It said "three", then "NINE as of round 14", and round 21 found it
saying nine, and the fix I wrote then said **fourteen** — also wrong — while prescribing
`grep -c 'CREATE ROLE'`, which COUNTS COMMENT LINES. The real number is **12** and `build-waves.plan.md` had it
right all along. Round 22: *"a prescribed derivation that returns a wrong answer is worse than a prose count — it
looks authoritative and will be re-cited."* Derive it with `grep -c '^        CREATE ROLE'`, anchored, or read the
script's own comment block. (the script's own
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

Overridden in CI by `TWES_TEST_DSN` plus, for the **five** LOGIN roles that connect, a user/password pair
(`TWES_TEST_DB_{USER,OWNER_USER,BYPASS_USER,MEMBER_USER,REPLICATOR_USER}` and their `_PASSWORD`
counterparts) — and `TWES_TEST_DB_MIXED_CASE_ROLE` with `_PASSWORD`, the sixth, whose whole point is a name
that is not all-lowercase. The **three** NOLOGIN probe roles are named by `TWES_TEST_DB_TRUNCATOR_ROLE`,
`TWES_TEST_DB_PROBE_OWNER_ROLE` and `TWES_TEST_DB_UNSETTABLE_ROLE` — no password, because nothing connects as
them. **Derive this list rather than trusting it**: `grep -o 'TWES_TEST_DB_[A-Z_]*' api/phpunit.xml | sort -u`
is the tally, and this paragraph is prose. Round 15 found it still describing "four login roles" and "two
NOLOGIN probe roles" two rounds after the set grew — so a CI provisioned from it omitted all three new
variables and silently skipped both of the mutants that make round 14's tenancy fixes load-bearing.

**Round 21 found the same defect one level deeper, and it is the more interesting one: the TALLY ITSELF was
incomplete.** `provision-test-database.sh` honoured `TWES_TEST_DB_CHAIN_INNER_ROLE`, `_CHAIN_OUTER_ROLE` and
`_INHERIT_ONLY_ROLE`; `api/phpunit.xml` — the file this paragraph names as the thing to derive from — carried none
of them. So a CI provisioned *exactly as documented* still omitted three roles. "Derive it, don't trust prose" only
works if the source you derive from is the real one, and pointing at a second artefact just moves the drift. All
three are now in `phpunit.xml`; the durable check is that the script and that file agree, which nothing yet
enforces.

**`TWES_TEST_DB_SUPERUSER` and its password are REQUIRED, and were wrongly documented here as optional
"used only in one test" until round 15.** MANY tests need a superuser to build privileged fixtures — derive the count with
`grep -c 'self::superuserConnection()' api/tests/Integration/Tenancy/TenantIsolationTest.php`, because this number
has been written as "one", "nine" and "eleven" in three successive rounds and was stale each time. Four of them
are the *only* evidence that a security fix is load-bearing — the `'SET'`-versus-`'MEMBER'` mutant,
the `pg_roles`-versus-`regrole` mutant, and round 15's rule and event-trigger carriers. So a missing or wrong
superuser credential now **fails** rather than skipping, for the identical reason the next sentence gives about
an unreachable database. [Verified: removing the two `<env>` entries turns a green integration run into
`Failures: 26`, where it previously reported `OK, but some tests were skipped!`. **No absolute test count is
written here** — the earlier version of this citation said `OK (98 tests)` / `Failures: 14`, which was accurate
when written and was invalidated by two later commits of the same diff, so round 16 filed it as a false
`[Verified]`. A citation whose numbers move with the suite has to state the DIRECTION, not the totals.]

The defaults in `api/phpunit.xml` are throwaway local values. With no database reachable the integration suite
**fails** rather than passing — deliberately, since a green run that silently skipped the tenancy proof is the
worst outcome available.

**Four test suites, deliberately separate** (`api/phpunit.xml`): `unit` (pure domain, no kernel, no
database), `integration` (real PostgreSQL — the tenancy policy, column fidelity), `functional` (HTTP
through the kernel) and `e2e` (a really-booted server). **`functional` HOLDS CODE since 2026-08-06** — this sentence
said both were empty for a commit after it did — and note that only some of it boots a kernel: the two processor
classes drive the state providers and processors directly, because their subject is the translation between the wire
and the domain, while `InvoiceWriteSurfaceTest` and `HttpSurfaceTest` need the real serializer, validator and router.
**`e2e` HOLDS CODE since 2026-08-07** — `ServedSurfaceTest`, which asks a really-running FrankenPHP/Caddy what it
actually sent: the two disjoint CSP policies, the narrow `/bundles/*` file server, the catch-all's 404, the site-wide
security headers surviving every handler, and no repeated field. None of that is visible through the kernel, because
the kernel is not what serves it. **It is deliberately NOT in `composer gate`** — see the row in the table above.

Derive the real command names from `composer.json` / `package.json` / `pubspec.yaml` rather than
trusting the table above — a command written in prose drifts from the one that exists.

Rule 7 (TDD) applies from the first commit of application code, and infra changes satisfy it with
`docker compose config` / `bash -n` / `--dry-run` output. Money arithmetic, tax rules and state
transitions get their failing test written **first**, every time — that is where this product's bugs
are expensive.

## Translation keys — which refusals get one

A domain exception gets its own key **only when a user can fix the thing it refuses**. Everything else maps to
`error.internal` and carries its detail in the log, not in a response. Written down because round 14 found FOUR
of roughly sixteen user-reachable document refusals keyed and no rule recorded anywhere (round 15 corrected the
count: four `document.*` keys existed at `a474b0d`, not five — counting `money.currency_mismatch` as the fifth
would have contradicted this very table) — so the split looked
arbitrary and the next author had nothing to follow. `scripts/gates/locale-key-parity.php` checks that every
locale carries the same SET; nothing checks COVERAGE, and that direction stays deliberately unautomated because
"is this user-fixable" is a judgement rather than a grep.

| Exception kind | Key? | Why |
|---|---|---|
| the user typed something invalid — a bad quantity, a negative price, a rate too precise | **yes** | they can retype it, and the message is the only instruction they get. `document.quantity_invalid`, `document.quantity_too_precise`, `document.quantity_too_large`, `document.unit_price_negative`, `document.vat_rate_invalid`, `document.charge_amount_negative`, `document.charge_label_empty`, `document.charge_label_too_long`, `pricing.rate_too_precise`, `pricing.rate_invalid`, `pricing.rate_too_large`, `pricing.cost_negative`,
`pricing.net_price_negative`, `pricing.net_price_not_representable`, `document.line_total_too_large`,
`document.total_too_large`, `money.amount_not_representable` |
| the user acted on stale state — issuing twice, editing an issued document, removing a line that is gone | **yes** | plausible double-click or stale page, and a 409/422 the UI must explain. `document.illegal_transition`, `document.not_mutable`, `document.line_not_found` — plus the three
`document.state.*` LABELS their `{state}`/`{from}`/`{to}` placeholders resolve through, which are not
refusals of their own |
| the CONTENT is not issuable — an empty invoice | **yes** | `document.empty_cannot_be_issued` |
| the document is FULL — a 1001st line or fixed charge, against `Invoice::MAX_LINES` | **yes** | not a retype, but a real action and the same one `document.total_too_large` already prescribes: remove something, or split the document. `document.line_count_too_large`, `document.charge_count_too_large` — **two keys rather than one carrying a `{what}`**, because `assertRoomFor()`'s `$what` is the English words `line` and `fixed charge`, and a machine-side noun interpolated into a French or Arabic sentence is the shape the `document.state.*` rule below forbids. Worse than `{state}`, in fact: the noun is gendered and pluralised per locale (`une ligne` / `un frais`; `بند` / `رسم`), so no single sentence can host it grammatically in any language |
| a CONFIGURATION value an administrator can fix — a number-pattern width of zero, or one wider than `NumberPattern::MAX_WIDTH` | **yes** | **TWO keys, one per bound**: `document.number_pattern_invalid` for the floor and `document.number_pattern_too_wide` for the ceiling. Admin-facing is still user-facing; the person who set it is the person who can correct it. The ceiling had no key for a round while `MAX_WIDTH` existed, so an administrator who set 25 was told the width *"must be at least 1"* — round 16's `document.quantity_too_large` defect verbatim (*"The quantity 2 is too large"* when the price was the oversized factor), recurring on the guard added at that finding's own closure. One refusal, one key: a message that names the wrong bound fails this section's own test |
| our own fault — a number from the wrong sequence, a sequence adapter returning 0, a `\LogicException` of any kind | **no** | `error.internal`. Naming internals to a client is noise at best and an information leak at worst |
| a currency mismatch **inside a document** — a line or charge in another currency | **no** | the API fixes the currency per document, so a mismatch reaching `DocumentCalculator` means our own layer built the request wrong |
| an unsupported CURRENCY code | **yes** | `money.currency_unknown`. The administrator picked it, so the person
who set it can correct it |
| a profit rate asked for where none was authored | **no** | `pricing.profit_rate_undefined` exists and is a
`\LogicException` case — it means our layer asked a `net_price`-authored product for a rate it never had. **Named
here rather than deleted** so the file→table direction of this cross-check closes; round 16 found six keys with no
row at all, three of them in this class |
| TRANSPORT-level refusals, not domain ones | n/a | `error.not_found`, `error.tenant_required`,
`error.validation_failed` and `error.internal` belong to the HTTP layer — which **EXISTS since 2026-08-06**, and this
cell read *"does not exist yet"* until the panel found it saying so twelve lines above the paragraph announcing the
transport. Three of these four are now reachable answers of the live transport: `error.not_found` from
`IssueInvoiceProcessor` and `InvoiceProvider`, `error.tenant_required` from the boundary refusal, and
`error.validation_failed` from the validator. None of them RESOLVES its key yet — see the paragraph below. Listed because a
cross-check that silently scopes members out is the exemption-inside-a-check shape § Gotchas records |
| a currency mismatch **while pricing a product** | **yes** | `money.currency_mismatch`, which has existed since Wave 0. Round 15 found the single coarse row above claiming this got no key while the key was there, translated, in all three locales — the row was right about documents and wrong about pricing, where a user really can type a cost and a price in two currencies |

**The keys are LISTED above on purpose.** Round 15 found the previous version of this section promising a key for
three case classes that had none, in the same commit that introduced the rule — so the rule was violated by the
artefact stating it. Naming them makes the two sides checkable by `grep -o 'resname="[^"]*"'` against this table,
which is the nearest thing to automation available for a judgement call.

The test of it: **would a competent user, reading only this message, know what to change?** If not, it is
`error.internal`.

**NOTHING READS THIS CATALOGUE, AND A TRANSPORT NOW EXISTS — so this is no longer an honest placeholder and is recorded as owed.** Round 16 stated the gap and said *"acceptable only while no transport exists: the moment one does, resolving these keys is part of it, and a key nothing resolves is then the declared-but-unconsulted shape § Gotchas records"*. That moment arrived on 2026-08-07 with `POST /api/invoices`, and the condition was not met. Deleting every key would still leave the suite green, because `locale-key-parity.php` checks that the three locales carry the same SET and never that a key is used.

**Why it was not done in the same change, stated rather than glossed:** a dozen distinct keyed refusals all raise a bare `\InvalidArgumentException` whose only payload is an English sentence, so nothing at the transport can tell `document.quantity_too_precise` from `document.total_too_large`. Resolving these keys therefore needs a TYPED EXCEPTION PER REFUSAL in `Domain/` first — carrying its key and its placeholders — which is a larger deliverable than the endpoint that revealed it, and one that touches every guard in the document kernel. **What the write path does today instead:** the input DTOs' validator catches the SHAPE errors and Symfony translates its own constraint messages into all three locales, and a domain refusal that survives that becomes a 422 carrying the domain's English message. So a French or Arabic caller gets a translated message for the common case and an English one for the rest — better than a raw key, worse than the rule in this section, and the gap is here rather than in a commit message.

**A placeholder carrying an ENUM takes a translated LABEL, never the backed value** (round 15). Backing
`DocumentState` fixed `{state}`, `{from}` and `{to}` to the wire values, which is right for the wire and wrong for
the only instruction a user gets: `document.not_mutable` would have rendered *"Ce document est issued"* in French
and an English token in Arabic. So `document.state.draft`, `document.state.issued` and `document.state.cancelled`
exist, and the HTTP layer must resolve `{state}` through them rather than interpolating `->value`. The same rule
applies to any future enum reaching a message — the backed value is an identifier, and an identifier is not a
sentence in anybody's language.

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
- **2026-07-29, CORRECTED 2026-08-01 — `composer install` DOES run. The blocker was never network egress, and
  I misdiagnosed it for twenty certification rounds.** The original entry here said *"GitHub egress is
  restricted to THIS repository, so `composer install` cannot run"* and prescribed *"the environment's network
  policy"* as the only remedy. That was wrong in kind, and the developer challenged it rather than accepting it.
  What is actually true:
  - **General network egress is OPEN.** [Verified: `$HTTPS_PROXY/__agentproxy/status` reports
    `"selective": false`.] There is no restricted-network mode to lift.
  - **`git clone https://github.com/<anything>.git` WORKS**, and so does `raw.githubusercontent.com`
    [Verified: a `--depth 1` clone of `symfony/uid` succeeded; `raw.githubusercontent.com/...` → 200]. The proxy
    injects git credentials (`gitConfigInjection: true`).
  - What returns **403** is only the GitHub **API and archive** hosts — `api.github.com`,
    `codeload.github.com` and `github.com` over plain HTTP — from the agent proxy's *per-repository
    authorization*, not from a firewall. The body says so: *"GitHub access to this repository is not enabled
    for this session. Use `add_repo` to request access."* `add_repo` is still not exposed in this session's
    tool list [Verified: two `ToolSearch` queries, including `select:add_repo`].
  - **So the fix is Composer configuration, not a new environment.** Composer reaches for the API to fetch
    `dist` zipballs; told not to, it clones instead:

    ```
    composer config -g use-github-api false     # stop using api.github.com for dist
    composer config -g github-protocols https   # NOT `git` -- the git:// protocol is dead and yields
                                                # "Failed to clone ... via  protocols" with an empty list
    cd api && composer install --prefer-source
    ```

    [Verified: exit 0, 52 runtime packages installed — Symfony framework-bundle, http-kernel, console,
    runtime, dotenv, uid, yaml, translation, doctrine-bridge, and Doctrine ORM, DBAL, migrations and both
    bundles. Full gate green afterwards: `OK (693 tests, 2687 assertions)`, `test-gates.sh` 361/0,
    `php-cs-fixer` 0 of 70, `dependency-licences` OK.]
  - **`--no-dev` WAS required for exactly one package, and IS NO LONGER — the flag is gone from the command
    above.** `phpstan/phpstan` was the only entry in `composer.lock` with no `source` URL, so `--prefer-source`
    could not avoid the API for it. It reached the lock only as a dependency of `deptrac/deptrac`, and both left
    `require-dev` on 2026-08-02, which retired the flag with them — unnoticed for three days, so the recipe here
    kept prescribing it. [Verified 2026-08-05: `packages: 74, 0 without source; packages-dev: 46, 0 without
    source`; `phpstan/phpstan in lock? NO`; `composer install --prefer-source --dry-run` → *"Installing
    dependencies from lock file (including require-dev) … Nothing to install"*.] **The earlier citation here read
    `packages: 52, 0 without source; packages-dev: 54, 1 without source — phpstan/phpstan`, and it no longer
    reproduces** — kept visible rather than swapped out, because a `[Verified]` figure that has silently stopped
    being true is the artefact this file most wants a reader to distrust.
  - **`--no-dev` omitted `autoload-dev`**, which is why `composer dump-autoload --dev` used to follow it: without
    it `Twes\Tests\` is unmapped and the suite dies with `Class "…DocumentNumberSequenceContract" not found`.
    Retired with the flag. Still worth knowing if a future constraint reintroduces `--no-dev`.

  **The lesson is not about Composer.** I read a 403, reached for the most structural explanation available
  ("the environment forbids it"), wrote it down as `[Verified]` on the strength of four `curl` calls that were
  all consistent with it, and then cited my own note for twenty rounds instead of trying the operation. Four
  `curl`s against API hosts cannot distinguish "the network is closed" from "these three hosts are
  authorization-scoped" — and the one command that would have told me apart, `git clone`, takes five seconds.
  This file already records *"say 'not covered, here is what it would take', never 'cannot be covered'"* four
  times over; this is the same failure applied to an environment rather than a test, and it was the most
  expensive one of the project so far, because it shaped the build order of twelve waves.

  Still true and worth keeping: **Packagist metadata IS reachable**, so a package's declared licence can be
  verified from `repo.packagist.org/p2/<vendor>/<pkg>.json` before adding it — remembering the v2 format is
  *minified*, inheriting any absent field from the previous version entry, so a naive read shows
  `license: null`. And **PHPUnit and php-cs-fixer publish official phars** from `phar.phpunit.de` and
  `cs.symfony.com`, fetched against pinned SHA-256 by `scripts/dev/fetch-tools.sh` — still the right way to
  get those two, since they need no vendor tree. Do **not** resolve anything here by pointing Composer at a
  third-party mirror: that is a provenance decision this project cannot make casually, given
  § "Licensing invariants".

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
- **2026-07-31 — the PER-LINE VAT ALLOCATION RULE is unfixable-later, exactly like the gapless sequence and
  money-is-never-a-float.** A per-line VAT figure is required (developer ruling), and under `PerRateGroup` the
  group's VAT is rounded ONCE on the summed base — so the rounded per-line figures do not add up to it and a
  share must be **allocated**. The rule is **largest remainder, ties to the earliest line**, and the invariant is
  that the shares sum EXACTLY to the group VAT. Change the rule after documents are issued and re-rendering an
  old invoice produces different per-line figures, which breaks the byte-identical-re-download guarantee for
  documents a client already holds. Two things follow and both were defects when this was written: **allocation
  applies under `PerRateGroup` ONLY** — under `PerLine` the group VAT is by construction the sum of the per-line
  rounded figures, so allocating there moves tax onto a line that does not owe it and makes the answer depend on
  line order; and the allocator **floors with `Floor`, never `Down`**, because truncation toward zero is not a
  floor for a negative amount and Wave 2's credit note will reach that path. The cross-tier vectors in
  `docs/spec/pricing-vectors.json` are what stop Angular and Flutter each inventing a third rule.
- **2026-07-31 — the Symfony ecosystem is the only vocabulary; never transliterate a Laravel/Eloquent
  pattern** (developer ruling). Graduated here from § "The Symfony ecosystem is the ONLY vocabulary" so it sits
  in the decision register as well as in its own section. Invariant 1 forbids upstream *code*; this forbids
  upstream *shape*, and it binds even where copyright does not — we legitimately learn behaviour from Invoice
  Ninja, and that behaviour is expressed in Laravel idiom, so every time a behaviour is understood the question
  is *"what is the Symfony-ecosystem way to do this?"* A transliterated pattern is how a Symfony codebase ends
  up fighting its own framework, and how a clean-room build starts to LOOK like a port even though no line was
  copied. The mapping table in that section is the record; a pattern met that has no row gets one in the same
  change.
- **2026-07-31 — a gate that walks the filesystem reads whatever OTHER checkouts happen to be inside the
  repo.** A parallel certification round runs reviewer and fix agents in git worktrees, and the harness places
  them at `.claude/worktrees/<agent>/` — **inside** the working tree. Each carries its own `CLAUDE.md`,
  `LICENSING.md` and every source file, so `grep -r` sees several repositories at once: `test-gates.sh`'s
  licence-surface cross-check failed with an "actual" list that was not wrong about this repository, it was
  reading four. The rule: **enumerate from `git ls-files`, never a recursive walk** — it sees tracked paths of
  the current work tree only, which is the set every one of these checks actually means. `shell-syntax.sh` and
  `no-orphaned-docblocks.php` already do. This also disposes of the `node_modules` special case such walks needed.

- **2026-08-01 — MIGRATIONS GET THEIR OWN CONNECTION, and `.env` claimed so for a commit while nothing implemented
  it.** The comment at the top of `api/.env` read *"Migrations use a different role"*; there was one
  `DATABASE_URL`, naming the restricted runtime role `twes`, and `doctrine_migrations` had no `connection` key. So
  `doctrine:migrations:migrate` with defaults ran **as the application's own credential**, and
  `doctrine_migration_versions` in the local `twes_in` database was owned by `twes` — one
  `ALTER TABLE … DISABLE ROW LEVEL SECURITY` away from every tenant, exactly the round-4 P0, reachable through the
  most obvious command in the project. [Verified: `pg_get_userbyid(relowner)` → `twes`.] Worse, `twes_in` itself was
  owned by `twes`, so `public` belonged to `pg_database_owner` and the runtime role had implicit `CREATE` on the
  schema; the provisioning script gets this right for `twes_in_test` and nothing provisioned the DEV database at
  all. Fixed with a second DBAL connection (`owner`, from `DATABASE_URL_OWNER`) and
  `doctrine_migrations.connection: owner`, so the safe role is the **default** rather than something to remember.
  Three general lessons, all recurrences: **prose asserting a control that nothing implements is the shape this
  section already records five times**; a `types:` map belongs at the `dbal` level and not per connection, which is
  the config format forbidding the bug of two copies disagreeing; and **the blast radius of pinning migrations to a
  connection is every caller that overrode `DATABASE_URL` expecting migrations to follow** — the gate's own
  integration test did, so for one commit it migrated `twes_in` (already current, exit 0) while its probe database
  stayed empty. A migration command that exits 0 does not tell you *which* database it migrated; the test now
  counts the tables it expects to find.
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

- **2026-08-02 — `vendor/autoload_runtime.php` was there the whole time, and a comment asserting otherwise made
  FrankenPHP worker mode unreachable.** `bin/console` and `public/index.php` both hand-rolled the bootstrap
  (`Dotenv::bootEnv()`, `Request::createFromGlobals()`, `handle`/`send`/`terminate`) behind a confident paragraph
  explaining that `symfony/runtime` is a Composer PLUGIN, that plugins are disabled in this container, and that the
  generated file therefore does not exist. It does exist, `composer dump-autoload` regenerates it, and the plugin is
  allow-listed in `composer.json`. [Verified: deleted the file, re-ran `composer dump-autoload`, it came back;
  `Symfony\Component\Runtime\SymfonyRuntime` loads.] **The cost was not stylistic.** `autoload_runtime.php` is the
  ONLY mechanism by which `APP_RUNTIME` selects a runtime, so worker mode — the single largest performance feature
  of the server this project just standardised on — was not "not yet enabled", it was *impossible*. **It is now
  reachable and deliberately REFUSED by a gate**, for a tenancy reason discovered later; see the 2026-08-05 entry
  below. Fixing the bootstrap was still right: a switch blocked ON PURPOSE is a decision, one broken by accident
  is not, and the
  Caddyfile's honest-sounding note about the two remaining preconditions was therefore incomplete in a way nobody
  could see. This is the FIFTH instance in this file of a reasoned-not-tried claim (the four in the 2026-07-30 entry,
  plus the twenty-round Composer misdiagnosis), and the pattern is now unmistakable: **a paragraph explaining why
  something cannot work is the highest-value thing in this repository to spend ten minutes disproving.**
- **2026-08-02 — compose ran two commands the application had no configuration for, and the whole stack was
  documented green.** `infra/compose.yaml` declared a `worker` running `messenger:consume async` and a `scheduler`
  running `messenger:consume scheduler_default`. There was no `config/packages/messenger.yaml`, no `lock.yaml`, and
  no `#[AsSchedule]` provider anywhere — so both containers would have exited with `The receiver "..." does not
  exist.` and crash-looped on the developer's first `docker compose up`. [Verified: running the command on the host
  produced exactly that message.] `THIRD-PARTY-NOTICES.md` meanwhile described `predis/predis` as "the Valkey client,
  for the Messenger transport and the lock store" while `grep -rn Predis api/src api/config api/tests` returned
  nothing at all. **Three separate artefacts described a wiring that did not exist, and the tier's own gate rendered
  the compose files without noticing**, because rendering only proves the YAML is well-formed — it cannot know that
  a string in a `command:` names something real. The gate now derives the receiver set from `messenger.yaml` and the
  `#[AsSchedule]` attributes and compares the two lists, with four mutants proving it fires. The general lesson is
  the one this section keeps relearning from a new angle: **a check that validates a file's SHAPE is not a check
  that its CONTENT refers to anything.**
- **2026-08-02 — Valkey was configured as a queue and justified as if it were durable, and the justification
  refuted itself.** `compose.yaml` runs Valkey with `--save '' --appendonly no` and explains: *"everything here is a
  QUEUE and a LOCK, both of which are reconstructible: Messenger's failure transport is the durable store."* But
  `MESSENGER_TRANSPORT_DSN` pointed at Valkey, so the failure transport WAS the thing with persistence disabled — a
  restart would silently drop the record of every message that had already exhausted its retries, which in a billing
  product is an invoice nobody knows was never sent. The queue moved to the Doctrine transport (PostgreSQL, backed
  up, and transactional with the document that dispatches the message) and Valkey kept the lock store, where losing
  state on restart is CORRECT rather than tolerable. Worth keeping as a shape: **a comment that names its own
  justification is checkable, and this one was false the moment you followed it one step.** Also worth keeping:
  `symfony/redis-messenger` requires `ext-redis` and does NOT accept Predis, which is not obvious from either
  package's name.
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

- **2026-08-05 — dev and prod are now different ARTEFACTS, and the thing that forced it was Xdebug.** A debugger in a
  production image is an RCE amplifier (`xdebug.mode` is settable from any `.ini` a compromised process can write, and
  Xdebug can be told to connect OUT), so the only safe way not to have it there is not to build it there — which is why
  the API tier has a `dev` Dockerfile target rather than a flag. Three things are worth keeping from getting it
  installed: `install-php-extensions xdebug` AND `xdebug-3.5.3` both delegate to **pecl's own HTTP client**, which this
  container's proxy answers `HTTP/1.1 426 Upgrade Required` while `curl` to the same URL returns 200; given a local
  tarball `install-php-extensions` compiles it correctly and then fails on its LAST step (`Unable to find the file of
  the PHP extension`) because it derives an extension NAME from its argument; and taking over the download means taking
  over `$PHPIZE_DEPS` plus `linux-headers`, without which you get `phpize' failed` and then
  `configure: error: rtnetlink.h is required`. **`pecl install` on a curl-fetched pinned tarball is the recipe.**
- **2026-08-05 — a bind mount does not translate ownership, so a dev container must run as the HOST's uid.** The dev
  overlay now bind-mounts the whole `api/` tree (which is what makes an IDE resolve vendor classes — a NAMED VOLUME
  cannot, because an IDE indexes the project directory and a volume lives in root-owned `/var/lib/docker/volumes/`).
  With the image's fixed uid 10001 that fails as `/app/vendor does not exist and could not be created`; running as root
  instead creates root-owned files in the developer's own working copy. `TWES_UID`/`TWES_GID` are passed from
  `id -u`/`id -g`, and **`USER` is NUMERIC** so any value works including 0 — a named `USER` could not, and
  `adduser -u 0` fails because the id is taken. The standing objection to a host `vendor/` ("the container would run
  whatever PHP resolved on the host") is about the INSTALL, not the mount: `make install` runs Composer *in* the
  container, so the container's PHP resolves it and the host merely holds the bytes.
- **2026-08-05 — `NET_BIND_SERVICE` is load-bearing for a reason its name does not suggest, and dropping it stops the
  API EXECUTING rather than binding.** The Dockerfile applies `setcap CAP_NET_BIND_SERVICE=+eip` to `frankenphp`, and
  the `e` (effective) bit makes **`execve` itself fail with EPERM** when the capability is outside the container's
  bounding set: `exec /usr/local/bin/frankenphp: operation not permitted`. It is still required even where the daemon
  sets `ip_unprivileged_port_start=0`, which would make a low port bindable with no capability at all — the exec-time
  check is independent of that sysctl. Every other `cap_add` in `compose.prod.yaml` was established the same way, by
  dropping ALL and reading the failure: postgres needs CHOWN/SETUID/SETGID/DAC_OVERRIDE/FOWNER (its entrypoint chowns
  the data directory then drops privilege), valkey needs SETUID/SETGID (`setpriv: setresuid failed`), and **gotenberg
  needs NOTHING** — worth knowing, because Chromium is the service people reflexively grant `SYS_ADMIN`.
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

- **2026-08-05 — one suffix was answering three different questions, and the bare name meant something different in
  each family.** The developer read the Makefile and spotted it: `up`/`up-prod` marked which STACK a target drives,
  `build-front`/`build-front-dev` marked which ARTEFACT FLAVOUR it produces, and `gate`/`gate-infra` marked SCOPE — so
  a bare name meant "development" in the first family, "production" in the second and "just the API tier" in the third.
  The proof was `build-front`, which was **both at once**: it ran on the dev stack and produced a production bundle,
  with no suffix to say either. And because both bundle targets write to the SAME shared volume that the production
  stack serves, `make build-front-dev && make up-prod` served an unminified bundle carrying our TypeScript source maps
  out of "production" — neither name warned anybody.
  **Ruled: a bare target name acts on DEVELOPMENT, `-prod` acts on PRODUCTION, and a bare aggregate is the WHOLE
  thing while a suffix narrows it.** That direction and not the reverse because of blast radius: muscle memory types
  the short name, so the short name must be the harmless one — and it was already the majority (22 of 25 targets), so
  it is one rename rather than twenty-two. Two asymmetries are deliberate and named in the gate's exemption list:
  there is **no `destroy-prod`** (a one-word command that deletes the production database is a foot-gun no convention
  should demand for symmetry), and `install`/`composer`/`test`/`debug-*` are dev-only because Composer, PHPUnit and
  Xdebug are absent from the production image by design. The general lesson is not about make: **when one token is
  used to answer more than one question, the answers eventually contradict** — and the contradiction shows up first
  in whichever family is read least often.
- **2026-08-05 — writing one recipe per environment is how the two drift; `DCX` writes it once.** The nine production
  twins added by the ruling above (`logs-prod`, `shell-prod`, `migrate-prod`, `backup-prod` …) are not nine new
  recipes. `DCX` is a TARGET-SCOPED variable — `logs: DCX := $(DC)` and `logs-prod: DCX := $(DC_PROD)` — and the
  recipe is then written once for `logs logs-prod:`. Two stanzas, one body, so a change to the dev behaviour cannot be
  forgotten on the production twin. That duplication is precisely what let `build-front` and `build-front-dev` diverge
  in the first place. The `name: ## help` lines carry no recipe and exist only so `make help` lists both.

- **2026-08-05 — A UUIDv7 IDENTIFIER IS AN ORDERING ARTEFACT, NEVER A SECRET** (developer ruling). Recorded
  beside money-is-never-a-float and the gapless sequence because it is the same kind of decision: a security
  property assumed in the wrong place is unfixable once a public surface depends on it. `UuidV7Generator`'s
  docblock claimed *"74 bits of randomness follow the timestamp, so an identifier is not guessable … with
  `/invoices/1234`, a single missing authorisation check becomes enumerable access to every tenant's documents"*.
  A certification round measured all three clauses and refuted two:
  - **It is 64 bits, not 74.** `symfony/uid` spends 10 of the 12 `rand_a` bits on sub-millisecond precision, and
    those are a deterministic function of the clock. [Verified: they equal the sub-millisecond for 1000 of 1000
    values; `symfony/uid`'s own docblock says "a 58-bit timestamp and 64 extra unique bits".] 74 was exact for
    the hand-written `random_bytes(10)` layout and became false in the commit that deleted it.
  - **Same-millisecond siblings are correlated within 2^24**, because the random field is INCREMENTED rather
    than redrawn (measured over 1999 pairs). Different milliseconds are independent — but two documents in one
    request share a millisecond routinely, so the correlated case is the ordinary one.
  - **The seed is recoverable from the output, and MORE CHEAPLY than this entry first said.** 21 observed
    same-millisecond deltas leak 504 of the seed block's 512 bits; a reviewer brute-forced the last byte and then
    *computed* a later identifier exactly, across two generator instances with different clocks — because the
    state is `static` on `UuidV7`, seeded once per PROCESS. Round 4 then showed the first step is free: on the
    initial randomise `self::$rand` is unpacked FROM `self::$seed`, so `rand[1]`/`rand[2]` — emitted verbatim in
    groups 3-5 — **are the first 8 of the seed's 16 bytes**. One identifier halves the search space with no deltas
    at all. The earlier error understated the weakness, which is exactly why it needed correcting: this entry is
    now the project's authoritative statement of the dependency's entropy properties.
  **`symfony/uid` is KEPT** — ordering is what v7 is for, and the hand-written version it replaced failed to
  ascend on about half of all consecutive same-millisecond pairs, which is the worse defect. What changes is what
  the identifier is allowed to mean. Two constraints follow, and **both are mechanised rather than written down**,
  because § Gotchas already records four times that a control asserted in prose is not a control:
  - the unauthenticated **client portal (Wave 10) gets its own `random_bytes(32)` token**, never the document
    primary key — the portal is the one surface where an identifier IS the credential;
  - **FrankenPHP worker mode must not be enabled until that token exists**, enforced by
    **`scripts/gates/worker-mode-blocked.sh`** across **every tracked file**, scope defined by EXCLUSION (docs,
    `tests/`, `.claude/`, the gates themselves) rather than by an inclusion list of paths — the inclusion list was
    version 3's defeat, being fail-open for `api/.env.prod`, `infra/api/Dockerfile.dev` and
    `api/composer.json`. This clause used to enumerate *"every `.env`, every compose file, both Dockerfiles, the
    Caddyfile, the Makefile and `api/config/**`"*, which was wrong twice: there are THREE tracked Dockerfiles, and
    an enumeration is exactly the thing the rewrite removed. Plus
    `compose-config.sh` on the RENDERED configuration, which sees composition no text sweep can. One process per
    request is what confines the recoverable seed to a single tenant; a worker process is what makes it span them.
    Delete both checks in **Wave 10** when the portal token lands, not before.
    **THREE versions of this control were defeated, and every defeat was the same POLARITY error one level up.**
    Version 1 enumerated VALUES: a block-list matching two class names that exist in no package, while
    `export APP_RUNTIME=` walked past the env grep. Version 2 enumerated LOCATIONS: `FRANKENPHP_CONFIG` was
    inspected in the rendered environment where no compose file declares it while the Dockerfile `ENV` that does
    went unread, two of the Caddyfile's four seams were unchecked, and the whole thing SKIPPED without
    `docker compose`. Version 3 enumerated PATHS, and was beaten six ways at once: `api/.env.prod` (the
    **committed** half of Symfony's own dotenv cascade, invisible to a pattern anchored `\.env$`, and
    `infra/.env` sets `APP_ENV=prod` so it is the live file), `infra/api/Dockerfile.dev`, `composer.json`'s
    `extra.runtime.class`, a YAML **block scalar** carrying the directive on lines the key never appears on, a
    FIFTH Caddyfile seam against a hand-written knob list, and the legacy `ENV KEY value` form which contains no
    `=` at all.
    **Version 4 enumerates nothing forbidden.** All three axes are inverted: scope is exclusion-based; the seam set
    is DERIVED from the Caddyfile's own `{$…}` placeholders; and a line mentioning `APP_RUNTIME` must equal one of
    three committed literals while a seam must be **EMPTY** rather than merely free of the word `worker`. That last
    inversion is what makes the block scalar, the legacy form, the quoted YAML key and a `\`-split assignment all
    fail at once — the gate stopped asking what a value MEANS. **The four seams are KEPT, deliberately**: they are
    FrankenPHP's own image's variable names, so deleting them would invent a project-specific spelling of a
    conventional thing, the marker § Gotchas 2026-08-02 records for first-principles wiring. Requiring them empty
    keeps the convention and still closes the route, and using one later means amending the gate — a visible diff.
    Its route count is derived from `test-gates.sh`'s own `for wm_route in` list rather than written down here (see the
    § Architecture row). Several false-positive routes exist because comment text was being read as configuration:
    a message once asserted `APP_RUNTIME is "prod"` about a line that set a different variable entirely.
  The lesson under the lesson: **the entropy claim had NO test**, and a reviewer proved it by reducing the
  increment to a constant `+1` — emitting `…ceb7, ceb8, ceb9` — with **every case then in the file** green.
  (NO COUNT IS WRITTEN HERE, and that is the third fix to this parenthesis. It said "ten" at four sites, then
  "8 at the parent commit, 12 now", and the parent had **12** as well [Verified: `phpunit --filter
  UuidV7GeneratorTest` at `1fc8f71`, `07e5a94` and HEAD all report `OK (12 tests, …)`] — so the correction was as
  stale as the thing it corrected, and a reviewer's "11" was wrong too. Derive it: run the filter, or
  `grep -cE '^    public function test' api/tests/Unit/Shared/UuidV7GeneratorTest.php`. A citation whose number
  moves with the suite states the DIRECTION, not the total, and a sentence whose whole subject is stale numbers is
  the last place to write one.) Round 4 then showed that test
  pins the INCREMENT and not the BASE DRAW: three further mutants passed it, one making two processes emit
  byte-identical identifiers. `testTwoProcessesAtOneFrozenInstantDisagree()` closes that, and the entropy
  assertion is now a birthday test over a thousand draws rather than a spread check over two hundred. Distinctness, ordering
  and well-formedness are all satisfied by a perfect counter, so none of them is an entropy test.
  `testTheRandomFieldIsNotMerelyASequentialCounter()` is.
- **2026-08-05 — the HTML documentation page took THREE silent failures to get right, every one a 200 with the correct
  `<title>`.** `/api` now serves Swagger UI to a browser and JSON-LD to a client on the same URL (`html` back in
  `docs_formats`, `symfony/twig-bundle` + `symfony/asset` installed). Each failure is worth keeping because none was
  visible from `curl`, an exit code, or a passing test:
  **(1)** the assets 404'd — the Caddyfile serves ONLY the front controller, so `public/bundles/**` reached the final
  `handle` and got `404`; fixed with a narrow `handle /bundles/*` file server rather than a file server over `public/`.
  **(2)** `Content-Security-Policy: default-src 'none'; ...; sandbox` — correct for an API response, fatal for a
  document — blocked every stylesheet and disabled script execution. Chromium said
  `Refused to load the stylesheet ...` while `curl` fetched each asset with 200.
  **(3)** the fix for (2) did not apply, because an unmatched `header` and a matcher-scoped `header` overriding the
  same field is ORDER-DEPENDENT and Caddy did not resolve it the way the file read. **Two DISJOINT matchers
  (`@apiDocs` / `@apiData`) is the fix**: exactly one applies to any request, so ordering stops mattering. Resource
  endpoints keep `default-src 'none'` and the docs get `'self'`.
  Also corrected: I claimed all three UIs "pull remote assets". Swagger UI is fully local, **Scalar** hardcodes
  `cdn.jsdelivr.net`, and **ReDoc fetches `cdn.redoc.ly/redoc/logo-mini.svg`** — which our own `img-src 'self' data:`
  BLOCKS [Verified: Chromium `requestfailed` with `errorText: csp`], so ReDoc is safe only *because of* the policy.
  That makes the CSP a privacy control rather than defence in depth, and the functional suite CANNOT see it — it goes
  through the kernel, not Caddy. Pinning it belongs in the empty `e2e` suite and is owed.

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
- **2026-08-05 — the flag that was supposed to harden the PDF renderer disabled it, and its own healthcheck could not
  tell.** `compose.yaml` ran Gotenberg with `--chromium-deny-list=.*` AND `--chromium-allow-list=^file:///tmp/.*`.
  Gotenberg applies the two as a **conjunction** and a deny match is **absolute**, so the allow-list could never
  override `.*` — the renderer was configured to render nothing, and Wave 4's PDF pipeline would have landed on it.
  [Verified: with both flags a local `/forms/chromium/convert/html` produces nothing usable; with the allow-list alone
  it returns `200` and a body beginning `%PDF-`, 7012 bytes from the real stack.]
  **The allow-list alone keeps the whole security property, and that was verified rather than assumed** — removing a
  security flag is worthless if the property goes with it: converting a URL returns `403 Forbidden`, and a local
  template that EMBEDS a remote image still renders while **the remote server is never contacted** (checked against a
  listener on the same docker network, whose log stayed empty). Gotenberg's own default deny-list — `file://` outside
  `/tmp` — is left in force by not naming the flag.
  The lesson is about the EVIDENCE, not the flag: `/health` reported `chromium: up` throughout, so the service's own
  probe could not distinguish a working renderer from one that refuses every conversion. A liveness endpoint answers
  "is the process alive", never "does the thing it exists for work". `compose-config.sh` now asserts the RELATIONSHIP
  between the two lists rather than banning a string, with a mutant.

- **2026-08-06 — the gates split on ONE INVISIBLE FLAG, and a file Claude has just CREATED is untracked, so four of
  them cannot see it at all.** § Gotchas 2026-07-31 rules that a gate enumerates from `git ls-files` rather than a
  recursive walk, because a parallel certification round places reviewer worktrees INSIDE the working tree. Every gate
  obeys it. What none of them says out loud is that they obey it in **two different ways**, and the difference is the
  whole behaviour:

  | enumeration | gates | sees a new untracked file? |
  |---|---|---|
  | `ls-files --cached --others --exclude-standard` | `spdx-headers.sh`, `shell-syntax.sh` | **yes** |
  | `ls-files` (cached only) | `no-orphaned-docblocks.php`, `no-owner-connection-in-application.php`, `worker-mode-blocked.sh`, `compose-config.sh` | **no** |

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
  reason). Pinned by a mutant: replacing the `export GIT_INDEX_FILE` line with a no-op turns `27 passed` into
  `26 passed, 1 failed`, and the case that flips is the orphaned-docblock one, whose fixture is left deliberately
  UNTRACKED for that reason. **The generalisable part is not about git:** two implementations of one documented rule
  is one rule and one silent exception, and the exception is invisible precisely because the rule is written down.

- **2026-08-06 — A RENDERED VALUE CAN DETERMINE ITS OWN FORMATTER, which turned a persisted-configuration problem into
  a DELETED dependency.** The 2026-08-01 ruling — persist an issued document's rendered number beside its sequence, so
  no later setting can restate a legal document a client holds — read like a column to add and a default to keep. It
  was not. `NumberPattern::format()` is left-zero-padding and grows rather than truncating, so for **every**
  *(width, sequence)* pair `padded(strlen($rendered))->format($sequence) === $rendered`. [Verified: 48 of 48
  combinations of width {1,2,3,7,8,20} × sequence {1,9,10,41,99,100,PHP_INT_MAX}, zero mismatches.] So the stored
  string determines a pattern that re-renders it byte-identically, and `InvoiceMapper`'s `NumberPattern` constructor
  dependency — which certification round 21 filed as a real hazard, not a placeholder — is **gone** rather than
  merely defaulted. **An absent dependency cannot be misconfigured and a default can**, which is why this is
  structurally stronger than getting the default right, and why the settings table Wave 1 has not built can now only
  govern what NEW numbers look like.
  Three things generalise past document numbers. **(1)** When a value is persisted alongside the input that produced
  it, check whether the value determines the transformation before adding a column to record the transformation —
  the second column is often unnecessary, and the ruling here had already rejected it on grounds of indirection
  without anyone noticing it was also redundant. **(2)** The derivation must then be **CHECKED**, not trusted:
  § Gotchas 2026-07-31 records a P0 where a validator read its expected column name out of the policy it was
  validating and therefore always agreed with itself, and deriving a pattern from a stored string has the same
  structure — `0000041` stored against sequence 99 would read back as invoice 99 rendered `0000099`, a number nobody
  issued. One `!==` turns a derivation into a checked round trip. **(3)** What is NOT recovered is worth stating
  rather than hiding: for a sequence that outran its padding the authored width is unrecoverable, and it does not
  matter, because every width from 1 to 5 renders `12345` identically. A round-trip contract test has to assert the
  OBSERVABLE property there and cannot use a whole-object equality backstop — the objects legitimately differ.

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

- **2026-08-07 — A LOCK CAN BE CORRECT AND UNPROVABLE, and the remedy was to change the CODE rather than build a
  harness to see it.** The gapless counter was written the way this file and the port both prescribed —
  `INSERT … ON CONFLICT DO NOTHING`, then `SELECT … FOR UPDATE`, then `UPDATE … + 1` — with a concurrency test written
  specifically to prove the lock load-bearing, because `build-waves.plan.md` records zero concurrency assertions on
  this adapter as a `completeness-reviewer` P0. **Deleting ` FOR UPDATE` left the whole suite green. Twice.** The
  second time was after the fixture had already been corrected once, on a diagnosis that was also wrong.
  - **What was actually happening:** `INSERT … ON CONFLICT DO NOTHING` **blocks on its own** whenever a concurrent
    transaction has touched that key — `canceling statement due to lock timeout … while inserting index tuple`
    [Verified on a live cluster]. So under contention the `SELECT` was never the statement that serialised the two
    sessions, and the `lock_timeout` the test relied on fired from the wrong statement. My first diagnosis (that a
    brand-new tenant made the *first* session create the row) was true and insufficient; committing a seed row first
    changed nothing.
  - **The window the lock really closed was between the first session's OWN `SELECT` and its `UPDATE`** — reachable
    only by a harness that interleaves statements *inside* the method. So the three-statement form was correct
    **only because of a lock nothing could observe**, which is this file's most-recorded defect class: § Gotchas
    already holds four separate controls that existed and were enforced by nothing.
  - **The fix was one atomic statement**: `INSERT … ON CONFLICT (…) DO UPDATE SET next_value =
    document_number_sequence.next_value + 1 RETURNING next_value - 1`. There is no between-statements window to
    protect, so serialisation stops being a property of code that must remember to take a lock and becomes a property
    of the statement. [Verified: 1, 2, 3 on a new key; 1 again after a rollback; and with two overlapping transactions
    the second is refused `55P03` while the first is open and returns the NEXT value once it commits — both for an
    existing counter and for two sessions racing to create one.] It is also mutant-killable in a way the old form was
    not: `EXCLUDED.next_value` for the table-qualified column, or dropping the `- 1`, each turns 11+ cases red.
  - **The plan and the port were AMENDED rather than left to disagree**, per this section's 2026-07-29 rule. Their
    substance is untouched — not a sequence, a row, inside the caller's transaction, serialised, at an accepted
    throughput cost — and what changed is that they now require the OUTCOME instead of naming one statement.
  - **The general lesson, and it is not about locks:** *"the test is not arriving"* (§ Gotchas 2026-07-31) has a second
    ending. Sometimes the honest conclusion is not a better test but a design whose property is structural. A control
    that only a bespoke harness can observe is one refactor away from being deleted by someone who cannot see it
    working.
  Two smaller findings from the same change, both worth keeping:
  **(1) A guard added to `save()` was a SECOND COPY of a rule `InvoiceMapper` already enforced — and the mutant that
  killed it is what revealed the duplicate.** Deleting the new guard produced the mapper's own refusal, with a *better*
  message: it names the consequence (an `Invoice` written under another type files its number in that type's sequence
  and leaves a hole in the invoice one). The new copy was deleted and the surviving definition is the one that explains
  itself. A mutant does not only tell you whether a fix is load-bearing; it tells you whether it is the FIRST one.
  **(2) A generic annotation can make a LIVE check look dead.** `@implements ProcessorInterface<NewInvoiceInput, …>`
  narrowed a `mixed $data` parameter, so PHPStan reported the processor's own `instanceof` guard as
  `instanceof.alwaysTrue`. The annotation was the wrong thing: the interface really does hand over `mixed`, and the
  guard is what narrows it. Suppressing the finding would have been the tempting move and would have left a promise
  standing in for an enforcement.

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

- **2026-08-07 — THE `e2e` SUITE FOUND A REAL DEFECT ON ITS FIRST RUN, and it was one no other layer could see: two
  values for one header.** `infra/api/Caddyfile` set `Cache-Control "no-store, private"` and
  `Vary "Accept, Authorization"` at the site level. The served response carried **both** those AND Symfony's own
  `Cache-Control: no-cache, private` and `Vary: Accept` — two lines each. Caddy's `header` writes the field before the
  PHP handler runs, so the application's value is added afterwards. RFC 9110 lets a recipient combine repeated field
  lines, so a conforming cache saw the union and `no-store` won: **not a hole, but an outcome decided by the recipient
  rather than by our configuration**, which is not a property to build a tenancy boundary on. Fixed with Caddy's `>`
  (deferred-set) prefix, which applies the operation after the response is written.
  Three things generalise, and the second is the one worth keeping:
  **(1) `>` ON ONE FIELD SILENTLY FIXES THE WHOLE BLOCK.** A `header` block containing any deferred operation is
  applied as a unit after the response is written [Verified against the running stack: with `>` on `Vary` alone, both
  fields came back single-valued]. So the first mutant I wrote — remove `>` from `Cache-Control` only — SURVIVED, and
  I nearly filed the test as weak. It was not: the mutant was not a mutant. Both fields carry the prefix anyway, for
  explicitness, because a correct response that depends on a neighbouring line regresses when somebody deletes the
  other one.
  **(2) MY OWN TEST HELPER WAS BLIND TO THE DEFECT IT FOUND.** `get()` parses headers into a MAP, so a repeated field
  collapses to whichever line came last — the defect was visible only because the application's line happened to be
  second. Had Caddy's been second, every value assertion in the class would have passed on a response carrying two
  conflicting policies. This is the *assert the property, not the representation* shape this section already records
  twice, and the fix was a case that counts RAW header lines. A check on a collapsed representation cannot see a
  duplication defect at all.
  **(3) A DELETED `header @apiDocs` LEAVES THE DOCS PATH WITH NO CSP AT ALL, not with the strict one.** The two
  matchers are disjoint by design, so there is no fallback — which is correct for ordering-independence and means a
  deleted line is an unprotected path rather than an over-protected one. Found while building the before/after
  evidence, and covered: the suite asserts the exact policy, so `null` fails.
  **(4) PHPStan CALLED THE SUITE'S OWN UNREACHABLE-SERVER GUARD `always false`, AND IT WAS RIGHT.** The transport
  helper used `file_get_contents` plus the magic `$http_response_header`, whose existence in the calling scope depends
  on whether a request produced headers at all — so on a refused connection the guard could not fire and the next line
  would have raised an undefined-variable warning instead of the message telling you to start the stack. The fix was to
  delete the magic variable (`fopen` + `stream_get_meta_data`, where `false` from `fopen` IS the connection failure),
  not to suppress the finding. A static-analysis complaint about a TEST's own error path is worth reading as a bug
  report: that path is, by construction, the one no green run ever executes.

  **The visual half is what the header assertions cannot give.** With the strict policy applied to `/api`, real
  Chromium renders the documentation page as broken image placeholders and unstyled links with no Swagger UI widget —
  a 200, a correct `<title>`, nothing usable. That is § Gotchas 2026-08-05's description, reproduced and photographed
  rather than recalled, and it is why this page needs a rendered check and not only a status code.

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

- `.claude/settings.json` — `defaultMode: auto`, pre-approved read-only/build commands, **`deny: []`
  AND `ask: []`**, and the hooks: SessionStart install, PreCompact handoff, and the `PostToolUse`
  write-time pair below. Note: `allow` entries are
  **inert in cloud sessions** (they need a workspace-trust dialog a cloud session never shows), so
  `defaultMode` is what actually takes effect. Don't grow the allow list expecting cloud effect.
  **Both `deny` and `ask` stay empty permanently** (developer instruction, 2026-08-06): a web session
  has no terminal, so a blocked or prompted command is not handed back to the developer to run — it is
  simply lost. That is a stronger reason than the inherited "dead end" wording and it is why the
  discipline in § "Git autonomy" is the only control. `rent-watch` carries four `deny` entries over
  `.env` files; they are **not** ported here, and porting them would break the tier — `api/.env`,
  `api/.env.prod` and `infra/.env` are committed templates that the gates read on every run.
- `.claude/hooks/**` — the repo-local `PostToolUse` hooks, on `Edit|Write`, plus their own test suite
  (`bash .claude/hooks/test-hooks-on-write.sh` — it reports its own case count; none is written here).
  Distinct from `scripts/claude-bootstrap/hooks/`,
  which is the **portable** bundle installed into `~/.claude/`; these are twes-in-specific and travel
  nowhere. **That suite is NOT in `composer gate`, and saying so is the point rather than an omission**:
  it is the third such suite here (`test-install.sh` and `hooks/test-precompact-handoff.sh` are the
  others) and all three drive a hook against a sandboxed `HOME`/`CLAUDE_PROJECT_DIR`, so gating them
  would put a step that rewrites the environment inside the build. `shell-syntax.sh` covers the
  hooks parsing; the behaviour is covered by running the suite after any edit to a hook. That is the
  whole protocol, and it is a weaker guarantee than a gate — stated so nobody has to discover it.
  - `lint-on-write.sh` — the one file that changed: `php -l` then php-cs-fixer `check`
    (`--path-mode=intersection`, run from `api/` because the fixer reads `composer.json` from the cwd),
    or `bash -n` for a `.sh`. **PHPStan is deliberately out** at 11.3s per file [measured]; `gate:static`
    covers it. **Nothing is auto-formatted** — `check`, never `fix` — because a doc comment's position is
    part of its truth here, and both facts are pinned by an assertion rather than left to memory.
  - `gates-on-write.sh` — **owns no rules.** Every check is an invocation of a real script in
    `scripts/gates/`, so a write-time verdict is the gate's own verdict with the gate's own message.
    Routing is biased towards over-running (under-routing only defers a finding to `composer gate`), so
    every `.php` write runs the whole architecture set. `test-gates.sh`, `schema-tenancy.php` and
    `compose-config.sh` are excluded and each exclusion is pinned.
- `.claude/skills/**` — repo-native slash skills, read in place.
- `.claude/agents/**` — the three certification-lens reviewers.
- `.claude/settings.local.json` is gitignored — machine-local overrides go there.
- `scripts/claude-bootstrap/**` — the global framework, carried in-repo and installed into the
  ephemeral `~/.claude/` at session start. See its `README.md`.

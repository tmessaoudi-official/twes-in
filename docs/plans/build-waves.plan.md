# Build waves Plan

The full build, sliced into waves, with a **certification review by the three lenses at every wave
boundary** (developer ruling, 2026-07-29). Written before Wave 1 starts so the shape is clear and
scope arrives deliberately rather than by accident.

**Wave 0 has landed in part; everything from Wave 1 on is unimplemented.** Read
`reimplementation-strategy.plan.md` first — it holds the licensing invariants and the pinned stack. Note
that two of its `AGREED` rulings were superseded by Wave 0 and are annotated there in place.

## Decisions Log

- [2026-07-29 13:40] AGREED: build in **waves**, each independently reviewable, each ending in a
  MAXIMAL certification round (three lenses, two consecutive clean rounds, cap 5 → ask). A wave is
  not "done" until its gate is green *and* the panel converges on it.
- [2026-07-29 13:40] AGREED: the wave list below is the **baseline**, not the final scope. The
  developer has feature changes and additions to fold in; those are gathered **before Wave 1** and
  this file is amended in the same change, so no wave is built against a stale scope.
- [2026-07-29 13:40] AGREED: **Wave 0 exists and is not optional.** The seams decided in it — money
  type, tenancy strategy, ID format, error shape, layer boundaries — are the ones that cannot be
  retrofitted without touching everything. Every later wave assumes them.
- [2026-07-29 16:20] RULED: **tests and code-quality enforcers in every tier, not just the API**
  (developer instruction). The API landed four suites — unit, integration, functional, e2e — plus six
  gates. The client tiers do not exist yet, so their equivalents are written into `admin/README.md`,
  `mobile/README.md` and `infra/README.md` as **gate conditions of their own waves**, which is what stops
  them being an afterthought.
- [2026-07-29 16:20] RULED: **the domain layer has zero Composer dependencies.** Arithmetic is `bcmath`,
  a PHP extension. Scaled integers were rejected because `NUMERIC(19,4)` overruns PHP's integer range,
  and `brick/math` because it is unnecessary once bcmath is exact. Rounding — which bcmath does not do —
  is implemented once in `Domain/Shared/Decimal` and tested across all eight modes including negative
  ties. This resolved a genuine contradiction in `CLAUDE.md`, which asserted both "ZERO dependencies" and
  "a decimal library may be an implementation detail".
- [2026-07-29 16:20] RULED: **tenant isolation is PostgreSQL row-level security**, superseding the
  default-on Doctrine filter this plan originally specified. Same requirement — forgetting must be
  impossible — but a filter is bypassed by native queries, migrations and `psql`, and a server-side policy
  is not. The filter becomes a second layer when Doctrine lands. Rationale and the three ways RLS can be
  silently defeated: `CLAUDE.md` § Gotchas.
- [2026-07-29 16:20] FOUND: **`qossmic/deptrac` is abandoned** in favour of `deptrac/deptrac`
  [Verified: packagist reports `abandoned: deptrac/deptrac`]. This plan and `CLAUDE.md` both said
  "deptrac" generically; the obvious package is the dead one.
- [2026-07-29 17:10] RULED: **the Flutter client ships all six targets** — Android, iOS, Linux, Windows,
  macOS and Web (developer instruction). Consequence recorded because it is structural, not a flag: builds
  cannot be cross-compiled, so CI is a three-runner matrix and a release is six artifacts from three
  machines. Flutter stays on **stable**, currently 3.44.8 / Dart 3.12.2 [Verified against Flutter's release
  manifest]. Flutter Web was left open in this entry and **RESOLVED by the 17:40 ruling below: it ships, and
  twes-in offers two admin interfaces.**
- [2026-07-29 17:40] RULED: **the profit-rate fix is BOTH parts, combined** (developer ruling). A product
  persists `authored_by` — `profit_rate` or `net_price` — and the typed field is never recomputed; **and**
  `Rate` carries 12 fraction decimals (10 on the percentage) instead of 6. Precision moves the boundary,
  authorship removes it. This closes the contradiction between "the stored authority is `net_price`" and
  "editing cost preserves the rate": the rate implied by a typed pair is what carries forward, so
  authorship transfers to the rate. Verified on both motivating cases — a millime of profit on 10,000 TND
  and 0.500 on a million — and pinned for all three tiers in `pricing-vectors.json` § `authored_field`.
- [2026-07-29 17:40] RULED: **Flutter Web ships — twes-in offers two admin interfaces**, Flutter and
  Angular, the same shape Invoice Ninja offers with Flutter and React (developer instruction). Accepted
  cost: every admin screen exists twice. The public client portal stays Angular/server-rendered, because an
  unauthenticated link opened on mobile data is a different requirement from an admin console.
- [2026-07-29 13:45] AGREED: certification tier per wave is **MAXIMAL** for any wave touching money,
  tax, tenancy, migrations, payments or e-invoicing — which is most of them. Documentation-only
  changes between waves get a single pass. See `CLAUDE.md` § "Certification ladder".

---

## How a wave works

Every wave follows the same loop, and none of it is optional:

1. **State the slice** — what is in, what is explicitly out, and the acceptance criteria.
2. **Write the failing tests first.** Money, tax and state transitions get their test before their
   implementation, every time. This is where this product's expensive bugs live.
3. **Build** to the architecture rules in `CLAUDE.md` § "Architecture" — framework-free `Domain/`,
   Doctrine mapping in XML, dependencies inward only.
4. **Run the tier gate** — `CLAUDE.md` § "Quality gate". Green means all of it, not most of it.
5. **Certification round** — the three lenses (`domain-correctness-reviewer`,
   `tenancy-security-reviewer`, `completeness-reviewer`) against the **frozen** wave commit. Findings
   → fix → re-round. Two consecutive clean rounds, cap 5 → ask in plain text.
6. **Record** rulings in this file's Decisions Log, gotchas in `CLAUDE.md` § "Gotchas".

**Freeze before you certify.** A panel round run against a moving tree cannot count toward the
two-clean requirement — learned the hard way during the bundle integration, where four rounds were
spent partly because the tree changed under the reviewer.

---

## Wave 0 — Foundations — **LANDED (partially), 2026-07-29**

Delivered and verified, **after certification rounds 1, 2 and 3** (see below): **251 tests, 1247
assertions green** and **183 gate tests green**; six architecture/licensing gates, each proven to fail on an injected
violation; the tenancy invariant proven against a real PostgreSQL 18.4 server, including a test that
removes the guard and watches every tenant leak, and one that exercises a *reused* connection.

| Delivered | Where |
|---|---|
| `Money` + `Currency` + `Decimal` + `RoundingMode` | `api/src/Domain/{Money,Shared}/` — bcmath, zero Composer dependencies, all eight rounding modes with negative ties |
| `Rate` + `PriceCalculator` (the F4 arithmetic) | `api/src/Domain/Pricing/` |
| `docs/spec/pricing-vectors.json` **with a live consumer** | `api/tests/Unit/Pricing/PricingVectorsTest.php` |
| Tenancy seam + shared-DB mode | `api/src/Infrastructure/Tenancy/` — PostgreSQL row-level security |
| `Clock` + `IdGenerator` ports, UUIDv7 adapter | `api/src/Domain/Shared/`, `api/src/Infrastructure/Shared/` |
| Six fitness gates | `scripts/gates/` |
| Four-suite test taxonomy | `api/phpunit.xml` — unit · integration · functional · e2e |
| FR/AR/EN catalogues + parity gate | `api/translations/` |
| Tier skeletons with their owed gates written down | `admin/`, `mobile/`, `infra/` READMEs |

**NOT delivered, and why — this is the honest half of the entry.** GitHub egress in the development
container is restricted by organisation policy to this repository alone, so every Composer `dist` URL
returns 403 and `composer install` cannot run (`CLAUDE.md` § Gotchas has the verification). That blocks:

- the **Symfony application itself** — kernel, HTTP layer, the RFC 9457 error shape, `bin/console`;
- **Doctrine** — ORM mapping in XML, the migration that creates the RLS policies, `schema:validate`,
  and the Doctrine filter that becomes isolation's second layer;
- **PHPStan and deptrac**, whose phars ship only from GitHub releases.

`api/composer.lock` is committed and fully pinned, so the next session with reachable dist URLs runs
`composer install` and continues. These items are **Wave 0's remainder, not Wave 1's scope** — Wave 1
must not start until they land, because the calculation kernel needs persistence to be meaningful.

Two scope changes made deliberately rather than silently:

- **The a11y harness moved to Waves 8 and 11.** `axe-core` needs an Angular app and semantics tests need
  a Flutter app; building either now front-runs its wave. Both are written into those tiers' READMEs as
  **gate conditions**, so they cannot arrive as an afterthought.
- **The F4 profit-rate arithmetic came forward from Wave 1.** It is pure `Money`/`Rate` work, and
  landing `pricing-vectors.json` without a consumer would have left the cross-tier fixture untested — the
  one failure mode that makes the whole mitigation worthless. The `Product` entity and the endpoints stay
  in Wave 1.

### Certification round 1 — 48 findings, and what remains owed

The MAXIMAL panel ran against frozen commit `8639de8` and returned **48 findings** (16 security,
12 correctness, 20 completeness). That is recorded as a number rather than smoothed over, because the
count is the argument for the panel existing. The three most serious were not style:

1. **The suite was unrunnable from a fresh clone.** `api/tests/Functional` and `api/tests/E2E` are
   declared in `phpunit.xml` and git cannot track empty directories, so PHPUnit exited 2 and **zero
   tests ran** — while passing in the author's tree, which still had the untracked directories.
   *Fixed:* `.gitkeep` in both, and a fresh-clone check is now part of the wave's evidence.
2. **`Decimal::divide`'s rounding had no coverage, and two wrong-money mutants survived the suite.**
   Deleting the exact tie test still gave `OK`, while `0.001/2` returned `0.000` and `2.000/3` returned
   `0.666`. *Fixed:* `DecimalTest.php`, written case by case to kill specific mutants; all five now die.
3. **The fail-closed tenancy claim was true only on a virgin connection.** After one `set_config`, the
   custom GUC's reset value is the empty string, not NULL — so a naive policy raises a cast error on the
   next unbound query instead of returning zero rows, and every test passed because `setUp()` opens a
   fresh connection. *Fixed:* `nullif(..., '')` in the canonical policy, `bind()` reads the value back
   rather than trusting it, and a test exercises a **reused** connection.

Also fixed in the same round: `Money::of()` silently coerced a float to an int from any weak-mode caller
(`19.99` became `19.000`) — now refused explicitly, and proved by a deliberately non-strict test file that
**guards its own premise**, because `php-cs-fixer` added `declare(strict_types=1)` to it twice while every
test stayed green. Six gate bypasses (`gmdate()`, `$_ENV`, `$_SERVER`, string callables,
`new $dynamicClass()`, `#[\Doctrine\ORM\Mapping\Entity]`). No gate had any test — now
`scripts/gates/test-gates.sh`, 33 cases. A magnitude guard on `Money`, since `NUMERIC(19,4)` holds 15
integer digits and nothing checked. Three missing ISO 4217 codes. `FrozenClock` silently violated its
port's UTC contract when given an offset — a **one-day** shift. The licensing gate never checked
`THIRD-PARTY-NOTICES.md` although `CLAUDE.md` said it did. The fixture could not distinguish per-line from
per-document VAT rounding, and `edited_field` was inert. Stale supersession claims in `README.md`,
`CLAUDE.md`, `reimplementation-strategy.plan.md`, 11 skills and 3 reviewer agents.

**Owed, and deliberately not closed in this round** — each is real, none is a blocker for the wave's
seams, and all are recorded so they cannot be mistaken for done:

| Owed | Why it is not closed here |
|---|---|
| **Composite tenant keys as a schema rule, mechanically checked.** PostgreSQL performs referential-integrity and uniqueness checks with row security BYPASSED, so a single-column FK lets one tenant delete another's rows and a bare unique constraint is an existence oracle. | Documented in `policySqlFor()` and in `tenancy-security-reviewer`, but there is no table yet to check. **Becomes a P0 with the first Wave 1 migration** — a gate asserting every `company_id` table has `relrowsecurity AND relforcerowsecurity`, a policy, and composite keys must land with it. |
| **A non-owner runtime database role, without `TRUNCATE`.** `FORCE` stops an owner skipping policies, not removing them; `TRUNCATE` is never subject to RLS at any privilege level. | Infrastructure, not code — belongs with `infra/` (Wave 12). **Round 2 found this row claimed it was "recorded in its README" when it was not** — `infra/README.md` mentioned only non-superuser/`BYPASSRLS`. Now genuinely recorded there. |
| ~~The rate-quantisation question.~~ **CLOSED 2026-07-29** by the 17:40 ruling in the Decisions Log: `authored_by` plus 12 fraction decimals. Both motivating cases now come out exact and are pinned in `pricing-vectors.json` § `authored_field`. | Kept as a struck row rather than deleted, so the trail from finding to ruling stays readable. |
| **`netFromCost` differs from the spec's written formula under `HalfEven`.** One multiplication vs `cost + (cost × rate)` diverge when the tie parity flips. | Identical under the ruled `half_up` (160,000 pairs, 0 divergences). The one-step form is the better arithmetic; the plan's formula line should be amended to match rather than the code changed — folded into the pricing spec next round. |
| **PHPStan and deptrac configuration.** No `phpstan.neon`, no `deptrac.yaml`, and `composer gate` names `gate:static` with neither a config nor paths. | Both are uninstallable here, so a config would be untested. Lands with `composer install`. |
| **The i18n catalogues have no consumer.** Nine keys in three locales; every message the code emits is still a hardcoded English literal. | There is no HTTP layer to translate for. Lands with the RFC 9457 error shape, together with a reverse gate (a key used in code but absent from a catalogue). |
| **Locale-aware formatting and its own test vectors.** Wave 0's scope named it; no formatter exists. | Needs a rendering surface to be meaningful. Moves to Wave 4 (PDF) and Wave 8 (admin), where `TND`'s three decimals can actually be seen to be right. |
| **A JSON Schema for `pricing-vectors.json`.** | The `$schema` key pointed at a file that did not exist and has been removed rather than left dangling. Worth writing when the second consumer (Angular) lands and the shape stops moving. |


### Certification round 2 — 26 findings, NOT clean; the counter did not reset

Ran against frozen `a18aa9d`: **9 correctness · 11 completeness · 6 security.** Reviewers were told what
round 1 fixed so they audited the fixes rather than re-finding the originals, and were told explicitly
that a clean verdict was acceptable. It was not clean.

**Fixed in this round** (see the commit that follows this entry): the stale `composer.lock` — round 1 added
`ext-intl` and `ext-pdo_pgsql` to `composer.json` and never regenerated it, so `composer validate` exited 2
while `CLAUDE.md` declared that row "Runs", and neither extension was in the lock's `platform` block so
`composer install` would not have enforced them; four surviving stale claims, all of them mine, all of the
same shape — **a correction appended while the false statement stood**, which is the third time in one
session and is now a Gotchas entry rather than a note.

**Owed from round 2, and this is why Wave 0 is not certified.** Each is real, each has a reproduction in
the round-2 reports, and none should be closed without one:

| # | Finding | Severity |
|---|---|---|
| R2-1 | **`ProductPricing::withCost()` guards the OLD cost for zero, not the new one.** `fromNetPrice(100.000, 150.000)->withCost(0.000)` deletes the typed price (net becomes `0.000`) and then reports `50%` on a zero cost, contradicting `profitRate()`'s own docblock. The one thing the class exists to protect. The fixture only ever moves *away* from a zero cost — its own `why` says "the reverse direction is not [safe]", and that is the untested direction. | **P0** — **CLOSED.** `withCost` now guards the NEW cost as well as the old one; the typed price survives a correction to zero and authorship does not transfer. `profitRate()`'s docblock is precise about a *typed* rate being defined on a zero cost. `ProductPricingTest` covers both directions and the guard-removal mutant dies. |
| R2-2 | **`scripts/gates/test-gates.sh` reports 33/33 for gates that detect nothing.** Five demonstrations: `assert_gate` accepts *any* non-zero exit for an expected-failure case and never asserts output, so a gate replaced with `throw` passes all 33; the fixture never copies `api/composer.json`, so the notices check is exercised zero times and can be deleted wholesale; `api/src/Application` is never created, so half of `FORBIDDEN_BY_LAYER` is uncovered; 60 of 71 banned names and 7 of 9 superglobals can be removed; `SEARCH_ROOTS` can be cut to one entry. | **P1** — **CLOSED.** `assert_gate` now asserts the gate's own FAIL/OK marker and, per case, the specific violation text — a crash no longer reads as a detection. The fixture gained `api/composer.json` and an `Application/` tree, and four cases were added. 37 cases; all five demonstrated neuterings are now caught (verified individually). |
| R2-3 | **Fail-closed tenancy is defeated by any pre-existing session value of `twes.tenant_id`** — settable with no privilege via a DSN `options='-c twes.tenant_id=…'`, i.e. exactly what a `DATABASE_URL` carries. After `bind()` commits, the session value is restored and the unbound path reads and writes that tenant. `bind()`'s read-back comment claiming it closes the session-scope gap is **wrong**. Fix, verified to discriminate correctly and not to false-positive on the reused-connection case: `assertConnectionCannotBypassPolicies()` must also assert `current_setting('twes.tenant_id', true) ∈ {NULL, ''}` at connection acquisition. | **P1** — **CLOSED.** `assertNoTenantPinnedOnTheConnection()` refuses a connection whose GUC is already set, accepting only NULL (never bound) and `''` (bound and committed) — so it does not reject recycled connections. Called from `assertConnectionCannotBypassPolicies()`. Tested with a real `options='-c …'` DSN. |
| R2-4 | **A surviving mutant that launders an unrepresentable amount.** Narrowing the remainder scale in `Decimal::rescale` makes `Money::of('0.10001', TND)` return `0.100` instead of refusing. Root cause: **no test anywhere uses an amount more than ONE decimal beyond the currency scale.** Needs `Money::of('0.10001', TND)` throws and `Decimal::rescale('0.0005001', 3, HalfDown) === '0.001'`. | **P1** — **CLOSED.** `MoneyTest` and `DecimalTest` now use amounts *two and ten* decimals past the currency scale; the remainder-scale mutant dies. |
| R2-5 | **`withProfitRate()` and `withNetPrice()` have no test** — the two methods the UI calls on every edit. `return $this;` survives both. Same for `withCost`'s zero-rate branch, because the fixture asserts `net_price` and `authored_by` but not `cost`. | **P1** — **CLOSED.** `ProductPricingTest` covers both mutators, the cost assertion, immutability and both currency guards; every no-op mutant dies. |
| R2-6 | **The profit-rate formula now exists TWICE in `Domain/`.** `ProductPricing::profitRate` is line-for-line `PriceCalculator::profitRateFromNet`, and `netPrice` is `netFromCost`; they are driven from two different fixture sections and nothing asserts they agree. `profitRateFromNet` has no non-test caller. Directly against `CLAUDE.md` § Architecture: *"one implementation, never two"*. | **P2** — **CLOSED by unification.** `ProductPricing` now delegates both formulas to `PriceCalculator`, which is the single home; [Verified: `grep -c 'markupMultiplier\|ratioTo'` → 0 and 2]. |
| R2-7 | **No `RateTest`.** `Unnecessary → HalfUp` survives in both factories, turning `InvalidRate::tooPrecise` into the silent rounding its own message forbids. `isZero`, `isNegative`, `equals`, `zero()` all unasserted. | **P2** — **CLOSED.** `RateTest` added — 40 assertions across both factories, the too-precise contract, sign predicates, `zero()`, `markupMultiplier()` and the scale relationship. The `Unnecessary → HalfUp` mutants die. |
| R2-8 | **`test-gates.sh` is wired into nothing** — absent from `CLAUDE.md` and from `composer gate`. A 33-case suite no documented command runs. | **P2** — **CLOSED.** `test-gates.sh` is in `composer gate:architecture` and in `CLAUDE.md`'s command block. |
| R2-9 | **The six-target / two-admin-interfaces ruling has no mechanical home.** Wave 12 says only "CI mirroring the quality gate"; `CLAUDE.md`'s Flutter gate row has **no per-target build**; the three-runner matrix exists in one README and no wave, gate row or table. Eleven skill banners still say "a Flutter mobile/desktop app". | **P2** — **CLOSED.** Wave 12 carries the three-runner release matrix; `CLAUDE.md`'s Flutter gate row requires a build of all six targets; header and all eleven skill banners updated. |
| R2-10 | **`authored_by` is new persisted state that never reaches Wave 1's scope.** Wave 1's `In:` list names no `Product` entity, and F4 names only the snapshot rule — so the wave writing the first migration has no record that the column exists. | **P2** — **CLOSED.** Wave 1's scope names the `Product` entity and specifies all four pricing columns with types, `authored_by` non-null, plus the composite-key and RLS requirements. |
| R2-11 | **`testTruncateIsNotProtected…` cannot distinguish what it claims to pin.** It counts rows while still bound to tenant A, so a scoped `DELETE` produces the identical observation. It would pass unchanged the day PostgreSQL made `TRUNCATE` RLS-scoped — exactly when it should start failing. | **P2** — **CLOSED.** The test now counts with RLS off, which is the only way to observe that tenant B's row went too; a scoped `DELETE` no longer produces the same observation. |
| R2-12 | **A savepoint rollback after `bind()` reverts the GUC to the previous tenant** while the PHP-side context still believes the new one. Not reachable today (PDO forbids nested transactions) but Doctrine implements them as savepoints, and `InMemoryTenantContext::switchTo()` exists for exactly the multi-tenant worker case. | **P2** |
| R2-13 | **Gate evasions still open:** `new (expr)()` and `new (self::CONST)()` (a third syntax the round-1 `new $var()` guard did not consider), and `DateTimeImmutable::createFromFormat()` — a genuine clock read, since missing format fields default to *now*, in a call that looks like ordinary parsing. | **P2** |
| R2-14 | **Undocumented magnitude boundary:** above a cost of ~1e9, a `withCost` to the *same value* drifts by a millime, because the price is rebuilt from a 12-decimal rate. `NUMERIC(19,4)` permits 15 integer digits, so the type allows it. Repeated cost changes do **not** compound (verified). | **P3** |
| R2-15 | **A negative cost is accepted** and silently yields a negative selling price. `Money` must allow negatives for credit notes, but a product *cost* below zero is not a commercial state. Decide and state it, as `Rate` does for negative rates. | **P3** — **CLOSED.** A negative cost is refused by `InvalidCost`, checked in `ProductPricing`'s constructor so every path is covered. Ruled rather than left ambiguous: selling below cost is a negative *rate*, which `Rate` allows. |
| R2-16 | The pricing plan's `## Decisions Log` had no entry for the ruling that rewrote its own subject; `Money::negated`/`absolute`/`isPositive` and `Decimal::compare` have no direct tests. | **P3** — **PARTLY CLOSED.** `Money::negated`/`absolute`/`isPositive` and `Decimal::compare` now have direct tests, and the min-scale mutant dies. The pricing plan's Decisions Log entry was added. |

**Ten of the sixteen rows above are now CLOSED**, including the P0 and three of the four P1s, each with a
mutation or a live-database reproduction proving the fix holds. **R2-6, R2-9 and R2-10 are now closed too**, so thirteen
of sixteen are done:

- **R2-6 — CLOSED by unification, not by a test.** `ProductPricing` now *delegates* both formulas to
  `PriceCalculator`, which is marked as their single home; `ProductPricing` owns authorship and no
  arithmetic. [Verified: `grep -c 'markupMultiplier\|ratioTo'` → 0 in `ProductPricing`, 2 in
  `PriceCalculator`.] The agreement test stays as a regression guard.
- **R2-9 — CLOSED.** Wave 12 now carries the three-runner release matrix with what each runner needs, plus
  the four database controls and the owed schema gate; `CLAUDE.md`'s Flutter gate row names a build of all
  six targets and a desktop-sized golden; the header and all eleven skill banners say six targets and two
  admin interfaces.
- **R2-10 — CLOSED.** Wave 1's scope now names the `Product` entity and specifies its four pricing columns
  with types, including `authored_by` as non-null, plus the composite-key and RLS requirements every
  tenant-owned table in that wave must carry.

Still open, and each is genuinely not-now rather than deferred by convenience: **R2-12** (a savepoint
rollback reverts the GUC — unreachable until Doctrine introduces nested transactions, and the fix belongs
with the code that creates the exposure), **R2-13** (`new (expr)()` and
`DateTimeImmutable::createFromFormat()` evade the ambient-call gate) and **R2-14** (the >1e9 rate-precision
boundary, now pinned by a test but still a real limit).

**Round 3 must therefore run, and a clean round 3 alone still would not certify Wave 0** — the MAXIMAL
tier needs *two consecutive* clean rounds. Do not start Wave 1 before that.


### Certification round 3 — 20 findings, NOT clean

Against frozen `53ff9c6`: **10 correctness · 5 security · 5 completeness.** Reviewers were told what rounds
1 and 2 fixed and that a clean verdict was acceptable. It was not clean. Notably, the correctness reviewer
**disclosed a bug in its own harness before reporting** — its ANSI-blind output matching had made every
mutant falsely report "killed" — then rebuilt on exit codes and re-ran everything.

**Fixed in this round.** All ten correctness findings, three of five security, and four of five
completeness:

- **`Money::ratioTo()` ignored the caller's rounding mode and nothing noticed.** Mutants discarding it
  survived all 209 unit tests, because no test input rounded *up* and none was an exact tie. The tie case
  is the serious one: under a half-even mutant it **reproduces the exact F4 defect** that `authored_by` and
  the 12-decimal rate were built to eliminate. Two cases added, one per direction.
- **`Rate` had no magnitude guard**, while `53ff9c6` specified `profit_rate NUMERIC(15,12)` — twelve
  fraction decimals leave only **three** integer digits. A one-millime cost with a typed price of 1000.000
  derives 999999, which `withCost` makes the *authored* value: `ERROR: numeric field overflow`. `Money` had
  had this guard since round 1; the money half was fixed and the rate half created in the same session.
  Now `Rate::MAX_INTEGER_DIGITS = 3` in the constructor. — **SUPERSEDED at round 4: the bound was right in
  kind and wrong in size, and it was reached from a READ ACCESSOR. Now 15, column `NUMERIC(27,12)`, and an
  unrepresentable derived rate is reported as `null` rather than thrown. See R4-4.**
- **The `SET ROLE` bypass.** `rolsuper` and `rolbypassrls` are **not inherited**, so a role that is a
  *member* of a privileged or table-owning role read `f`/`f`, passed the check, and reached full
  cross-tenant read/write with one `SET ROLE`. And the precondition was created **by this session** —
  `infra/README.md` mandates a separate owning migration role, and the ordinary wiring grants it to the
  runtime role. The check is now a predicate over every role reachable via `pg_has_role(…, 'MEMBER')`,
  with an explicit refusal if the privilege set cannot be determined. [Verified against live roles: a
  member of a `BYPASSRLS` role is refused; the restricted role is accepted.]
- **The acquisition check had two unstated preconditions.** Inside a transaction, `''` may be a
  transaction-local shadow over a live session pin that returns on COMMIT — and the same call would throw
  on a correctly *bound* connection. It now refuses to answer inside a transaction. Separately, one
  post-acquisition session-scope write reopened the bypass in full with `bind()` still reporting success,
  so **`bind()` now re-checks before every write** rather than trusting a one-time check.
- **The ambient denylist had holes in all four of its own categories**, and since every one is a global
  symbol the layer gate was blind to them too. Added ~35 names — including the entire process family
  (`shell_exec`, `exec`, `proc_open`), which had been left open while `mail()` and `curl_init()` were
  banned — plus `include`/`require` and the backtick operator, which are language constructs the tokenizer
  walk skipped for the same reason `T_EXIT` was already special-cased.
- **The agreement test had become a tautology.** Once `ProductPricing` delegated, both sides of every
  assertion executed the same code: breaking *both* formulas still passed. Replaced with hand-computed
  literals. [Verified: breaking both now fails all 8 cases.]
- **Three `isWellFormed` guards were untested** — `multipliedBy` (the path a **line quantity** travels, so
  an absent quantity became a free line), `dividedBy`, and `Rate::fromFraction`.
- **Ten of the seventeen zero-decimal currencies had no scale assertion.** The 3-decimal set was pinned as
  a *set*; the 0-decimal set was enumerated ad hoc. Both it and the 4-decimal set are now pinned as sets.
- **`isLessThan`/`isGreaterThan` were never asserted at equality**, and they are what payment application
  will be built on: an inclusive `isGreaterThan` flags an exact-full payment as an overpayment.
- **`layer-dependencies.php` printed `OK — 0 file(s)`** after a directory rename, where two sibling gates
  hard-fail. It now refuses to pass having inspected nothing.
- **`InvalidCost`'s message stated a rule the domain does not hold** — a negative selling *price* is
  accepted by two other paths. Reworded, with the open question named.
- **The >1e9 boundary docblock said the drift "can lose a millime".** It can also **add** one, which is the
  direction that matters legally. Corrected.
- Stale statements: the three R2 rows now say CLOSED **in-cell** (they said it only in prose below the
  table — the *fifth* recurrence of the pattern in `CLAUDE.md` § Gotchas, so this sweep was done by `grep`
  rather than from memory); `CLAUDE.md`'s architecture row no longer calls closed work owed;
  `mobile/README.md`'s dangling "open question" is resolved; `README.md`, `VISION.md`, `CLAUDE.md`'s layout
  comment and `reimplementation-strategy.plan.md` no longer call desktop "planned" or "later"; the pricing
  plan's "Nothing here is implemented" is corrected.

**Owed from round 3.** R3-1 is closed (below); R3-2 and R3-3 remain, and they are why round 4 must run:

| # | Finding | Severity |
|---|---|---|
| R3-1 | ~~**`test-gates.sh` pins the fixture's instances, not the rule sets.**~~ **CLOSED, structurally.** Each gate now answers `--dump-rules` with its own rule data, and the meta-suite uses it two ways, because one alone is insufficient: **generated cases** — one execution per banned function, superglobal, instantiation, layer pair, SPDX root, extension and lock section — prove every present rule fires; and a **committed baseline** asserts each rule set is a *superset* of a named list, because generating cases from the data means deleting an entry would delete its own case. 37 → **183 cases**. All seven demonstrated neuterings now fail, plus two more found while building it: disabling the `T_EXIT` branch kept the suite green (the generated cases skip `exit`/`die` by construction), and so did disabling the `include`/`require` and backtick branches — so those five language constructs have explicit cases. [Verified: nine neuterings re-run individually, each reported.] Original finding: Seven neuterings each keep 37/37 — dropping `packages-dev` from the licence merge, slicing the package list to one, dropping two of three forbidden layers, deleting the `DateTime` row, disabling the `T_EXIT` branch, and two `SEARCH_ROOTS`/extension reductions. The last hides a header-less `.orm.xml`, which is exactly what Wave 1 must produce. Remedy is structural: drive the cases from the gates' own data — one per `BANNED_*` entry, one per `FORBIDDEN_BY_LAYER` pair, a fixture member per root and per extension, and a `packages-dev` case. | **P2** |
| R3-2 | **`Decimal::divide`'s working-scale `max()` term is only partly covered.** A case with a 15-decimal dividend was added, but the term deserves a systematic sweep across dividend/divisor/target-scale combinations rather than one example. | **P3** |
| R3-3 | **`Decimal::scaleOf` survives a mutant returning garbage for every dotless value.** Now has direct tests, but the reviewer's point stands: it survived by *luck of consumer shape*, and the next consumer — a formatter, or a `NUMERIC(19,4)` decimal-count check in the Doctrine type — would break silently. | **P3** |

Also still open from round 2: **R2-12** (savepoint rollback reverts the GUC — unreachable until Doctrine),
**R2-13** (`new (expr)()`, `new (self::CONST)()` and `DateTimeImmutable::createFromFormat()` evade the
ambient gate) and **R2-14** (the >1e9 boundary, pinned but real).

### Certification round 4 — 21 findings including a P0, NOT clean

Frozen at `f57910b`. Three lenses. The counts across the loop are 48 → 26 → 20 → **21**, and the *source*
shifted in a way the raw count hides: **round 4's most serious findings are in code round 3 itself wrote.**
The completeness lens named the pattern precisely — *"each round's fixes land without the test that pins
them"* — and it is the diagnosis this record exists to preserve, because it is a process defect rather than a
code defect. `CLAUDE.md` § "Quality gate" already requires a failing test *first* for money, tax and state
transitions; round 4's remedy extends that to **every fix**, and the evidence standard for a fix is now a
**killed mutant**, not a passing suite.

**The P0, and why it matters more than its one row suggests.**
`assertConnectionCannotBypassPolicies()` read exactly two catalogue attributes, `rolsuper` and
`rolbypassrls`, and was named as though it answered the whole question. It did not: a role that is neither —
so **accepted** by the check — reaches every tenant in two statements if it **owns** a policed table
(`SET ROLE owner; ALTER TABLE … DISABLE ROW LEVEL SECURITY`), or erases every tenant's rows in one if it
holds **TRUNCATE**. `FORCE ROW LEVEL SECURITY` does not help: it stops an owner *skipping* policies, not
*removing* them. Bypass #0 was listed in the class's own docblock and enforced nowhere.

Worse, the suite *demonstrated* the bypass while certifying against it: `TenantIsolationTest` ran
`DISABLE ROW LEVEL SECURITY` and `TRUNCATE` on the very connection it certified as unable to bypass, and a
comment in its fixture admitted *"the application connects as the owner"* against `infra/README.md`'s
requirement that it must not. Every isolation assertion in Wave 0 was therefore made against a role that
could step around the thing being asserted.

| # | Finding | Severity | Status |
|---|---|---|---|
| R4-1 | **The bypass check ignored table ownership and `TRUNCATE`** — the P0 above. | **P0** | **CLOSED.** `assertPolicedTablesAreBeyondThisRolesReach()` derives the table set from the catalogue (`relrowsecurity`), so a table added by a later wave is covered the day it is created and cannot be forgotten from a list, and refuses on reachable ownership, `TRUNCATE`, **or** RLS enabled without `FORCE`. Zero policed tables is refused, not reported clean. |
| R4-2 | **The reachability predicate named the wrong role.** `pg_has_role(current_user, …)` — but PostgreSQL authorises `SET ROLE` against **session_user**, so a connection arriving with `current_user` already changed (`options='-c role=…'` in the DSN, or `ALTER ROLE … SET role`, neither needing application code) enumerated a strictly smaller set than it could reach. | **P1** | **CLOSED.** Unioned over both `session_user` and `current_user`. |
| R4-3 | **Three of round 3's four tenancy fixes had ZERO coverage; the fourth was asserted message-blind.** Reverting the `pg_has_role` predicate, deleting `bind()`'s pre-write check, or replacing the whole privilege query with `SELECT false, false` each left `OK (17 tests, 23 assertions)`. The bracketed `[Verified against live roles]` in round 3's record was a one-off manual run no fresh clone could reproduce. | **P1** | **CLOSED.** The integration suite now runs against the **real four-role topology** (`scripts/dev/provision-test-database.sh`): a restricted runtime role, a separate owning role, a `BYPASSRLS` role and a role that *reaches* one by `SET ROLE`. 17 → **27 tests, 56 assertions**, and **nine mutants proven killed** — each fix individually reverted, suite red, control green either side. |
| R4-4 | **`Rate::MAX_INTEGER_DIGITS = 3` made a READ ACCESSOR throw on legally-persisted state.** `ProductPricing::fromNetPrice(0.001 TND, 1000.000 TND)` constructs and persists fine (`authored_by = net_price`, `profit_rate` NULL) and then every read of its rate raised `InvalidRate` — a 500 on a product page, not a validation error. The column was the thing that was wrong. | **P1** | **CLOSED.** Bound widened to 15 (matching `Money`), column `NUMERIC(27,12)`, and `PriceCalculator::profitRateFromNet()` **asks** `Rate::canHoldFraction()` and returns `null` — the same channel as an undefined rate — rather than throwing. Still reachable at the extremes of a 4-decimal currency, so it has a witness rather than being dead code. |
| R4-5 | **The caller's `RoundingMode` was discardable at SIX of nine entry points with no detection.** All six hard-coded to `HalfUp` at once → `OK (251 tests, 1247 assertions)` while eight probes gave wrong money, and `RoundingMode::Unnecessary` became a silent rounding at three of them, inverting its one guarantee. Every class tested *what* it computed; nothing tested that the policy it was handed was the policy it used — and `ProductPricing`'s tests called `PriceCalculator` directly, so they never proved forwarding at all. | **P1** | **CLOSED.** `RoundingModeIsForwardedTest` asserts the property across **all eleven** mode-taking entry points, twice each (two modes must diverge with exact literals; `Unnecessary` must refuse). **Nine mutants proven killed.** |
| R4-6 | **`eval()` bypassed the ambient gate completely** — `T_EVAL`, not `T_STRING`, so it was in `BANNED_FUNCTIONS`, advertised by `--dump-rules` as enforced, skipped **by name** in the generated loop, and matched by no branch. One `eval` evaded every ban in the table simultaneously. | **P1** | **CLOSED.** Own branch, own case. |
| R4-7 | **The round-3 vacuity fix reached one gate of three.** `no-ambient-calls` and `no-orm-attributes` — the two gates enforcing the domain-purity P0s, reading the *same* input — still printed "does not exist yet, nothing to check" and exited **0**. Relocating `api/src/Domain` left both unchecked with `gate:architecture` green. And the fix itself had no meta-case: deleting it kept 183/183. | **P1** | **CLOSED.** Both gates now fail on an absent *or* empty domain, `layer-dependencies` checks **per layer** (its total-count guard was blind to one layer of two disappearing), and there are six meta-cases. Its own total-count guard was then found **unreachable** and deleted rather than kept as dead code posing as a check. |
| R4-8 | **`spdx-headers.sh` could not see `api/`'s own files.** `xml` is in scope but `api/` is not a root, so the set complement was unscanned — and `api/phpunit.xml` was sitting in it with **no SPDX identifier**, a live licensing-invariant-8(c) miss, while the gate reported OK. The generated cases proved every listed root *is* scanned; nothing proved the roots *cover every file*. | **P1** | **CLOSED.** The missing inventory direction is now asserted against `git ls-files`, the header added, and the meta-fixture is a real git work tree so the coverage path is actually exercised rather than taking its not-a-work-tree branch in every case. |
| R4-9 | **`isLastDigitOdd` reached with one of five odd digits.** Deleting `'3'`, `'5'`, `'7'` or `'9'` left the suite green; each turns half-even into half-down for a fifth of all ties. | **P1** | **CLOSED.** All ten last digits, expectations computed independently in Python's `decimal`. Five mutants killed. |
| R4-10 | **`Decimal::divide`'s divisor sign normalisation was uncovered.** Deleting it left the suite green while five of seven modes gave wrong answers and one produced a fatal `ValueError` from the string `'--0.000'`. | **P1** | **CLOSED.** Twelve cases across both sign combinations, including the directed modes, which a magnitude-only implementation gets exactly backwards. |
| R4-11 | **Cross-currency guards on `ratioTo`/`compareTo` untested.** `100.000 TND` compared **equal** to `100.00 EUR`; `compareTo` underpins the predicates payment application will use to decide whether an invoice is settled. | **P1** | **CLOSED.** Every cross-currency operation, plus `equals()`'s deliberate answer-false asymmetry asserted as a decision. |
| R4-12 | **The one-multiplication rationale was a comment nothing asserted.** The two forms are provably identical under half-up and diverge only under **half-even** — the default of most accounting configurations. | **P2** | **CLOSED.** Both forms written out in the test, divergence asserted. |
| R4-13 | **`bind()`'s docblock and error message asserted something false.** A second `bind()` in one transaction trips the pre-write check — from inside a transaction a transaction-local write and a session-scope one read identically — and the message blamed "a DSN option, PGOPTIONS, or a session-scope write", sending the reader hunting something that was not there. The private helper's docblock called the check "sufficient" while the public one explicitly denied that same sufficiency. | **P2** | **CLOSED.** Rebinding is refused deliberately (statements under two tenants must not share an atomic unit), the message names both causes, and the guard's real limit is stated rather than overclaimed. |
| R4-14 | **`test-gates.sh` covered 41 of 97 banned functions by name, so 56 were deletable at 183/183** — generating a case from the rule data means deleting an entry deletes its own case. | **P2** | **CLOSED structurally.** Committed **minimum sizes** per rule set (`assert_at_least`), which closes the class for every future entry rather than for whichever names a reviewer happened to enumerate. |
| R4-15 | **`use function time as now;` renamed a banned function past both gates.** The imported name is followed by `as`, not `(`, so the "only an actual call counts" rule skipped it, and the alias is in no denylist. | **P2** | **CLOSED.** Checked at the import, where the real name is still written down; grouped imports covered by the same walk; a legitimate `use function bcadd` asserted not to trip. |
| R4-16 | **`'\time'` — a backslash-prefixed string callable — passed.** The string branch did not strip the backslash; the qualified-name branch eighteen lines away did. | **P2** | **CLOSED.** |
| R4-17 | **`no-orm-attributes-in-domain.sh` had no `--dump-rules`**, so five of its eight patterns had no generated coverage. | **P2** | **CLOSED.** Each pattern now ships a paired sample line that must trip it — a test cannot derive a matching sample from a grep regex, so the gate declares one — with a self-check that the two arrays cannot drift. |
| R4-18 | **`bind()`'s pre-write check is defeated by a transaction-local `''`** masking a live session pin, which returns on COMMIT. | **P2** | **OPEN, documented.** The actor able to do this can bind itself to any tenant directly, so it buys them nothing; the honest remediation is a re-check on connection *release*, which needs a connection lifecycle this wave has no ORM to hook. Stated in the code rather than overclaimed. **Owed at Wave 1.** |
| R4-19 | The probe table used `id integer PRIMARY KEY`, i.e. the canonical emitter's own witness did not satisfy the composite-key rule its docblock states. | **P2** | **CLOSED.** `PRIMARY KEY (company_id, id)`. |
| R4-20 | `mobile/README.md` still said "desktop later" against `VISION.md` — the **sixth** recurrence of correcting a statement somewhere other than where it is made. | **P2** | **CLOSED** in place. |
| R4-21 | The `pg_has_role` fix could not be tested with the existing single-role provisioning, and that constraint was recorded nowhere. | **P1** | **CLOSED.** `scripts/dev/provision-test-database.sh` provisions the four roles and documents what each one makes testable; `CLAUDE.md` § "Quality gate" points at it. |

**Also closed in passing:** R2-14 (the >1e9 rate-precision boundary) is now exercised by
`RoundingModeIsForwardedTest`'s `withCost` case, where a 12th-decimal rate difference is what decides the
third decimal of the price.

**Still open and honestly so:** **R2-12** (savepoint rollback reverts the GUC — unreachable until Doctrine),
**R2-13** (`new (expr)()`, `new (self::CONST)()` and `DateTimeImmutable::createFromFormat()` evade the ambient
gate), **R3-2** (`Decimal::divide` working-scale sweep), **R3-3** (`scaleOf` survives by luck of consumer
shape), **R4-18** above, and the composite-key schema gate — **P0 at the first Wave 1 migration**.

**Round 5 is owed, and it is the last before `CLAUDE.md`'s five-round cap.** Wave 0 remains uncertified:
MAXIMAL needs two *consecutive* clean rounds and no round has been clean. Do not start Wave 1. Note that the
bundle-integration precedent for stopping at five **does not apply** — it excluded code waves explicitly, and
R4-1 through R4-12 are code.

**The standing rule this round produced, which outlives it:** a fix is not delivered until a *mutant* proves
the fix load-bearing. Reverting it must turn the suite red. Round 4 closed 18 findings and every one of them
is backed by a re-run mutant, recorded above.

### Original scope, for reference — HISTORICAL, read the tables above for what is true

*Kept verbatim as the record of what this wave set out to do. Where it differs from the tables above,
the tables above are correct: notably it specifies a "default-on Doctrine filter", which was superseded
by row-level security, and `deptrac`/PHPStan, which could not be installed.*

**In:** repo skeleton (`api/`, `admin/`, `mobile/`, `infra/`) · Symfony 8.1.1 on PHP 8.5.8 · the
hexagonal layer layout · **`Money` value object** over `NUMERIC(19,4)` with explicit rounding on every
lossy operation · **`TenantContext` + `TenantIsolationStrategy`** seam with Mode A (shared DB,
default-on Doctrine filter) implemented · UUIDv7 IDs · RFC 9457 error shape · Doctrine Migrations ·
PHPUnit · **the architecture-fitness tooling that `CLAUDE.md` currently records as owed**: `deptrac`
for the inward-only rule, a PHPStan banned-function rule for ambient `time()`/`random_int()`/`getenv()`
in `Domain/`, and grep gates for `#[ORM\` under `Domain/` and for the SPDX header.

**Also in Wave 0, because the developer ruled them first-version and they are cross-cutting:** the
**i18n/l10n scaffolding** (locale catalogues with mechanically-checked key parity, locale-aware
formatting that honours each currency's own scale) and the **a11y harness** (`axe-core` in the admin
gate, semantics tests for Flutter). Both are cheap now and expensive to retrofit across every screen
and template later. `docs/spec/pricing-vectors.json` also lands here — the shared arithmetic fixture all
three tiers test against.

**Out:** any entity beyond what the money and tenancy tests need. No invoices yet.

**Acceptance:** `Money` arithmetic is exhaustively tested including repeating decimals and a
three-decimal currency; a cross-tenant read is proven impossible by a test that *fails* when the filter
is disabled; `deptrac` fails on a deliberately-introduced outward `use`; the SPDX gate fails on a file
missing its header. **Every one of those four is a test that must be watched failing first.**

**Why this is Wave 0 and not part of Wave 1:** the two P0 classes for this product — wrong money and
cross-tenant reads — are decided here. `CLAUDE.md` § Gotchas records both as day-zero rulings
precisely because they are unfixable later.

## Wave 1 — Client & the invoice core

**In:** Client (+ contacts) · **Product** · Invoice with line items · the **calculation kernel** (line
totals, discounts, taxes, document totals) as **one parameterised implementation** — inclusive vs exclusive
tax is a *flag*, never a parallel class hierarchy · invoice state machine behind a **transition guard**, no
status written by assignment · numbering with per-tenant counters.

**The `product` table's pricing columns are already decided, so its migration has no choices to make**
(recorded here because a certification round found this specified only in the pricing plan, leaving the
wave that writes the migration with no record of it):

| Column | Type | Why |
|---|---|---|
| `cost` | `NUMERIC(19,4)` | never a float; see `CLAUDE.md` § Gotchas |
| `profit_rate` | `NUMERIC(27,12)` nullable | 12 fraction decimals (`Rate::FRACTION_SCALE`) plus 15 integer digits (`Rate::MAX_INTEGER_DIGITS`, matching `Money`'s). **Widened from `NUMERIC(15,12)` at round 4**: three integer digits was too narrow, and a one-millime cost with a typed price of 1000.000 derives 999999. Null when the price was the authored field |
| `net_price` | `NUMERIC(19,4)` nullable | null when the rate was the authored field |
| `authored_by` | non-null enum `('profit_rate','net_price')` | **the load-bearing one.** Without it both fields look equally real and the derived one gets rebuilt from a rounded copy — see `pricing-and-documents.plan.md` § F4 |

Every tenant-owned table in this wave also carries `company_id`, `PRIMARY KEY (company_id, id)`, foreign
keys and unique constraints on **both** columns, and the three RLS statements from
`PostgresRowLevelSecurityIsolation::policySqlFor()`. Not stylistic: FK and uniqueness checks run with row
security bypassed, so a single-column FK lets one tenant delete another's rows.

**Out:** quotes, credits, payments, PDF, e-invoicing.

**Acceptance:** the rounding order is deliberate, documented and tested (`round(sum(x))` vs
`sum(round(x))` on the same fixture); no illegal transition is reachable; a paid invoice cannot be
edited.

## Wave 2 — Quotes, credits & the shared document machinery

**In:** Quote · Credit · conversion (quote → invoice) · the shared document abstraction. **Full-set
coverage is the theme:** anything true of Invoice must be true of Quote and Credit, or explicitly not.

**Acceptance:** a calculation change applied to one document type is proven applied to all three — the
single most common finding class on the completeness lens.

## Wave 3 — Payments

**In:** Payment · partial payments · overpayment · applying a credit · refunds (full and partial) · one
payment split across several invoices · balance recomputed rather than incrementally adjusted.

**Out:** gateways. This wave is the arithmetic and the ledger only.

**Acceptance:** `sum(applied) <= payment.amount` and `balance = total - sum(applied)` hold after every
operation; no drift after a long sequence of partial payments and refunds.

## Wave 4 — PDF documents

**In:** the rendering pipeline · one template · a placeholder/variable contract · `live_preview` for an
**unsaved** entity (a real backend feature, not a client one).

**Acceptance:** the stored total, the number on the PDF and the number a validator recomputes all
agree. **Visual evidence delivered with `SendUserFile` in the same turn** — a PDF is a legal document
and no unit test looks at the pixels.

## Wave 5 — Tax & e-invoicing (France AND Tunisia, both in this wave)

**RULED 2026-07-29: both jurisdictions ship together.** This resolves the inconsistency flagged earlier
(the wave originally said "France first", written before TND and the Tunisian stamp duty were ruled).

Doing two jurisdictions at once is **more work than one but less than one-then-another**, and that is the
argument for it: two live jurisdictions from the start force the tax layer to be genuinely generic
instead of France-shaped with Tunisia bolted on. They differ in exactly the ways that expose a bad
abstraction — different VAT rates and bands, a fixed stamp duty in one and not the other, different
e-invoicing formats and transports, different currencies (EUR 2 decimals, TND 3), and different
languages. If the charge engine and the format layer survive both, they will survive the third.

**In, regardless of order:** the generic charge engine from `pricing-and-documents.plan.md` (per-line and
per-document VAT at differing rates, plus fixed absolute charges) · reverse charge · exempt vs
zero-rated, which are legally distinct and routinely conflated in code · one jurisdiction's rules and one
e-invoicing format, done properly end to end.

**Out:** every other jurisdiction and format. Deferred deliberately — and having done two, adding a
third should be configuration plus a format adapter, never a rewrite. If it is not, the abstraction is
wrong and that is the signal to stop and fix it.

**Acceptance:** generated XML passes a real schema/schematron validation; the PDF and the XML never
disagree on a figure.

## Wave 6 — Recurring billing

**In:** recurring invoices · the scheduler · timezone and DST handling · month-end dates (Jan 31 → Feb)
· **idempotency** — a scheduler that runs twice must not bill twice.

## Wave 7 — Auth, permissions & the API contract

**In:** authentication · API tokens (hashed at rest, constant-time comparison) · Symfony Voters over a
real `role_permissions` table · the OpenAPI spec as the contract SSOT · contract tests pinning the
shape.

**Note:** this is where the API contract becomes load-bearing. After the Flutter client ships, the
contract is frozen for anyone who has not updated.

## Wave 8 — Angular admin

**In:** the admin client over the Wave 7 contract · a generic entity-config service rather than
per-entity duplication · typed client generated from or checked against the OpenAPI spec.

**Acceptance:** visual evidence delivered, not just captured.

## Wave 9 — Payment gateways

**In:** Stripe · SEPA direct debit. Tokenization, webhooks with **signature verification and
idempotency**, refunds, SCA.

**Out:** the other ~19 gateways.

## Wave 10 — Client portal

**In:** view and pay, unauthenticated by link. **Treated as hostile surface:** unguessable expiring
tokens, rate limiting, and no field rendered that should not be.

## Wave 11 — Flutter client — all six targets

**In:** written from scratch, 100% ours (licensing invariant 3). **Android · iOS · Linux · Windows ·
macOS · Web** (developer ruling, 2026-07-29). Flutter **3.44.8** / Dart **3.12.2**, the current stable.

Mobile is built first, but the six-target shape is designed in from the start rather than retrofitted,
because it changes two things structurally: **CI is a three-runner matrix** (you cannot cross-compile —
Windows needs Windows, iOS and macOS need a Mac), and every platform capability the client needs has six
implementations. `mobile/README.md` enumerates both, plus the signing and distribution costs.

**RULED 2026-07-29: Flutter Web ships too — twes-in offers TWO admin interfaces, Flutter and Angular.**
Deliberately the same shape as Invoice Ninja, which offers a Flutter client and a React one; ours are
Flutter and Angular.

The cost is accepted, not hidden: **every admin screen exists twice**, once in Dart and once in
TypeScript, forever. Two consequences follow and both are load-bearing rather than incidental:

1. **The API contract and the shared fixtures matter more, not less.** They are the only thing keeping two
   independent front ends numerically and behaviourally consistent. Both tiers consume
   `docs/spec/pricing-vectors.json`, and a contract change that does not reach both is a
   `completeness-reviewer` P0.
2. **The public client portal (Wave 10) stays server-rendered or Angular, NOT Flutter Web.** Different
   requirement, not a preference: the portal is unauthenticated, public, opened from an e-mail link by
   someone who may be on a phone on mobile data, and Flutter Web ships a large bundle before it renders
   anything. "Two admin interfaces" is a choice about the admin, and the portal is not an admin surface.

### Certification round 5 — 29 findings, FIVE P0s, three of them proven cross-tenant breaches

Frozen at `2e56c9f`. Counts across the loop: 48 → 26 → 20 → 21 → **29**. The count rose, and the reason is
recorded honestly: **two entire tiers were scaffolded between rounds 4 and 5**, which widened the search
surface mid-loop. Both the security and completeness lenses said so independently. That was a sequencing
mistake — scaffolding belonged either before the loop opened or after it closed.

**The three proven breaches.** Each came with a working exploit, not a theory:

1. **`REPLICATION` was never checked.** A role with `LOGIN REPLICATION` and nothing else — not superuser, not
   `BYPASSRLS` — passed the entire chain. Its SQL *is* correctly policed, which is exactly what made the
   clean verdict convincing, and the same credentials ran `pg_basebackup`; both tenants' rows came back out
   of the copied heap files. Row security has no bearing on a physical read. Worse, the provisioning script
   spelled out `NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB` under a comment promising that re-running it
   "repairs a role whose attributes drifted" — and omitted the one attribute that most defeats the design.
2. **`TRUNCATE` was checked with inheritance semantics while `SET ROLE` uses membership semantics.**
   `has_table_privilege()` resolves privileges the way PostgreSQL applies them *now*; a grant made
   `WITH INHERIT FALSE` — the PG16+ way to say "hold this deliberately, not by default" — is invisible to it
   and one statement from the privilege. Every tenant's rows were erased through that gap with
   `current_user == session_user` throughout, so no DSN trick was involved. The ownership check *two lines
   above* had the semantics right, which is precisely what made the asymmetry easy to miss.
3. **`relkind = 'r'` excluded partitioned tables.** A partitioned parent carries `relkind = 'p'` with
   `relrowsecurity = t` (its partitions carry `f`), so ownership, `TRUNCATE`, `FORCE` and the non-vacuity
   count all skipped it — and it counted for nothing toward "at least one policed table". Cross-tenant read
   **and** write, against a clean verdict. Verified that `'p'` is the only hole: views and materialised views
   cannot carry RLS at all.

**The two process P0s, and these are the ones worth remembering.**

4. **`layer-dependencies.php` exited 1 from a fresh clone** and 0 in the author's tree, because
   `api/src/Application` is an empty directory git cannot track — and the per-layer existence check that
   failed was *round 4's own fix*. So every "six gates green" claim across five rounds was produced in an
   environment no clone reproduces. **This is round 1's finding #1 recurring verbatim**, and the `.gitkeep`
   remedy from then was never extended to the directory a later gate started requiring. Now applied to all
   four empty directories and verified by running the gates from `git checkout-index`, not from the tree.
5. **`php-cs-fixer` exited 8, and had for three commits.** One token, introduced in the Angular scaffolding
   commit, never re-checked — while `CLAUDE.md` § "Git autonomy" requires a green gate before every commit
   and three commit messages asserted green.

**Also closed:** the policy *expression* is now read rather than only the two catalogue flags
(`ENABLE` + `FORCE` + `USING (true)` was a clean verdict on a table readable and writable across tenants);
**four money-path parameters** (`Money::multipliedBy`, `::dividedBy`, `Rate::fromPercentage`,
`::fromFraction`) still took a bare `string|int` and silently truncated a float — round 1 fixed one of five
sites, and `Rate::fromFraction(0.30)` returned a rate of **zero**, the F4 defect arriving through a different
door; and `GRANT CONNECT` was inert because PostgreSQL grants it to `PUBLIC` on every new database.

**Provisioning gained two probe roles** — `twes_replicator` and a `twes_truncator` granted `WITH INHERIT
FALSE` — on the principle round 4 established: *a fixture that cannot express a dangerous shape cannot detect
it.* Six roles now. 27 → 32 integration tests; 296 → 328 total, 1410 assertions.

**Still open after round 5, and each stated where it actually is:**

| # | Owed | Where |
|---|---|---|
| R5-1 | The **ownership** reachability axis (`MEMBER` vs `USAGE`) has no live test: the existing test connects *as* the owner, and a role always satisfies `pg_has_role` on itself under any mode, so the both-halves mutant survives. Closing it needs a seventh role with `ADMIN OPTION` plumbing so a table owner can be *reached* without being *assumed*. The sibling TRUNCATE axis — where the P0 actually was — **is** covered. | **CLOSED. A seventh role (`twes_probe_owner`, granted to the owning role `WITH ADMIN OPTION`) lets a test own a table with a role the runtime role can *reach* but does not *inherit*. The `MEMBER` → `USAGE` mutant now dies — it had survived every previous round.** |
| R5-2 | `bind()`'s read-back mismatch throw has no coverage; the test named for it re-queries the GUC itself and never drives the branch. Remedy is the pattern this class used twice already: extract to a pure predicate. | **CLOSED for the comparison, OPEN for two lines of wiring. The read-back logic is extracted to the pure `describeBindingMismatch()` and covered for every way a value can fail to take (different tenant, empty, NULL, `false`, loose-comparison trap). The remaining residue is honest: `if (null !== $mismatch) { throw }` is still deletable with the suite green, because provoking a genuine mismatch would need PostgreSQL to lie about its own `set_config` return value.** |
| R5-3 | `assertPolicedTablesAreBeyondThisRolesReach()`'s `return \count($tables)` is hardcodable to `1` with the suite green, so the count assertion does not do what its comment claims. A second policed table in the fixture fixes it. | **CLOSED. A second policed table in the fixture, so `return \count($tables)` cannot be hardcoded to `1`; the mutant now dies, and the partitioned-table case asserts 3.** |
| R5-4 | The **licensing** gate is the only gate with no `--dump-rules` and no size baseline, so `GPL-3.0` can be added to `PERMISSIVE` with all 226 meta-cases green. Growth is the dangerous direction, exactly as for the SPDX exclusion list, which *does* have a maximum assertion. | **CLOSED. The gate answers `--dump-rules`, and the meta-suite asserts both a minimum and a **MAXIMUM** on `PERMISSIVE` — growth is a legal act here, not a build fix. Admitting `GPL-3.0`/`AGPL-3.0`/`MPL-2.0` now fails, as does removing `MIT` or dropping a lock file. One generated case per permissive identifier proves each is actually honoured.** |
| R5-5 | `Decimal::add()`/`subtract()` silently truncate at a narrowing scale, outside `applyRounding()`, contradicting the class's own docblock. All four current callers are safe by construction — operands already at the target scale — which is a property of today's callers, not of the method. | **CLOSED. `add()` and `subtract()` compute at full width and **refuse** a narrowing scale via `exactlyAt()`, so no digit is discarded outside `applyRounding()`. A `LogicException`, because reaching it means a caller asked an exact operation to round. All four existing callers were unaffected, which confirms they were safe by construction.** |
| R5-6 | `ProductPricing::netPrice()` throws from a read accessor when `cost × (1 + rate)` exceeds `Money`'s bound — the mirror of R4-4, which was closed on `profitRate()` only. Matching the two bounds does not constrain their *product*. | **CLOSED, and note the remedy is the OPPOSITE of R4-4's deliberately. A rate that cannot be derived is legitimately absent, so null is honest; a price always exists, so the invalid combination must not be **constructible**. `fromProfitRate()` now refuses when `cost × (1 + rate)` exceeds `Money`'s bound — at the edit, where it is an actionable validation error, rather than from a getter. All three of round 5's reproduction triples now refuse at the edit.** |
| R5-7 | `spdx-headers.sh` cannot see `.html`, `.scss` or `.js`, so `admin/src/app/app.html` — which we authored, replacing the generated welcome page — carries no identifier. The **extensions** set complement is the direction still unasserted after R4-8 closed the *roots* one. | **CLOSED. `html`, `scss`, `css` and `js` added to `EXTENSIONS`, which gates **both** the header check and the coverage check; four authored Angular files gained headers and `admin/eslint.config.js` joined `SEARCH_FILES`. Four generated meta-cases. Deliberately still absent, with reasons stated in the gate: `json` (no comment syntax), `md`, and binaries.** |
| R5-8 | `pricing-and-documents.plan.md`'s headline `### The formula` block still reads `cost + (cost × profit_rate)`, the two-step form, while three other places say `cost × (1 + rate)`. Identical under HalfUp — every fixture vector is HalfUp — so a client implementer would pass every vector and diverge the day a company configures half-even. Round 1 owed this and it dropped off the register. | **CLOSED at source. The headline `### The formula` block now shows `cost × (1 + profit_rate)`, with the half-even divergence witness written out beside it — because every fixture vector is half-up, so a client implementer would have passed the whole fixture with the wrong form.** |
| R5-9 | Owed items whose pointer leads somewhere they are not recorded: `application/wasm` (→ `infra/README.md`), the external-origin test (→ `mobile/README.md`), the Flutter transitive-licence walk (→ this plan). Round 2 filed this exact shape once already. | **CLOSED. `application/wasm` and four more controls are rows in `infra/README.md`; the external-origin test, release signing, keystore storage and the bundle identifier are rows in `mobile/README.md`. Each is now recorded where its pointer said it was.** |
| R5-10 | Both client tiers have **no security row** in their gate-condition tables. Concretely: no CSP anywhere and `@angular/build`'s `autoCsp` left off; Android release variant signed with the **published debug key**; token-in-keystore stated in prose, not gated. | **CLOSED. `autoCsp` enabled in `angular.json` — verified rendering clean in a real browser with **zero** CSP violations — and the Android release variant now reads `key.properties` and **fails** when it is absent rather than falling back to the published debug key. Four security gate rows added to each tier's README.** |
| R5-11 | `.gitignore` misses `.npmrc`, `api/config/secrets/**`, `*.p8`, `*.mobileprovision` — in a repo where `git add -A` is a standing autonomous operation. | **CLOSED. All eight credential shapes round 5 proved trackable are now ignored, verified with `git check-ignore`.** |
| R5-12 | **The seventh recurrence of correcting a claim somewhere other than where it is made:** `README.md:9` and **11 skill banners** still say the client tiers do not exist, and `.claude/skills/sweep/SKILL.md` reads that as authorisation to skip the Angular and Flutter review dimensions — including visual evidence — for tiers that are present and building. | this table |
| R5-13 | Stale counts: `CLAUDE.md` writes "183 cases" (actual 226) **377 lines after stating that no count may be written there**, and this plan's Wave 0 "Delivered and verified" headline still says 251 tests / 183 gate tests. | this table |

Plus, unchanged and still accurate: **R2-12**, **R2-13**, **R3-2**, **R3-3**, **R4-18**, the composite-key
schema gate (**P0 at the first Wave 1 migration**), PHPStan/deptrac, and — new from the partitioned-table
finding — **RLS on a partitioned parent does not police direct access to a partition**, so a Wave 1
partitioned tenant table needs the policy on every partition. That belongs beside the composite-key rule in
`policySqlFor()`'s docblock.

**ALL THIRTEEN R5 ITEMS ARE NOW ADDRESSED** (developer ruling, 2026-07-29: *"option 1"* — fix all thirteen,
then one round on a frozen tree). Eleven are closed with a killed mutant or a verified check; R5-12 and R5-13
were closed earlier in the round. **Two residues are recorded rather than hidden:** R5-2's two-line
throw-wiring (a genuine mismatch would need PostgreSQL to lie), and the single-half `session_user`/`current_user`
mutants on the ownership axis, which are *equivalent* mutants because the tests run with
`current_user == session_user` — the asymmetric case is covered on the role-attributes axis by
`testAConnectionWhoseSessionUserCanReachBypassRlsIsRefused`.

**ROUND 5 IS THE CAP.** `CLAUDE.md` § "Certification ladder" requires two *consecutive* clean rounds and caps
the loop at five; there have been **zero** clean rounds. The required next step is the plain-text escalation,
not a declaration of victory — and the bundle-integration precedent for stopping at five explicitly excludes
code waves.

**What the loop has actually bought, stated so the decision can be made on evidence.** Five rounds have found
**five P0s that a green test suite did not**: two tenancy P0s (round 4's ownership/TRUNCATE, round 5's three),
and a float-truncation P0 that silently turned a 30% margin into 0%. Three of round 5's were demonstrated
with working exploits against a live database. Against that: the counts are not converging, and the dominant
*category* of finding has shifted from code to record-keeping — 6 of the completeness lens's 8, and 8 of the
13 R5 items above, are documentation or coverage rather than wrong behaviour.

## Scaffolding findings — 2026-07-29, from screenshotting the builds rather than running the tests

Both client tiers were scaffolded with their official generators on 2026-07-29 (see each tier's README).
Capturing the required before/after visual evidence found **two defects that every automated check passed**,
which is the clearest justification of `CLAUDE.md`'s visual-evidence rule the project has produced so far.

**1. Flutter web fetched Roboto from `fonts.gstatic.com` at runtime — a GDPR exposure, not a packaging
detail.** `flutter analyze` clean, `flutter test` green, `flutter build web` successful, and the page rendered
**a blank surface**: the theme painted, the widget tree built, and no text appeared. [Verified: a headless
Chromium load of the release build issued exactly one external request, to
`https://fonts.gstatic.com/s/roboto/v32/…woff2`.] Three consequences, worst first: every page load sends the
visitor's IP to Google, which LG München I (3 O 17493/20) held to be an unlawful transfer without consent —
and this product targets EU invoicing, so its own client leaking client IPs is indefensible; no network means
no text; and Playwright's `networkidle` never fires, making any screenshot test flaky by construction.
**Fixed** by vendoring Roboto (Apache-2.0, 3 weights) and declaring it in `pubspec.yaml`. The release build
now issues **zero** external requests. **Owed at Wave 11:** a test that fails if the built web bundle
references any external origin — the fix is only durable if a future dependency cannot silently reintroduce
one.

**2. `.wasm` must be served as `application/wasm`, or CanvasKit never initialises.** Serving the build through
a default `python3 -m http.server` left the app stuck at Flutter's typography-measurement bootstrap with no
`flt-glass-pane` and no canvas — because `WebAssembly.instantiateStreaming` rejects any other MIME type, and
it does so without a console error. **Owed at Wave 12:** the nginx (or equivalent) config must set
`application/wasm` for `.wasm`, and the infra gate must assert it. A wrong MIME type here is a blank
application in production with a green CI.

Also decided while scaffolding, and worth recording because it constrains Wave 11 rather than this commit:
**app bundle identifiers are compile-time and are NOT covered by the branding config seam.** `com.twesin` is a
placeholder; no domain is owned yet. It must be set to a reverse-DNS name actually controlled **before any
store submission**, because changing it after publication means a new listing and losing every installed user.
That is a Wave 11 gate condition.

## Wave 12 — Infra & CI

**In:** `infra/` written from scratch — Dockerfiles, compose, deployment. CI mirroring the quality
gate tier by tier, every job commented with why it exists and what breaks without it.

**The Flutter release matrix, which is the one part of this wave that is not a choice.** Builds cannot be
cross-compiled, so the six ruled targets need three runners and a release is six artifacts:

| Runner | Builds | Also needs |
|---|---|---|
| `ubuntu-latest` | Linux · Android · Web | GTK dev headers; Android SDK; a Play Console registration |
| `windows-latest` | Windows | a code-signing certificate, or every user sees a SmartScreen warning |
| `macos-latest` | macOS · iOS | an Apple Developer membership; notarisation as well as signing |

**Also in:** the **database controls** `infra/README.md` enumerates, because RLS does not cover them and
none is enforceable from application code — a non-superuser **and non-owner** application role,
`REVOKE TRUNCATE`, and a connection string carrying no pre-set `twes.tenant_id`. A migration role that
owns the tables, separate from the runtime role. And the **schema gate** owed since Wave 0: every table
with a tenant column must have `ENABLE` + `FORCE ROW LEVEL SECURITY`, a policy with `USING` and
`WITH CHECK`, and composite keys — that one becomes a P0 the moment Wave 1 writes its first migration, so
it may well need to land earlier than this wave.

**Note:** the *topology* (php-fpm + nginx + db + redis + queue + scheduler + headless Chrome) is an
idea and free to reuse; upstream's **files** are GPL-2.0 and must never be copied.

---

## Explicitly out of scope for the first release

Recorded so it is a decision and not a silence: the other ~19 payment gateways · 7 of 8 e-invoicing
standards · 35 of 36 tax jurisdictions · bank feeds and transaction matching · QuickBooks sync · the
subscription/payment-link product · the visual template editor · e-signature · Elasticsearch · the
report builder · tasks/projects/expenses · purchase orders · vendors · 43 of 45 locales · database
sharding.

Upstream reached ~344k lines of backend PHP over twelve years; matching that is **25–40 person-years**.
The waves above are roughly **18–30 person-months**. Every item above is deferrable and none is
forgotten.

## Developer feature additions (2026-07-29)

Baseline behaviour is *"what Invoice Ninja does"* — legitimate, because behaviour and functionality are
not copyrightable; only expression is (licensing invariant 2). These four are **additions or
modifications on top of that baseline**, to be specced in detail when their wave starts.

**F1 — Merge several invoices of the same client into one.** Same client is a hard precondition.
Detailed spec at Wave 2 (shared document machinery). *RULED 2026-07-29: drafts only — `pricing-and-documents.plan.md` § Decisions Log.*

**F2 — Create an invoice from a quote, if one does not already exist.** One-to-one link, so converting
twice does not silently produce a second invoice; the second attempt returns the existing one.
Wave 2.

**F3 — Create a delivery note from an invoice while the invoice is still a draft.** Draft-only is a
deliberate constraint from the developer. *RULED 2026-07-29: its own persistent, independently numbered document — same log.* Wave 2, or Wave 4 if it turns out to be a rendering concern.

**F4 — Profit rate on the product, so the selling price is computed from cost + profit rate + VAT.**
This is the most arithmetic-sensitive addition in the list and has **no upstream behaviour to inherit**
— it is genuinely new, so every rule for it is ours to decide. Wave 1, alongside the calculation
kernel, because it feeds line pricing. Non-negotiable regardless of the open decisions: the **computed
selling price is snapshotted onto the invoice line** at issue time. A later change to a product's cost
or profit rate must never retroactively alter an issued document.

## Awaiting the developer — **ALL FIVE NOW RULED, 2026-07-29**

This section listed five open decisions; every one has since been settled and the rulings live in
`pricing-and-documents.plan.md` § Decisions Log. Kept as a record rather than deleted, with the outcomes:
profit-rate formula → **markup on cost**, VAT on the profit-inclusive net; invoice merging → **drafts
only**; delivery notes → **their own persistent, independently numbered documents**; multi-currency →
**in from the start, default TND**; VAT rounding → **once per rate group on the summed base**.

Nothing here is awaiting the developer. The open items are in the owed table under Wave 0.

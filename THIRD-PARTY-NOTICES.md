# Third-party notices

Every third-party dependency of twes-in, with its licence, recorded **before** it is added — not
audited afterwards. This file is a deliverable, not a formality: twes-in is dual-licensed
(`LICENSING.md`), and dual licensing fails the moment an incompatible dependency enters the tree.

## The rule: PERMISSIVE DEPENDENCIES ONLY

Before adding any dependency: establish its licence, confirm it is **permissive**, and add a row here
in the same change.

**Permitted for anything distributed:** MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC, 0BSD, MIT-0,
CC0-1.0, BlueOak-1.0.0. That is the whole list, and it is stated in the same closed, ordered form in
`CLAUDE.md` invariant 8(a), `LICENSING.md`, `README.md`, `docs/plans/reimplementation-strategy.plan.md` and
`.claude/agents/completeness-reviewer.md`; `scripts/gates/test-gates.sh` fails if any of those six drifts from
the others or from the gate, **and** asserts that the set of documents stating the rule has not grown — a
hand-listed cross-check cannot notice a seventh surface appearing, which is how the fifth and sixth were each
missed for a round.

Two narrow additions, neither of which leaks into the list above:

- a **dev-only** dependency may also carry **CC-BY-4.0** or **CC-BY-3.0** — content licences tolerated only
  for build-time reference data that is never shipped;
- a vendored **font asset** may also carry **OFL-1.1** (developer ruling, 2026-07-30).

An OFL-1.1 *code package* is refused, as is a CC-BY *runtime* dependency.

**The test is NOT "AGPL-compatible"** — and getting this wrong is how the commercial licence dies.
twes-in is dual-licensed (`LICENSING.md`), so every dependency must be conveyable under **both**
branches. A copyleft dependency satisfies the AGPL branch and **fails the commercial one**: we cannot
relicense a third party's copyleft code to a customer who is specifically buying an escape from
source-disclosure obligations. `LICENSING.md` states this principle for *contributions* — copyright
must stay wholly owned — and it applies with equal force to *dependencies*.

So the following are **excluded even though they are AGPL-compatible**:

| Licence | Compatible with AGPL branch? | Why still excluded |
|---|---|---|
| **GPL-3.0-or-later** | Yes | Cannot be conveyed under the commercial branch. |
| **AGPL-3.0** | Yes | Same. |
| **LGPL** (any version) | Usually | The familiar "dynamic linking" safe harbour is a C/ELF concept. PHP `require`, Dart `pub` and npm have **no dynamic-linking boundary**, so whether importing an LGPL library forms a derivative is unsettled — and unsettled is not good enough for the commercial branch. Excluded until a lawyer says otherwise. |
| **MPL-2.0** | Yes | File-level copyleft; the per-file reciprocity still binds a commercial licensee. Ask before considering. |

**Never, under either branch:**

| Licence | Why |
|---|---|
| **GPL-2.0-only** | No "or later" clause, so not even AGPL-compatible. On `invoiceninja/dockerfiles`: it carries GPL-2.0 text with no per-file version notice, so "only" vs "or later" is ambiguous under GPL-2.0 §9 — we take the conservative reading, and copying is excluded anyway because the copied file becomes copyleft either way. See `LICENSING.md`. |
| **Elastic License 2.0** | Not open source; forbids hosted-service provision. Invoice Ninja's API and web UI. |
| **BUSL / SSPL / "source-available"** | Not open source; incompatible with AGPL redistribution. |
| Anything unlicensed or licence-unclear | Absence of a licence means no grant at all. |

If a genuinely necessary library is copyleft-only, that is an **`ask-human` decision**, not a judgement
call: the options are find a permissive equivalent, isolate it behind a process boundary, or accept a
documented limit on the commercial branch. Never resolve it silently.

Note that **using the same library upstream uses is not copying upstream** — these packages have
their own independent licences and no relationship to Invoice Ninja's. That is the legitimate
shortcut for the hard domain problems (Factur-X, UBL, Peppol, XAdES, HTML→PDF).

## PHP / Symfony (API)

Locked in `api/composer.lock`. **INSTALLED, and this paragraph said *"Not yet installed — GitHub egress in
the development container is restricted to this repository, so Composer cannot fetch dist archives"* for
four days after it was.** The 403 is per-repository AUTHORIZATION on three GitHub hosts, not a network
block: `composer config -g use-github-api false && composer config -g github-protocols https` then
`composer install --prefer-source` installs the whole locked tree, dev dependencies included
(`CLAUDE.md` § Gotchas carries the full correction).

The full locked tree is **74 runtime + 46 dev packages**, distributed **MIT ×96, BSD-3-Clause ×24** —
every one permissive. **The previous figures — "52 runtime + 54 dev … MIT ×82, BSD-3-Clause ×23,
Apache-2.0 ×1" — no longer reproduce**, and are quoted rather than silently replaced because this is the
file licensing invariant 8(a) names as the record: `deptrac` and `phpstan/phpstan` left `require-dev` on
2026-08-02, and Symfony packages arrived with the application. Derive these numbers from the lock rather
than trusting this sentence; the gate does. That is not a claim from inspection: it is checked on every gate
run by `scripts/gates/dependency-licences.php`, which reads the lock and fails on anything outside the
allowlist. Direct requirements:

| Package | Version | Licence | Purpose | Verified |
|---|---|---|---|---|
| `symfony/framework-bundle` | v8.1.2 | MIT | the framework | composer.lock |
| `symfony/console` | v8.1.2 | MIT | CLI commands | composer.lock |
| `symfony/runtime` | v8.1.0 | MIT | front-controller bootstrap | composer.lock |
| `symfony/dotenv` | v8.1.2 | MIT | environment loading | composer.lock |
| `symfony/twig-bundle` | v8.1.2 | MIT | the HTML documentation UI at `/api` — Twig renders API Platform's Swagger UI template | composer.lock |
| `symfony/twig-bridge` | v8.1.2 | MIT | pulled in by twig-bundle; Symfony's Twig integration | composer.lock |
| `twig/twig` | v3.28.0 | **BSD-3-Clause** | the template engine itself. Permissive and on the permitted list; noted in bold only because it is the one new entry that is not MIT | composer.lock |
| `symfony/asset` | v8.1.0 | MIT | resolves `asset()` to the LOCALLY SERVED Swagger UI css/js/fonts under `public/bundles/apiplatform/`. Without it the documentation page has no stylesheet | composer.lock |
| `symfony/yaml` | v8.1.2 | MIT | configuration | composer.lock |
| `symfony/translation` | v8.1.4 | MIT | i18n — FR/AR/EN catalogues | composer.lock |
| `symfony/uid` | v8.1.0 | MIT | UUIDv7 generation, and the `Uuid` type on every Doctrine row entity's identifier columns. **Adopted 2026-08-05** — this row said *"adapter written by hand meanwhile — see below"*, with a "see below" pointing at nothing, in the commit that deleted the hand-written layout | composer.lock |
| `doctrine/orm` | 3.6.7 | MIT | persistence, via a SEPARATE model in `Infrastructure/` mapped with **ATTRIBUTES**. This row said *"mapped in XML not attributes"* until 2026-08-05, four days after the 2026-08-01 ruling reversed it | composer.lock |
| `doctrine/dbal` | 4.4.4 | MIT | database abstraction | composer.lock |
| `doctrine/doctrine-bundle` | 3.3.1 | MIT | Symfony integration — **3.x is the first line supporting Symfony 8** | composer.lock |
| `doctrine/doctrine-migrations-bundle` | 4.0.0 | MIT | schema migrations, incl. the RLS policies | composer.lock |
| `api-platform/core` | v4.3.17 | MIT | **the REST/GraphQL surface** — the mechanism `CLAUDE.md` § "The Symfony ecosystem is the ONLY vocabulary" mandates for API resources and pagination, rather than a hand-rolled controller per endpoint. v4.3 is the first STABLE line supporting Symfony 8 (`symfony/http-kernel: ^6.4.13 \|\| ^7.0 \|\| ^8.0`); the 4.4 and 5.0 lines are still alpha, and an alpha dependency in a billing product is not acceptable | composer.lock + packagist p2 (expanded — the v2 feed is MINIFIED and a naive read shows `license: null`) |
| `symfony/serializer` | v8.1.3 | MIT | API Platform's representation layer | composer.lock |
| `symfony/validator` | v8.1.2 | MIT | DTO validation at the UI boundary — the domain still refuses invalid state itself | composer.lock |
| `symfony/property-access` | v8.1.0 | MIT | pulled by API Platform | composer.lock |
| `symfony/property-info` | v8.1.2 | MIT | pulled by API Platform | composer.lock |
| `symfony/type-info` | v8.1.0 | MIT | pulled by API Platform | composer.lock |
| `phpstan/phpdoc-parser` | 2.3.3 | MIT | **a DIRECT runtime requirement, not a dev tool — read the reason before removing it.** PHP has no generics, so a `list<NewInvoiceLineInput>` on an input DTO exists only in a docblock. `symfony/property-info` registers its `PhpStanExtractor` — and therefore resolves those element types for the Serializer AND for the published OpenAPI schema — only when this package and `phpdocumentor/type-resolver` are both NON-DEV requirements of the root package [Verified: `FrameworkExtension.php:2193-2196`]. Move it to `require-dev` and `POST /api/invoices` deserialises its lines into raw arrays, `#[Assert\Valid]` cascades onto nothing, and the contract document types `lines` as an untyped array | composer.lock |
| `phpdocumentor/type-resolver` | 2.0.0 | MIT | the second half of the condition above — the extractor needs a docblock CONTEXT factory as well as a parser | composer.lock |
| `phpdocumentor/reflection-common` | 2.2.0 | MIT | pulled by `phpdocumentor/type-resolver` | composer.lock |
| `symfony/web-link` | v8.1.0 | MIT | `Link` headers — 103 Early Hints and Vulcain preloading, both of which FrankenPHP serves | composer.lock |
| `psr/link` | 2.0.1 | MIT | PSR-13 link interfaces, pulled by `symfony/web-link` | composer.lock |
| `willdurand/negotiation` | 3.1.0 | MIT | content negotiation, pulled by API Platform | composer.lock |
| `composer/semver` | 3.4.4 | MIT | version constraint parsing, pulled by API Platform | composer.lock |
| `symfony/messenger` | v8.1.2 | MIT | the QUEUE. `CLAUDE.md` § "The Symfony ecosystem is the ONLY vocabulary" names it as the replacement for Laravel Jobs/Horizon, and `infra/compose.yaml` runs a worker on it | composer.lock |
| `symfony/scheduler` | v8.1.2 | MIT | recurring work — recurring invoices, reminders, retention sweeps. Pinned to ONE replica in `infra/compose.prod.yaml`, because two schedulers issue every recurring invoice twice | composer.lock |
| `symfony/lock` | v8.1.4 | MIT | the distributed lock behind that single-scheduler constraint, so it survives a future multi-host deploy rather than depending on a compose file | composer.lock |
| `symfony/doctrine-messenger` | v8.1.2 | MIT | the Messenger transport, added 2026-08-02. **Doctrine and not Valkey**, for three reasons given at length in `api/config/packages/messenger.yaml`: `compose.yaml` runs Valkey with persistence disabled, so it cannot be the durable failure store; `symfony/redis-messenger` requires `ext-redis` and refuses Predis; and a message dispatched inside the transaction that issues a document must commit or roll back with it | composer.lock |
| `predis/predis` | v3.5.1 | MIT | the Valkey client, for the LOCK STORE only. It was described here as serving "the Messenger transport and the lock store" while **neither existed** — no `messenger.yaml`, no `lock.yaml`, and zero references to Predis anywhere in `api/src`. Both are now configured, and the queue deliberately does NOT use it: Valkey runs with persistence off, which is right for a lock and disqualifying for a durable queue. **VALKEY, not Redis** — Redis relicensed in 2024 to RSALv2/SSPLv1, neither of which is permissive, and SSPL specifically bites offering the software as a service, which is the commercial branch. Valkey is the Linux Foundation fork under BSD-3-Clause. The CLIENT is protocol-compatible and unaffected | composer.lock |
| `phpunit/phpunit` | 13.3.0 | BSD-3-Clause | all four test suites | composer.lock |
| `friendsofphp/php-cs-fixer` | v3.95.17 | MIT | style gate | composer.lock |
| `phpstan/phpstan` | 2.2.6 | MIT | static analysis — a pinned PHAR in `api/tools/bin/`, fetched by `scripts/dev/fetch-tools.sh`, NOT a Composer dependency. It is dist-only on Packagist, which is why; see CLAUDE.md § "Quality gate" | `scripts/dev/fetch-tools.sh` pin + phar reports `PHPStan 2.2.6` |
| ~~`deptrac/deptrac`~~ | — | MIT | **REMOVED from `require-dev` 2026-08-02**: it requires the dist-only `phpstan/phpstan` and so blocked every other dev dependency. Owed as a phar once the release asset is located. **`qossmic/deptrac` is ABANDONED** in favour of this package | composer.lock history + packagist `abandoned` field |
| `symfony/browser-kit` | v8.1.4 | MIT | functional suite — HTTP through the kernel | composer.lock |
| `symfony/css-selector` | v8.1.0 | MIT | functional suite — assertions against markup | composer.lock |
| `symfony/process` | v8.1.0 | MIT | e2e suite — booting a real server | composer.lock |

**PHP extensions, not packages, so they carry no notice obligation but are hard requirements:**
`bcmath` (the domain's exact decimal arithmetic — see `CLAUDE.md` § "Architecture" for why this replaced
a decimal *library*), `pdo_pgsql`, `mbstring`, `intl`, `simplexml`, `ctype`, `iconv`.

**THREE tools are fetched as vendor-official phars rather than via Composer** — this said "Two" while the
table above already listed the third. PHPUnit from `phar.phpunit.de`, php-cs-fixer from `cs.symfony.com`,
PHPStan from `raw.githubusercontent.com`. **Not "because Composer's dist URLs are blocked"**, which was the
refuted premise: they are phars because a pinned SHA-256 phar runs in a checkout with NO vendor tree at all,
which is what keeps `gate:style`, `gate:test` and `gate:static` independent of a successful
`composer install`. `phpstan/phpstan` additionally is not in the lock at all — it entered only as a
dependency of `deptrac` and left with it. `scripts/dev/fetch-tools.sh` verifies all three against pinned
SHA-256 hashes — an unverified phar is arbitrary code from the network, which this project's licensing
position cannot tolerate. Same licences as the table above; the phars are not committed.

**One dependency deliberately NOT taken:** `brick/math` (MIT, and a fine library). `Money` uses `bcmath`
instead, which keeps `Domain/` at literally zero Composer dependencies — enforced by
`scripts/gates/layer-dependencies.php` with an empty allowlist. Recorded here because "we considered it
and chose not to" is more useful to a future reader than silence.

**This file is the SSOT for anticipated dependencies.** `CLAUDE.md` invariant 6 and
`docs/plans/reimplementation-strategy.plan.md` also name candidate libraries; where they differ, this
list governs, and neither of them is a licence record.

Anticipated, licences to be confirmed **at the moment each is added**: `symfony/*` (MIT),
`doctrine/orm` (MIT), `horstoeko/zugferd` (Factur-X/ZUGFeRD), `josemmo/facturae-php` (FacturaE),
`invoiceninja/ubl_invoice` (UBL/EN 16931 models), `setasign/fpdi` (PDF assembly), a headless-Chrome or
Gotenberg renderer, and the payment-gateway SDKs. **Do not treat the parenthesised guesses as
verified** — check the package's own `LICENSE` and record it here.

One note on `invoiceninja/ubl_invoice`, since it is published by the upstream vendor: it is
**MIT** [Verified: packagist `p2/invoiceninja/ubl_invoice.json` → licences reduce to `["MIT"]`], so it is
permitted, and **who authored a package has no bearing on its licence.** Depending on an MIT library
that Invoice Ninja happens to maintain is not "using upstream code" in the sense invariant 1 forbids —
that invariant is about their ELv2 application source, not about independently-licensed packages they
also publish. Record it like any other dependency and move on.

## Reference material that is NOT a dependency

Invoice Ninja's repositories were **studied, never vendored** — read-only clones outside the working
tree. No file, and no translation of a file, from any of them is present. See `CLAUDE.md`
§ "Licensing invariants". The legitimate inputs were the published OpenAPI specification, the schema
shape, observable behaviour, and the public standards (EN 16931, UBL, CII, Factur-X, Peppol BIS) —
none of which is copyrightable expression.

## Angular admin (`admin/package.json`)

Scaffolded with the official generator (`ng new`, Angular CLI 22.0.9) rather than by hand, so the layout
and configuration follow Angular's own current recommendations instead of ours. Node 26.7.0.

Every direct dependency below, with the licence npm records for it in `admin/package-lock.json`. The
**763** packages in the full locked tree across both tiers are checked by
`scripts/gates/dependency-licences.php` on every run; only direct choices are enumerated here, because a
transitive list would rot on the first `npm update` while a direct one records a decision somebody made.

| Package | Constraint | Licence | Scope |
|---|---|---|---|
| `@angular/common` | `^22.0.0` | MIT | runtime |
| `@angular/compiler` | `^22.0.0` | MIT | runtime |
| `@angular/core` | `^22.0.0` | MIT | runtime |
| `@angular/forms` | `^22.0.0` | MIT | runtime |
| `@angular/platform-browser` | `^22.0.0` | MIT | runtime |
| `@angular/router` | `^22.0.0` | MIT | runtime |
| `rxjs` | `~7.8.0` | Apache-2.0 | runtime |
| `tslib` | `^2.3.0` | 0BSD | runtime |
| `@angular/build` | `^22.0.9` | MIT | dev |
| `@angular/cli` | `^22.0.9` | MIT | dev |
| `@angular/compiler-cli` | `^22.0.0` | MIT | dev |
| `@eslint/js` | `^10.0.1` | MIT | dev |
| `angular-eslint` | `22.1.0` | MIT | dev |
| `eslint` | `^10.6.0` | MIT | dev |
| `jsdom` | `^28.0.0` | MIT | dev |
| `prettier` | `^3.8.1` | MIT | dev |
| `typescript` | `~6.0.2` | Apache-2.0 | dev |
| `typescript-eslint` | `8.62.1` | MIT | dev |
| `vitest` | `^4.0.8` | MIT | dev |

**`tslib` is the only runtime dependency here whose licence is not MIT/ISC/Apache/BSD**: it is **0BSD**,
Zero-clause BSD, which is permissive with no attribution requirement at all — strictly less demanding than
MIT. It is TypeScript's own runtime helper library.

**Four of the six licence identifiers beyond the original MIT/Apache/BSD/ISC set are now ON the permitted
list, and only `tslib`'s applies to a RUNTIME dependency.** `0BSD`, `BlueOak-1.0.0`, `MIT-0` and `CC0-1.0` were
each added on their own merits (all non-copyleft, none imposing an obligation that could survive into a
commercial sublicence) — see `CLAUDE.md` § "Licensing invariants" 8(a), which is authoritative, and
`LICENSING.md`. `CC-BY-4.0`
(`caniuse-lite`) and `CC-BY-3.0` (`spdx-exceptions`) were **not**: they are Creative Commons *content*
licences that impose attribution, and this project has already refused a dependency for exactly that reason
(licensing invariant 3 — `admin-portal`'s Attribution Assurance License). They are permitted only as
**build-time reference data absent from the shipped artifact**, and the gate fails if either ever appears as
a runtime dependency.

## Flutter client (`mobile/pubspec.yaml`)

Scaffolded with the official generator (`flutter create --platforms=android,ios,linux,macos,windows,web`)
on Flutter **3.44.9** / Dart **3.12.2** — the current stable channel, matching this tier's pin.

**The Flutter client is written from scratch, and NO code from `invoiceninja/admin-portal` is used**, so its
Attribution Assurance License imposes nothing here (developer ruling, 2026-07-30). That licence would have
required, on **every launch, forever**, a prominent display of *Hillel Coren* / *Invoice Ninja* /
*invoiceninja.com* — and the obligation follows the code rather than the quantity of it, which is why licensing
invariant 3 forbids reusing it even "just for the transport layer". This paragraph previously sat under a
`## Dart / Flutter (mobile & desktop)` heading that also declared *"No dependencies yet — the client is not
scaffolded"*, above this populated table; that duplicate section and its Angular twin were deleted at round 3 and
this sentence moved here, because a file with two sections per tier saying opposite things is worse than either.

| Package | Constraint | Licence | Scope |
|---|---|---|---|
| `flutter` | SDK | BSD-3-Clause | runtime |
| `cupertino_icons` | `^1.0.8` | MIT | runtime |
| `flutter_test` | SDK | BSD-3-Clause | dev |
| `flutter_lints` | `^6.0.0` | BSD-3-Clause | dev |
| Roboto (font, bundled binary) | 3 weights, vendored | Apache-2.0 | runtime asset |
| NotoSansArabic (font, bundled binary) | 2 weights, vendored | OFL-1.1 | runtime asset |

**Roboto is vendored as a binary asset** under `mobile/assets/fonts/`, with its Apache-2.0 licence text kept
beside it as `Roboto-LICENSE.txt`. Copied from the Flutter SDK's own `bin/cache/artifacts/material_fonts`,
which is where Flutter ships it. Three weights (regular, medium, bold) rather than all seventeen. **Why
vendored rather than fetched is a GDPR decision** — see `mobile/pubspec.yaml`, which states it in full.

**Noto Sans Arabic is vendored for the same reason, and it is the one dependency here under a licence that
is not on the permissive list.** OFL-1.1 is permitted for a **vendored font asset only** — developer ruling,
2026-07-29, recorded in `CLAUDE.md` invariant 8(a). It is not copyleft in any sense that touches our code:
its one substantive obligation is the **Reserved Font Name** clause, which binds somebody who *modifies* a
font and redistributes it under its original name. These files are vendored byte-for-byte unmodified, so
that clause is never engaged, and OFL-1.1 § 2 is satisfied by keeping the licence text beside them.

Why it was needed rather than merely convenient: `ar` is a first-class locale here and already has a
committed catalogue. Flutter Web's engine covers a script the bundled fonts do not by downloading a Noto
fallback from `fonts.gstatic.com`; pinning `fontFallbackBaseUrl` same-origin stopped that transfer to
Google, but with no font behind the pin an Arabic glyph produced a 404 retry loop and no glyphs at all.
Bundling the family as a `fontFamilyFallback` means the engine never needs the fallback path for Arabic.

Provenance, because a font's licence is only as good as where the binary came from
[Verified 2026-07-29]: extracted from Debian/Ubuntu `fonts-noto-core` 20201225-2, whose
`debian/copyright` records `Files: *` → `License: OFL-1.1`, copyright Google Inc./LLC. Only the two `.ttf`
files were taken — **none of that package's `debian/*` files, which are GPL-3+**, so no copyleft attaches.
Each binary states its own licence internally (OpenType `name` table nameID 13: *"This Font Software is
licensed under the SIL Open Font License, Version 1.1"*, nameID 14: `http://scripts.sil.org/OFL`), and the
canonical licence text was retrieved from SIL's own `https://openfontlicense.org/documents/OFL.txt`
(sha256 `1d361a8f…f6b57e`) rather than reflowed out of the Debian copyright file.
`sha256(NotoSansArabic-Regular.ttf)` = `504d7407d86875acf7d04dfaa0fd7524d0b8797723bc4aa18022f29db25b0b6e`;
`sha256(NotoSansArabic-Bold.ttf)` = `ded6fc7359ca36d15d7aab9ef0c066e21ce48b26a069994d6602fa2cb9a1b952`.

**Every vendored font is now gated, not merely documented.** `scripts/gates/dependency-licences.php`
requires, per font binary: a REUSE 3.0 `<file>.license` sidecar declaring exactly one SPDX identifier; that
identifier being permissive or on the font-asset list; **the font's own `name` table corroborating it**, so a
sidecar that disagrees with the binary fails rather than being believed; the full licence text beside it; and
the family named in this file. A `.woff2`, `.ttc` or `.eot` is refused **by name** rather than skipped —
a container the gate cannot read a licence out of must not be indistinguishable from one it approved.

[Verified 2026-07-29: `cupertino_icons` 1.0.9's licence text is "The MIT License (MIT)", (c) Vladimir
Kharlampidi, read from `pub.dev/packages/cupertino_icons/license`; `flutter_lints` 6.0.0 and the Flutter SDK
both carry the Flutter Authors' three-clause BSD text, with all three clauses present.]

**`mobile/pubspec.lock` carries NO licence field** — unlike `composer.lock` and npm's `lockfileVersion 3`,
which both record one per entry. [Verified: `grep -c license mobile/pubspec.lock` → 0.] That is a real
limitation and it is stated rather than papered over: the licences above were each read from pub.dev by hand,
and `scripts/gates/dependency-licences.php` can therefore only check that every **direct** pub dependency
appears in this file, not that its licence is permissive. The 23 transitive entries are the Dart and Flutter
SDK's own first-party packages (`async`, `collection`, `meta`, `matcher`, `stack_trace`, `vm_service`,
`leak_tracker`, `sky_engine` and similar), published by `dart.dev`/`flutter.dev` — **mostly** under
BSD-3-Clause, and that word matters: **three are Apache-2.0**, namely `clock`, `fake_async` and
`material_color_utilities` [Verified 2026-07-30: each package's own `LICENSE` opens *"Apache License /
Version 2.0, January 2004"*]. Apache-2.0 is on the permitted list, so this was never a violation — but this
file said "under BSD-3-Clause" without qualification, which was a false factual claim in the one document
invariant 8(a) names as the licence record. Adding any pub dependency from outside that set requires reading
its licence and adding a row here in the same change.

~~**Owed:** a licence check for this tier that does not depend on a field pub does not publish.~~
**DELIVERED 2026-07-30.** `scripts/gates/dependency-licences.php` now reads **every** locked pub package's
own licence file out of the pub cache and classifies it — 24 hosted packages, 24 classified, none left over:
**BSD-3-Clause ×20, Apache-2.0 ×3, MIT ×1.** The old note claimed the check needed `flutter pub deps --json`
or vendored `LICENSE` files; neither was true, because every cached package already ships its licence. It
**fails rather than skips** when it cannot look — no cache, a package missing from it, a package with no
licence file, or a licence text matching no known signature are all failures — which means running this gate
requires `cd mobile && flutter pub get` first. That coupling is the accepted cost, stated rather than hidden,
and it is the same principle as the integration suite failing without PostgreSQL. `sdk`-source entries
(`flutter`, `flutter_test`, `sky_engine`) are not in the cache and remain covered by this file.

### Fonts and icons that reach the bundle without being vendored by us

Round 8 found the vendored-font enumeration stopped at the five files under `mobile/assets/fonts/`, while the
release web build carries **seven** font binaries. The other two arrive from a manifest flag rather than from
this repository, and both are recorded here because "we did not commit it" is not the same as "we do not
distribute it":

| Asset | How it arrives | Licence | State |
|---|---|---|---|
| `CupertinoIcons.ttf` | the `cupertino_icons` package | MIT | **Fine.** Its licence text is in the built artifact — [Verified: `grep -c "Vladimir Kharlampidi" build/web/assets/NOTICES` → 1] — because Flutter's generated `NOTICES` aggregates LICENSE files from *packages*, which this is. |
| `MaterialIcons-Regular.otf` | `uses-material-design: true` | **CC-BY-4.0**, as a discharged obligation | **Resolved by developer ruling, 2026-07-30** — see below. |

**The MaterialIcons determination.** Its position was genuinely unclear, so under licensing invariant 10 it was
put to the developer rather than resolved conveniently. Four facts, all verified locally: the Flutter SDK ships
`MaterialIcons_LICENSE.txt` beside the binary whose first line is *"Attribution 4.0 International"* —
**CC-BY-4.0**. Google's `material-design-icons` repository states Apache-2.0 for the icon set, which would be a
weaker obligation. **That IS now verified and it does NOT disturb the ruling** — the claim that it *"cannot be
verified from this container — GitHub egress is restricted to this repository"* was false, and
`CLAUDE.md` § Gotchas is explicit that *"a documented impossibility gets read once and never re-tested"*,
which is why it survived in four places. [Verified 2026-08-05:
`curl https://raw.githubusercontent.com/google/material-design-icons/master/LICENSE` → HTTP 200, *"Apache
License, Version 2.0"* — the same host `fetch-tools.sh` already downloads the PHPStan phar from.] The ruling
stands on its own two grounds, neither of which was the egress claim: the licence that travelled WITH the
binary we vendored is the SDK's own `MaterialIcons_LICENSE.txt`, whose first line is *"Attribution 4.0
International"*, and the copy in the bundle is tree-shaken — a modified work under either licence. Complying
with the stricter reading therefore satisfies both. Re-opening it in Apache's favour would be a licensing
decision under invariant 10 and is the developer's to make, not a build fix. The binary carries **no nameID 13 at all** [Verified: parsed; only nameID 0 *"Copyright 2019 Google
LLC"* and nameID 1 *"Material Icons"*], so the name-table cross-check that makes every vendored font's sidecar
into evidence cannot run on it. And the copy in the bundle is **tree-shaken** — 7736 bytes from 1645184 — a
*modified* work, which engages CC-BY § 3(a)(1)(b) and Apache-2.0 § 4(b).

**The ruling: comply with the STRICTER reading, which satisfies both.** CC-BY-4.0 asks for attribution, a
licence notice, the licence URI and an indication of modification; Apache-2.0 § 4(a) asks for less. All four are
discharged by `mobile/assets/fonts/MaterialIcons-LICENSE.txt`, which is declared under `flutter:` → `assets:`
and therefore **travels in the built artifact** [Verified 2026-07-30: present at
`build/web/assets/assets/fonts/MaterialIcons-LICENSE.txt` in a release web build]. It carries Google's
copyright, the licence and its URI, the SDK's full licence text, and an explicit statement that the shipped copy
is subset by Flutter's icon tree-shaking rather than redrawn.

**This is a discharged obligation, not a new permission, and the distinction is enforced.** CC-BY-4.0 remains
off the permitted list for anything distributed: a Composer, npm or pub package under it is still refused, and
`scripts/gates/test-gates.sh` carries a case for each of those asserting exactly that. The record lives in
`scripts/gates/dependency-licences.php` as `FRAMEWORK_PROVIDED_FONTS` — enumerated because a font arriving from
a *manifest flag* is invisible to every walk over our own directories and to every lock file, which is precisely
how it shipped unnoticed for two commits. The meta-suite caps that list at **one entry**, so a second framework
font is a deliberate licensing act rather than a way to make a build pass.

### Template-derived binaries: 37 tracked `.png`/`.ico`, 13 of them not ours

Also found by round 8, and recorded because the claim that a font was "the one third-party work arriving as a
committed binary" was wrong. Thirteen of the 37 tracked image files are byte-identical to a Flutter-SDK or
Angular-schematic template asset — `admin/public/favicon.ico` matches
`@schematics/angular/.../favicon.ico.template`, `mobile/web/icons/Icon-192.png` and the Android
`mipmap-*/ic_launcher.png` set match the SDK's own templates — and all of them ship.

The `mobile/**` ones are covered by the generic sentence above: Flutter's template output, SDK, BSD-3-Clause.
`admin/public/favicon.ico` is not, and is hereby recorded as **Angular schematic template output, MIT** (the
`@angular/*` and `@schematics/angular` packages are MIT, already rowed in the Angular section). All are
placeholders that licensing invariant 9 requires replacing with our own branding before any public deployment,
at which point the question disappears rather than needing an answer.

### Owed: the Angular tier's notices file may not reach the served artifact

`npm run build` writes `dist/admin/3rdpartylicenses.txt` (329 lines, the MIT texts for `@angular/*`) as a
**sibling** of `dist/admin/browser/`, which is the static web root. [Verified 2026-07-30: `browser/` holds
`index.html`, the bundles, `styles-*.css` and `favicon.ico`, and `grep -rl "Permission is hereby granted"
dist/admin/browser/` returns nothing.] So whether the notices are actually served depends on what `infra/`
copies, and `infra/` holds only a `README.md` — nothing decides it yet.

This is the same distinction this project promoted to a `CLAUDE.md` Gotcha on 2026-07-30 (*"beside the file is
not shipped"*), gated for the Flutter tier and unchecked for this one. Owed with the `infra/` tier: either the
deployment copies that file into the web root, or the build is configured to emit it there.

**The generated per-platform scaffolding under `mobile/{android,ios,linux,macos,windows,web}` is Flutter's
own template output** (SDK: BSD-3-Clause) and is deliberately **excluded from the SPDX header requirement**
by `scripts/gates/spdx-headers.sh`. Stamping our copyright and `AGPL-3.0-or-later` onto upstream's template
files would assert copyright over their work, which is the opposite of what licensing invariant 8(c) exists
to achieve. `mobile/lib` and `mobile/test` are ours and do carry headers.

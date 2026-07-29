# Third-party notices

Every third-party dependency of twes-in, with its licence, recorded **before** it is added — not
audited afterwards. This file is a deliverable, not a formality: twes-in is dual-licensed
(`LICENSING.md`), and dual licensing fails the moment an incompatible dependency enters the tree.

## The rule: PERMISSIVE DEPENDENCIES ONLY

Before adding any dependency: establish its licence, confirm it is **permissive**, and add a row here
in the same change.

**Permitted:** **MIT · Apache-2.0 · BSD-2-Clause · BSD-3-Clause · ISC**. That is the whole list.

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

Locked in `api/composer.lock` as of Wave 0, 2026-07-29. **Not yet installed** — GitHub egress in the
development container is restricted to this repository, so Composer cannot fetch dist archives
(`CLAUDE.md` § Gotchas). The lock is committed and fully pinned, so `composer install` on a network that
can reach them needs no other change.

The full locked tree is **52 runtime + 54 dev packages**, distributed **MIT ×82, BSD-3-Clause ×23,
Apache-2.0 ×1** — every one permissive. That is not a claim from inspection: it is checked on every gate
run by `scripts/gates/dependency-licences.php`, which reads the lock and fails on anything outside the
allowlist. Direct requirements:

| Package | Version | Licence | Purpose | Verified |
|---|---|---|---|---|
| `symfony/framework-bundle` | v8.1.2 | MIT | the framework | composer.lock |
| `symfony/console` | v8.1.2 | MIT | CLI commands | composer.lock |
| `symfony/runtime` | v8.1.0 | MIT | front-controller bootstrap | composer.lock |
| `symfony/dotenv` | v8.1.2 | MIT | environment loading | composer.lock |
| `symfony/yaml` | v8.1.2 | MIT | configuration | composer.lock |
| `symfony/translation` | v8.1.1 | MIT | i18n — FR/AR/EN catalogues | composer.lock |
| `symfony/uid` | v8.1.0 | MIT | UUIDv7 (adapter written by hand meanwhile — see below) | composer.lock |
| `doctrine/orm` | 3.6.7 | MIT | persistence, mapped in XML not attributes | composer.lock |
| `doctrine/dbal` | 4.4.4 | MIT | database abstraction | composer.lock |
| `doctrine/doctrine-bundle` | 3.3.1 | MIT | Symfony integration — **3.x is the first line supporting Symfony 8** | composer.lock |
| `doctrine/doctrine-migrations-bundle` | 4.0.0 | MIT | schema migrations, incl. the RLS policies | composer.lock |
| `phpunit/phpunit` | 12.5.33 | BSD-3-Clause | all four test suites | composer.lock |
| `friendsofphp/php-cs-fixer` | v3.95.17 | MIT | style gate | composer.lock |
| `phpstan/phpstan` | 2.2.6 | MIT | static analysis (owed — cannot install) | composer.lock |
| `deptrac/deptrac` | 4.7.1 | MIT | layer fitness (owed — cannot install). **`qossmic/deptrac` is ABANDONED** in favour of this package | composer.lock + packagist `abandoned` field |
| `symfony/browser-kit` | v8.1.1 | MIT | functional suite — HTTP through the kernel | composer.lock |
| `symfony/css-selector` | v8.1.0 | MIT | functional suite — assertions against markup | composer.lock |
| `symfony/process` | v8.1.0 | MIT | e2e suite — booting a real server | composer.lock |

**PHP extensions, not packages, so they carry no notice obligation but are hard requirements:**
`bcmath` (the domain's exact decimal arithmetic — see `CLAUDE.md` § "Architecture" for why this replaced
a decimal *library*), `pdo_pgsql`, `mbstring`, `intl`, `simplexml`, `ctype`, `iconv`.

**Two tools are fetched as vendor-official phars rather than via Composer**, because Composer's dist URLs
are blocked and these two publish from their own domains: PHPUnit from `phar.phpunit.de` and php-cs-fixer
from `cs.symfony.com`. `scripts/dev/fetch-tools.sh` verifies both against pinned SHA-256 hashes — an
unverified phar is arbitrary code from the network, which this project's licensing position cannot
tolerate. Same licences as the table above; the phars are not committed.

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

## TypeScript / Angular (admin)

_No dependencies yet — the admin app is not scaffolded._

| Package | Version | Licence | Purpose | Verified |
|---|---|---|---|---|

## Dart / Flutter (mobile & desktop)

_No dependencies yet — the client is not scaffolded._ The Flutter client is written from scratch;
**no code from `invoiceninja/admin-portal` is used**, so its Attribution Assurance License imposes
nothing here (developer ruling, 2026-07-29).

| Package | Version | Licence | Purpose | Verified |
|---|---|---|---|---|

## Reference material that is NOT a dependency

Invoice Ninja's repositories were **studied, never vendored** — read-only clones outside the working
tree. No file, and no translation of a file, from any of them is present. See `CLAUDE.md`
§ "Licensing invariants". The legitimate inputs were the published OpenAPI specification, the schema
shape, observable behaviour, and the public standards (EN 16931, UBL, CII, Factur-X, Peppol BIS) —
none of which is copyrightable expression.

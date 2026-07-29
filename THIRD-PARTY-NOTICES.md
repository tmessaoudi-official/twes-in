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

_No dependencies yet — the API is not scaffolded._

| Package | Version | Licence | Purpose | Verified |
|---|---|---|---|---|

Anticipated, licences to be confirmed at the moment each is added: `symfony/*` (MIT),
`doctrine/orm` (MIT), `horstoeko/zugferd` (Factur-X/ZUGFeRD), `setasign/fpdi` (PDF assembly),
a headless-Chrome or Gotenberg renderer, and the payment-gateway SDKs. **Do not treat the
parenthesised guesses as verified** — check the package's own `LICENSE` and record it.

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

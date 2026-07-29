# Third-party notices

Every third-party dependency of twes-in, with its licence, recorded **before** it is added — not
audited afterwards. This file is a deliverable, not a formality: twes-in is dual-licensed
(`LICENSING.md`), and dual licensing fails the moment an incompatible dependency enters the tree.

## The rule

Before adding any dependency: establish its licence, confirm it is **AGPL-3.0-compatible**, and add a
row here in the same change. A dependency with no row is a `completeness-reviewer` finding.

**Compatible:** Apache-2.0, MIT, BSD-2/3-Clause, ISC, LGPL (via dynamic linking), GPL-3.0-or-later,
AGPL-3.0.

**Incompatible — never add:**

| Licence | Why |
|---|---|
| **GPL-2.0-only** | No "or later" clause, so it cannot be upgraded to AGPL-3.0. Note on `invoiceninja/dockerfiles`: it carries GPL-2.0 text with no per-file version notice, so "only" vs "or later" is ambiguous under GPL-2.0 §9 — we do not rely on the convenient reading, and copying from it is excluded on the independent ground that the copied file becomes copyleft either way. See `LICENSING.md`. |
| **Elastic License 2.0** | Not open source; forbids hosted-service provision. Invoice Ninja's API and web UI. |
| **BUSL / SSPL / "source-available"** | Not open source; incompatible with AGPL redistribution. |
| Anything unlicensed or licence-unclear | Absence of a licence means no grant at all. |

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

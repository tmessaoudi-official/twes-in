# admin — Angular web client

> **twes-in ships TWO admin interfaces** (developer ruling, 2026-07-29): this Angular one and the Flutter
> client's web build — the same shape Invoice Ninja offers with Flutter and React. Every admin screen
> therefore exists twice, in TypeScript and in Dart. That is an accepted cost, and it is why the API
> contract and `docs/spec/pricing-vectors.json` are load-bearing: they are the only thing keeping two
> independent front ends consistent. **The public client portal (Wave 10) is this tier's job, not
> Flutter's** — an unauthenticated link opened on mobile data cannot afford a large bundle.

**Scaffolded, not built. The application lands in Wave 8** (`docs/plans/build-waves.plan.md`), on top of the
API contract that Wave 7 pins.

**Scaffolded with the official generator, never by hand** (developer ruling, 2026-07-29 — *"the projects
scaffolds need to be following official recommendations and best practices, not done manually"*):

```
ng new admin --style=scss --ssr=false --zoneless=true --routing=true --ai-config=claude
ng add @angular-eslint/schematics
```

Angular CLI **22.0.9** on Node **26.5.0** (installed from nodejs.org against the published SHA-256; the
container's default 22.22.2 is one patch below Angular 22's `^22.22.3` floor). Three flags were choices
rather than defaults, and each is a decision:

- **`--ssr=false`** — this is an authenticated admin SPA. SSR would add a Node server to build, deploy and
  keep patched for no SEO benefit, against a deployment story that starts Docker-only with no public domain.
  Note the public client portal (Wave 10) is a *different* surface and may want prerendering; that is its
  decision to make, not this one.
- **`--zoneless=true`** — Angular's current recommendation for new applications, and it drops `zone.js`
  entirely. Choosing it now costs nothing; retrofitting it later means auditing every async assertion in
  every test.
- **`--ai-config=claude`** — generates `admin/.claude/CLAUDE.md`, Angular's *own* statement of current best
  practice (signals over decorators, native control flow, `input()`/`output()`, Signal Forms, AXE/WCAG AA).
  Kept as generated: it is upstream's guidance, not ours, and rewriting it would defeat the point.

Two things were changed from what `ng new` emitted, both deliberate:

1. **The 20 KB welcome page was replaced** with a minimal shell. It carries Angular's own logo, links and
   copy — vendor marketing, and not the admin shell — so it does not belong in this tier's first commit.
2. **The product name is injected, never a literal** (`src/app/branding.ts`, a `BRANDING` token). Licensing
   invariant 9 requires a later public deployment to be a **config change, not a code change**, and a name
   hardcoded across forty templates is not a refactor anybody schedules. A test asserts that overriding the
   token changes what renders — so the invariant is checked, not merely intended.

Current state: `npm run lint` clean, `npm test` 2 passing (Vitest + jsdom, no browser needed),
`npm run build` produces a 189 kB initial bundle. All 763 locked packages across both tiers are licence-
checked by `scripts/gates/dependency-licences.php`, and every direct dependency is recorded in
`THIRD-PARTY-NOTICES.md`.

Pinned on landing: Angular **22.0.8**, Node **26.5.0**.

## What this tier owes on arrival, as gate conditions

Not aspirations — a wave is not done until its gate is green.

| Owed | Why |
|---|---|
| Test taxonomy: **unit · component · e2e** | Mirrors the API's four suites. Component tests over a real DOM, e2e over a real browser. |
| **`axe-core` a11y check in the gate** | Accessibility asserted in prose and never measured is not accessibility. |
| **Locale key parity** across `en`/`fr`/`ar` | Same rule the API already enforces — `scripts/gates/locale-key-parity.php` gains this directory. |
| **RTL proven visually**, not asserted | Arabic is a shipped locale. A screenshot, delivered — see `CLAUDE.md` on visual evidence. |
| **Consumes `docs/spec/pricing-vectors.json`** | The profit-rate arithmetic runs here too. A tier whose suite does not read that fixture is a `completeness-reviewer` P0. |
| **…including its `document_totals` block, not only `cases`** | Named explicitly because round 18 found this row describing the fixture as the *profit-rate* contract, so an implementer could read it, implement `cases[]` in full, and consider the row satisfied while the whole document kernel went unpinned. `document_totals` carries the VAT rounding point, the **per-line VAT column** and two near-miss columns recording what the natural mistake produces. The allocation rule — largest remainder, ties to the earliest line, flooring first, applied under `PerRateGroup` only — is **unfixable once documents are issued** (`CLAUDE.md` § Gotchas), and this fixture is the stated mechanism preventing three tiers inventing three rules. |
| Lint, strict TypeScript, production build | `npm run lint`, `ng build --configuration production`. |
| Every dependency permissive, in `THIRD-PARTY-NOTICES.md` | `scripts/gates/dependency-licences.php` **now inspects** `admin/package-lock.json` — all 763 locked packages across both tiers are licence-checked, so this row is CLOSED. |
| **A Content-Security-Policy, generated not hand-written** | `@angular/build` 22 ships `security.autoCsp`, which emits a hash-based strict CSP at build time; it defaults to **off** and `ng new` does not enable it. **Now enabled** in `angular.json`, verified rendering clean in a real browser with zero violations. Owed on top: `frame-ancestors` and `connect-src` scoped to the API origin once that origin exists, and a test that fails if the built `index.html` carries no CSP. |
| **The bearer token must not reach `localStorage`** | This tier holds the session credential and renders every client's name, address and e-mail. `localStorage` is readable by any XSS; an in-memory token plus an httpOnly refresh cookie is the shape to reach for. Assert it — a test that fails if a token-shaped value is written to web storage. |
| **`axe-core` on every route, and RTL** | Angular's own generated guidance (`admin/.claude/CLAUDE.md`) requires passing all AXE checks and WCAG AA. Accessibility asserted in prose and never measured is not accessibility. Arabic is a first-class locale here, so RTL is a layout requirement, not a translation one. |
| **No `bypassSecurityTrust*` without a recorded reason** | `DomSanitizer`'s escape hatches turn Angular's default XSS protection off at the call site. Note that `admin/.claude/CLAUDE.md` — generated by `ng new --ai-config` and kept as upstream's own guidance — contains **no security rule at all**, so an agent working in this tier has no security floor from it. This row is that floor. |

**No upstream code, ever.** Invoice Ninja's web UI (`invoiceninja/ui`) is Elastic License 2.0. This is a
clean-room reimplementation — see `CLAUDE.md` § "Licensing invariants".

# admin — Angular web client

> **twes-in ships TWO admin interfaces** (developer ruling, 2026-07-29): this Angular one and the Flutter
> client's web build — the same shape Invoice Ninja offers with Flutter and React. Every admin screen
> therefore exists twice, in TypeScript and in Dart. That is an accepted cost, and it is why the API
> contract and `docs/spec/pricing-vectors.json` are load-bearing: they are the only thing keeping two
> independent front ends consistent. **The public client portal (Wave 10) is this tier's job, not
> Flutter's** — an unauthenticated link opened on mobile data cannot afford a large bundle.

**Scaffolded, not built. The application lands in Wave 8** (`docs/SPEC.md`), on top of the
API contract that Wave 7 pins.

**Scaffolded with the official generator, never by hand** (developer ruling, 2026-07-29 — *"the projects
scaffolds need to be following official recommendations and best practices, not done manually"*):

```
ng new admin --style=scss --ssr=false --zoneless=true --routing=true --ai-config=claude
ng add @angular-eslint/schematics
```

Angular CLI **22.0.9** on Node **26.7.0** (installed from nodejs.org against the published SHA-256; the
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

Pinned on landing: Angular **22.1.3**, Node **26.7.0**.

## What this tier owes on arrival, as gate conditions

**Moved to [`docs/SPEC.md`](../docs/SPEC.md) § 8 — the ONE open register.** The table used to
live here, which meant three tier READMEs each held owed items that existed nowhere else and
nothing cross-checked them. This tier's rows are under *`admin/` owed* there.

Do not re-add a table here: an owed item recorded in two places is an owed item that will be
struck from one of them.


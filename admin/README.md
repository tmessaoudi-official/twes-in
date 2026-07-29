# admin — Angular web client

**Not built yet. Lands in Wave 8** (`docs/plans/build-waves.plan.md`), on top of the API contract that
Wave 7 pins.

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
| Lint, strict TypeScript, production build | `npm run lint`, `ng build --configuration production`. |
| Every dependency permissive, in `THIRD-PARTY-NOTICES.md` | `scripts/gates/dependency-licences.php` already reserves a row for `admin/package-lock.json`. |

**No upstream code, ever.** Invoice Ninja's web UI (`invoiceninja/ui`) is Elastic License 2.0. This is a
clean-room reimplementation — see `CLAUDE.md` § "Licensing invariants".

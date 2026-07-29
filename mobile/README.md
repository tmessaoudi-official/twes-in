# mobile — Flutter client

**Not built yet. Lands in Wave 11** (`docs/plans/build-waves.plan.md`). Mobile first; native desktop
later.

Pinned on landing: Flutter **3.44.8**, Dart **3.12.2**.

## Written from scratch — this one is a legal requirement, not a preference

`invoiceninja/admin-portal` is under the **Attribution Assurance License**, which would require — on
**every launch, forever** — a prominent display of *Hillel Coren* / *Invoice Ninja* /
*invoiceninja.com*. Writing our own client means that duty never attaches. Do not reuse its code "just
for the transport layer": the obligation follows the code, not the quantity of it. `CLAUDE.md` §
"Licensing invariants", item 3.

## What this tier owes on arrival, as gate conditions

| Owed | Why |
|---|---|
| Test taxonomy: **unit · widget · integration** | `flutter test` plus `integration_test` on a real device or emulator. |
| **Semantics / a11y tests in the gate** | Flutter's own semantics assertions. Same reasoning as the admin's `axe-core`. |
| **Golden tests or real screenshots** | `flutter test` asserts a widget tree, not a rendered result. The mobile client is the surface whose regressions are slowest to fix once shipped, because it updates on app-store timelines. |
| **Locale key parity** across `en`/`fr`/`ar`, RTL included | Arabic is shipped. |
| **Consumes `docs/spec/pricing-vectors.json`** | Third of the three implementations of the same money formula. This fixture is the only thing stopping them drifting. |
| `flutter analyze` clean | |
| Every dependency permissive, in `THIRD-PARTY-NOTICES.md` | `scripts/gates/dependency-licences.php` reserves a row for `mobile/pubspec.lock`. |

**Once this ships to a store, the API contract is frozen** for everyone who has not updated. That is
why `CLAUDE.md` treats a contract change as a breaking change with a migration plan.

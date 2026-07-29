# mobile — Flutter client

**Not built yet. Lands in Wave 11** (`docs/plans/build-waves.plan.md`). Mobile ships first in the
sequence, but **all six targets — Android, iOS, Linux, Windows, macOS and Web — are in scope for Wave 11**
(developer ruling, 2026-07-29); desktop is not deferred to some later wave. Sequencing is not scope.

Pinned on landing: Flutter **3.44.8**, Dart **3.12.2** — the current stable channel
[Verified 2026-07-29 against Flutter's release manifest: `stable` is 3.44.8 / Dart 3.12.2, released
2026-07-23]. Beta (3.47.0-0.2.pre) is deliberately not used: a billing client is the wrong place for a
pre-release toolchain.

## Build targets — all six (developer ruling, 2026-07-29)

**In scope: Android · iOS · Linux · Windows · macOS · Web.** Flutter has exactly six targets and all six
ship. What that actually costs, stated plainly, because none of it is a flag you flip:

| Target | Builds on | The part that bites |
|---|---|---|
| **Android** | any OS | Play Store review; `minSdk` decides how much of the API surface is usable |
| **iOS** | **macOS only** | Apple Developer membership, provisioning profiles, App Store review |
| **Linux** | Linux | GTK development headers; packaging is a separate decision (see below) |
| **Windows** | **Windows only** | A code-signing certificate, or every user sees a SmartScreen warning |
| **macOS** | **macOS only** | Notarisation as well as signing; the app is sandboxed by default, which affects file save |
| **Web** | any OS | **RULED in scope**: twes-in ships two admin interfaces, this and the Angular one — the same shape Invoice Ninja offers with Flutter and React. Accepted cost: every admin screen exists twice. The public client portal stays Angular, because an unauthenticated link opened on mobile data cannot afford a large bundle. |

**You cannot cross-compile.** That is the single most consequential fact here: Windows binaries need a
Windows machine, and iOS and macOS need a Mac. So CI is a **three-runner matrix** —
`ubuntu-latest` (Linux + Android + Web), `windows-latest` (Windows), `macos-latest` (macOS + iOS) —
and a release is not "one build", it is six artifacts from three machines. Wave 12 owns wiring that up.

**Signing and distribution are money and admin, not code:** an Apple Developer membership (annual), a
Windows code-signing certificate (annual), and a Play Console registration. Desktop distribution outside
the stores also needs a choice per platform — Snap, Flathub or a plain tarball on Linux; MSIX or a plain
installer on Windows; a notarised `.dmg` on macOS. None of that is decided yet and none of it blocks
Wave 11.

**Six platform capabilities an invoicing client actually needs, each different per target** — worth
naming now because they are the real desktop work, not the build itself:

1. **Viewing and printing a PDF** — an invoice or delivery note is the product's output, and the native
   print path differs on every one of the six.
2. **Saving a file where the user asked** — a save dialog on desktop, a share sheet on mobile, a download
   on web, and a macOS sandbox that constrains all of it.
3. **Secure credential storage** — Keychain, Keystore, libsecret, Windows Credential Manager, and on web
   nothing trustworthy at all. An API token belongs in the platform keystore, never in preferences.
4. **Offline behaviour** — a phone loses signal mid-invoice; a desktop app does not. Whether the client
   is offline-capable is a product decision that changes the whole data layer, and it is **not yet made**.
5. **Deep links** — one invoice URL that opens the right screen on all six.
6. **Window management on desktop** — sizing, multiple windows, keyboard shortcuts, menu bar. A phone
   layout stretched to 2560px is the usual failure, and RTL doubles the surface to check.

**Golden tests must cover a desktop window size, not just a phone one**, or the desktop builds ship
untested layouts while the suite stays green.

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

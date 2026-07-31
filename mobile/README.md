# mobile — Flutter client

**Scaffolded, not built. The client lands in Wave 11** (`docs/plans/build-waves.plan.md`). Mobile ships first
in the sequence, but **all six targets — Android, iOS, Linux, Windows, macOS and Web — are in scope for Wave
11** (developer ruling, 2026-07-29); desktop is not deferred to some later wave. Sequencing is not scope.

**Scaffolded with the official generator, never by hand** (developer ruling, 2026-07-29):

```
flutter create --project-name twes_in --org com.twesin \
  --platforms=android,ios,linux,macos,windows,web mobile
```

Flutter **3.44.8** / Dart **3.12.2** — the current stable channel, matching this file's pin. All six platform
directories exist from the first commit, so no target is a retrofit.

Three departures from what `flutter create` emitted, each deliberate:

1. **The counter demo is gone**, replaced by a minimal shell. It is not our code and not the client.
2. **The product name is injected** (`lib/branding.dart`), never a literal — licensing invariant 9 requires a
   later public deployment to be a config change, not a code change. It matters more here than on the web
   tier: a value baked into a store build cannot change without shipping a binary and waiting for review. A
   test asserts that overriding `Branding` changes what renders.
3. **`analysis_options.yaml` adds the three `strict-*` analyser flags** that the template leaves off, plus
   nine lints. `strict-casts` is the one that earns its place in this domain: JSON from the API arrives as
   `dynamic`, and `final Money total = json['total']` compiling by accident is how a wrong amount reaches a
   document.

**Roboto is vendored, not fetched, and that is a GDPR decision** — `pubspec.yaml` states it in full. Flutter
web's CanvasKit renderer downloads Roboto from `fonts.gstatic.com` when no font asset is declared, which sends
every visitor's IP to Google (LG München I, 3 O 17493/20 held that unlawful without consent) and renders
nothing at all offline. Found by screenshotting the build; `flutter analyze`, `flutter test` and
`flutter build web` were all green while the page rendered blank.

**`--org com.twesin` is a PLACEHOLDER.** Bundle identifiers are compile-time and are *not* covered by the
branding seam. No domain is owned yet, and this must be set to a reverse-DNS name actually controlled
**before any store submission** — changing it after publication means a new listing and losing every
installed user. A Wave 11 gate condition.

Current state: `flutter analyze` clean, then **`flutter build web --release --no-web-resources-cdn` and only
then `flutter test`** — 9 passing. That order matters: three tests read `build/web`, and reversed they skip
while the suite still reports success.

The bundle makes **zero** external requests, including with Arabic text — which the Roboto vendoring alone did
NOT achieve. Flutter Web's engine downloads Noto fallback fonts for any script the bundled fonts do not cover,
from a URL compiled into the bundle defaulting to `fonts.gstatic.com`; measured, the same build issued 0 gstatic
requests with Latin text and 13 with Arabic. `web/flutter_bootstrap.js` pins that origin.

**And a pin with nothing behind it was not a fix, which is worth stating because it looked like one.** With the
origin pinned and no Noto self-hosted, an Arabic glyph made the engine retry a same-origin 404 for the lifetime
of the page and the glyphs rendered as tofu boxes: a per-client flood against our own origin instead of a leak
to Google. **Closed 2026-07-30** by vendoring Noto Sans Arabic (OFL-1.1, permitted for a vendored font asset
only — developer ruling; see `THIRD-PARTY-NOTICES.md` for provenance and `CLAUDE.md` invariant 8(a) for the
rule) and naming it in `ThemeData.fontFamilyFallback`, so the fallback path is never taken for Arabic at all.
Measured on the same release build before and after: **229 HTTP 404s and unshaped glyphs → 0 404s, 0 external
requests, correctly shaped RTL Arabic.**

Two things the tests here cannot do, so do not read them as covering it. **A widget test cannot prove a glyph
paints** — a tree holding an Arabic `Text` is identical whether it shapes correctly or renders tofu — so the
rendered proof is a delivered screenshot, per `CLAUDE.md`'s visual-evidence rule. And **a blank Flutter
screenshot under Playwright is usually the harness**: this container's `LANG` makes headless Chromium report
`navigator.language` as `en-US@posix`, which Flutter's locale parser rejects with `RangeError`, blanking the
page with every test still green. Pass `newContext({locale: 'en-US'})`.

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
| **…including its `document_totals` block, not only `cases`** | Round 18 found both tier READMEs describing this fixture as the *profit-rate* contract — "the same money formula", singular — while `document_totals` was named in no markdown file in the repository. It carries the VAT rounding point, the **per-line VAT column** and two committed near-miss columns. The allocation rule is **unfixable once documents are issued** (`CLAUDE.md` § Gotchas) and this fixture is the stated mechanism that stops each tier inventing its own, so implementing `cases[]` alone does not satisfy this row. |
| `flutter analyze` clean | |
| Every dependency permissive, in `THIRD-PARTY-NOTICES.md` | `scripts/gates/dependency-licences.php` reserves a row for `mobile/pubspec.lock`, whose format carries no licence field. **As of 2026-07-30 the pub tree's per-package licences ARE read** — from the pub cache, since every cached package ships its own licence file — so this is no longer a presence-only check: 24 hosted packages, 24 classified (BSD-3-Clause ×20, Apache-2.0 ×3, MIT ×1). It **fails rather than skips** when it cannot look, so running it needs `flutter pub get` first; a copyleft grant is vetoed even when the same file also contains a permissive paragraph, and a non-`hosted`/non-`sdk` source is refused rather than skipped. **Vendored fonts are fully gated as of 2026-07-30**, which no lock file could ever see: recursively, per binary, a REUSE `.license` sidecar declaring exactly one SPDX identifier, that identifier permissive or on the font-asset list, **every** one of the font's own `name`-table licence records corroborating it, the licence text beside the binary *and* declared under `flutter:`→`assets:` so it ships, the family named in the notices, and — the inverse direction — every font path this manifest declares must have been examined. |
| ~~A test that fails if the built web bundle references ANY external origin~~ **DELIVERED** — `test/no_external_origin_test.dart` | And the specification above was **refuted while implementing it**: grepping `build/web` for `https://` flags 16 legitimate hosts on a clean build (licence text, XML namespaces, spec URLs), so that test would have been deleted the first time somebody needed to ship. It asserts what actually matters instead — the engine's `fontFallbackBaseUrl` is same-origin, CanvasKit is bundled locally, and every font in the bundle ships with a licence. **It reads `build/web`, so `flutter build` must run BEFORE `flutter test`** or the cases skip and the suite still reports success. Round 8 found two holes in it and both are closed: `startsWith('/')` admitted the protocol-relative `//fonts.gstatic.com/s/` — a third-party origin that passes a prefix check — so the value is now parsed and required to have no scheme and no authority; and the font assertion listed two filenames under a title promising "every font it ships" while the bundle held seven, so it now enumerates `build/web`. |
| ~~**`MaterialIcons-Regular.otf` needs a licensing ruling**~~ **RULED 2026-07-30 and DISCHARGED** | `uses-material-design: true` ships it, and its position was genuinely unclear: the Flutter SDK keeps a **CC-BY-4.0** text beside it, Google's icon repository states Apache-2.0 (unverifiable here — GitHub egress is restricted), the binary carries **no nameID 13** so the cross-check cannot run on it, and the shipped copy is **tree-shaken**, i.e. modified. Put to the developer under invariant 10; the ruling was to **comply with the stricter reading, which satisfies both**. `assets/fonts/MaterialIcons-LICENSE.txt` carries the attribution, the licence URI and the statement of modification, and is declared under `assets:` so it ships. Recorded as `FRAMEWORK_PROVIDED_FONTS` in the gate (maximum one entry, asserted) — a **discharged obligation, not a permission**: CC-BY-4.0 is still refused on any Composer, npm or pub package, with a case for each. |
| **The `fontFallbackBaseUrl` pin still has nothing behind it for CJK, Hebrew and emoji** | Bundling Noto Sans Arabic removed the fallback path for Arabic only. Every other uncovered script resolves to a same-origin path with no font under it: **3328 404s in a 40-second load, uncapped, ~83 req/s per tab** [Verified 2026-07-30]. No external transfer, so the GDPR half holds and the availability half does not — and in a billing product the trigger is tenant free text. Not exploitable before this wave, because nothing renders user data yet. **Remedy ruled 2026-07-30** and landing with `infra/` in Wave 12: serve **200** at that prefix with the already-vendored `NotoSansArabic-Regular.ttf` instead of 404 — measured **713 → 17 requests, 712 → 0 404s**, safe because a substituted font yields tofu and never a wrong glyph. Vendoring the whole fallback set is rejected on evidence (143 families, 100–124 CJK shards each, version-hashed). **Web-only** — absent from the framework, so the five native targets never enter this path. |
| **Release builds must be signed with OUR key, never the debug key** | `flutter create` scaffolds `signingConfig = signingConfigs.getByName("debug")` behind a TODO. The Android debug keystore is a fixed, published key, so a debug-signed artifact can be re-signed by anyone and accepted as an update to the same app. Play rejects debug-signed uploads; direct APK and desktop distribution — which this tier contemplates — does not. Now read from `android/key.properties`, and the release build **fails** when that file is absent rather than falling back. |
| **The API token belongs in the platform keystore, never in preferences** | `flutter_secure_storage` or the platform equivalent. This was stated in prose in this file and gated by nothing, which is the same failure the a11y row calls out: a control asserted and never measured is not a control. Assert it — a test that fails if a token-shaped value reaches `SharedPreferences`. |
| **A real reverse-DNS bundle identifier** | `com.twesin` is a placeholder for a domain nobody owns. Compile-time, so it is *not* covered by the branding config seam, and changing it after publication means a new store listing and losing every installed user. |

**Once this ships to a store, the API contract is frozen** for everyone who has not updated. That is
why `CLAUDE.md` treats a contract change as a breaking change with a migration plan.

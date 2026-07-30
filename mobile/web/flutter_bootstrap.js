// This file is part of twes-in.
//
// (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
//
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A CUSTOM BOOTSTRAP, for one reason: to pin the engine's font-fallback origin. This is a GDPR control, not
// a tuning knob.
//
// Vendoring Roboto closed the Roboto fetch and nothing more. Flutter Web's engine downloads NOTO fallback
// fonts for any script the bundled fonts do not cover, from a base URL compiled into the shipped bundle
// whose default is `https://fonts.gstatic.com/s/`. Roboto carries no Arabic, and `ar` is a first-class
// locale here (api/translations/messages.ar.xlf) — so rendering one Arabic string reintroduces exactly the
// third-party transfer the vendoring was meant to end. Certification round 6 measured it on an otherwise
// self-contained build: 0 gstatic requests with Latin text, 13 with Arabic.
//
// Note `window.flutterConfiguration` does NOT work for this — it is the deprecated mechanism and the engine
// ignores it (verified: the gstatic request still fired). The loader's `config` argument is the supported
// path, and reaching it requires this file, because the generated bootstrap calls `load()` itself.
//
// Pointing at a same-origin path means the engine 404s locally instead of reaching Google. That trade — a
// self-inflicted 404 loop instead of a data transfer — is the RIGHT one, and it is still not a finished state.
//
// ARABIC IS DONE (2026-07-30): the developer ruled OFL-1.1 permitted for a vendored font asset, so Noto Sans
// Arabic is bundled as a `fonts:` family and named in ThemeData.fontFamilyFallback. The engine therefore never
// takes this fallback path for Arabic at all, and there is no licensing decision outstanding for it.
//
// STILL OWED, and measured rather than assumed: every OTHER script the bundled fonts do not cover — CJK,
// Hebrew, emoji — still resolves through this path, finds nothing, and retries. [Verified 2026-07-30: a build
// rendering Japanese, Hebrew and an emoji issued 1384 same-origin 404s in a 15-second load and 3328 in 40s,
// linear and uncapped at ~83 req/s per tab, with 0 external requests.] In a billing product the trigger is
// tenant-supplied free text — a client named 株式会社山田商事, an emoji in an invoice note — so one row of
// user data turns every browser rendering it into a request storm against our own origin.
//
// Not exploitable before Wave 11, because nothing renders user data yet. Recorded as owed in
// docs/plans/build-waves.plan.md and mobile/README.md so it is not closed-and-forgotten: the fix is either
// vendoring the fallback set under this path or restricting which scripts the client will render.
{{flutter_js}}
{{flutter_build_config}}

_flutter.loader.load({
  config: {
    fontFallbackBaseUrl: "/assets/fonts/noto/",
  },
});

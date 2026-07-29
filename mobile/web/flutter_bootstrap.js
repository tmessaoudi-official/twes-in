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
// Pointing at a same-origin path means the engine 404s locally instead of reaching Google. OWED at Wave 11:
// self-host the Noto fallback fonts under this path before shipping any Arabic UI, and record their licence
// in THIRD-PARTY-NOTICES.md — Noto is OFL-1.1, which is NOT on this project's permissive list, so it is a
// licensing decision to take deliberately rather than by running `flutter build`.
{{flutter_js}}
{{flutter_build_config}}

_flutter.loader.load({
  config: {
    fontFallbackBaseUrl: "/assets/fonts/noto/",
  },
});

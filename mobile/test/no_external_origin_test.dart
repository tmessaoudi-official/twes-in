// This file is part of twes-in.
//
// (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// The built web bundle must not point the engine at a third-party origin.
///
/// **Why this is not a grep for `https://`.** That is how the control was first specified, and a reviewer
/// showed it is unusable: a clean build legitimately contains 16 external hosts in licence text, XML
/// namespaces and spec URLs (`w3.org`, `apache.org`, `unicode.org`…). A test that fails on all of those gets
/// deleted the first time somebody needs to ship.
///
/// What actually matters is narrower and checkable: the two places the *engine* is told where to fetch from.
///  - `fontFallbackBaseUrl` — Flutter Web downloads Noto fallback fonts for any script the bundled fonts do
///    not cover. Its default is `https://fonts.gstatic.com/s/`, so leaving it unset sends every visitor's IP
///    to Google the moment an Arabic (or CJK, or Hebrew) glyph renders. `ar` is a first-class locale here.
///  - the CanvasKit base URL — `--no-web-resources-cdn` bundles it locally; without that flag the engine
///    fetches the wasm from `gstatic.com` on every load.
///
/// Certification round 6 found the first of these live: vendoring Roboto closed the Roboto fetch and nothing
/// more, and the same build issued 0 gstatic requests with Latin text and 13 with Arabic.
///
/// Skipped rather than failed when `build/web` is absent, because `flutter test` must not require a prior
/// `flutter build`. CI runs the build first, so it is a real gate there.
void main() {
  group('the built web bundle', () {
    final buildDir = Directory('build/web');

    test('pins the engine font fallback to our own origin', () {
      if (!buildDir.existsSync()) {
        markTestSkipped('build/web absent — run: flutter build web --release --no-web-resources-cdn');
        return;
      }

      final bootstrap = File('build/web/flutter_bootstrap.js');
      expect(bootstrap.existsSync(), isTrue, reason: 'the bootstrap must be built');

      final source = bootstrap.readAsStringSync();

      expect(
        source,
        contains('fontFallbackBaseUrl'),
        reason: 'Unset means the engine defaults to https://fonts.gstatic.com/s/ — see web/flutter_bootstrap.js',
      );

      // And the configured value must be same-origin, not merely present.
      final match = RegExp(r'''fontFallbackBaseUrl\s*:\s*["']([^"']*)["']''').firstMatch(source);
      expect(match, isNotNull, reason: 'fontFallbackBaseUrl must be a literal string');
      expect(
        match!.group(1),
        startsWith('/'),
        reason: 'An absolute URL here is a third-party transfer; only a same-origin path is acceptable.',
      );
    });

    test('bundles CanvasKit locally instead of fetching it from a CDN', () {
      if (!buildDir.existsSync()) {
        markTestSkipped('build/web absent');
        return;
      }

      // --no-web-resources-cdn writes canvaskit/ into the bundle. Its absence means the engine will fetch
      // the wasm from gstatic.com on every load — and, in an offline or restricted network, render nothing
      // at all while every unit test and the build itself stay green.
      expect(
        Directory('build/web/canvaskit').existsSync(),
        isTrue,
        reason: 'Build with --no-web-resources-cdn, or the engine fetches CanvasKit from a Google CDN.',
      );
    });
  });
}

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

      final String configured = match!.group(1)!;

      // `startsWith('/')` ALONE CERTIFIED ITS OWN BYPASS, which is why this is three assertions and not one.
      // A protocol-relative URL — `//fonts.gstatic.com/s/` — starts with `/` and is a third-party origin, so
      // the single check admitted precisely the transfer it exists to forbid. [Verified 2026-07-30: setting
      // that value kept this test green while the build issued 16 requests to fonts.gstatic.com for Hebrew,
      // Japanese and emoji glyphs.] Parse it instead of pattern-matching a prefix: a same-origin reference
      // has no scheme and no authority, and `Uri` is the thing that actually knows the difference.
      final Uri parsed = Uri.parse(configured);

      expect(configured, startsWith('/'), reason: 'must be a root-relative path, not a bare or absolute URL');
      expect(parsed.hasScheme, isFalse, reason: 'a scheme means a third-party origin: $configured');
      expect(
        parsed.hasAuthority,
        isFalse,
        reason: 'an authority — including the protocol-relative //host form — is a third-party transfer: '
            '$configured',
      );
    });

    test('carries every font it ships, and each font licence with it', () {
      if (!buildDir.existsSync()) {
        markTestSkipped('build/web absent');
        return;
      }

      // A PIN WITH NOTHING BEHIND IT IS NOT A FIX, and this is the test that says so. `fontFallbackBaseUrl`
      // pointing at our own origin with no Noto under it does stop the transfer to Google — and then the
      // engine retries a 404 for the page's lifetime and renders Arabic as tofu boxes.
      // [Verified 2026-07-30 on exactly that build: 229 HTTP 404s in a 12-second load.]
      //
      // ENUMERATE THE BUNDLE — do not list names. The first version of this test hardcoded two filenames
      // under a title promising "every font it ships", and the bundle held SEVEN: it was blind to the three
      // other vendored weights and, more importantly, to `assets/fonts/MaterialIcons-Regular.otf`, which
      // `uses-material-design: true` ships with no licence text anywhere in the artifact. Listing instances
      // where the title claims a class is the exact shape CLAUDE.md records against `test-gates.sh`.
      final List<String> shipped = Directory('build/web')
          .listSync(recursive: true)
          .whereType<File>()
          .map((File f) => f.path)
          .where((String p) => p.endsWith('.ttf') || p.endsWith('.otf'))
          .toList()
        ..sort();

      expect(shipped, isNotEmpty, reason: 'no font found in build/web — this test would assert nothing');

      // Every family declared in pubspec must be present, derived from the manifest rather than named here.
      final String pubspec = File('pubspec.yaml').readAsStringSync();
      final Iterable<String> declaredAssets = RegExp(r'^\s+- asset:\s*(\S+)', multiLine: true)
          .allMatches(pubspec)
          .map((RegExpMatch m) => m.group(1)!.split('/').last);

      expect(declaredAssets, isNotEmpty, reason: 'the pubspec asset parse found nothing');

      for (final String font in declaredAssets) {
        expect(
          shipped.any((String p) => p.endsWith('/$font')),
          isTrue,
          reason: '$font is declared but not in the bundle, so the engine reaches for its fallback instead',
        );
      }

      // And every shipped font must have a licence in the artifact. Flutter's generated assets/NOTICES
      // aggregates LICENSE files from packages, not from app assets, so before the texts were declared under
      // `assets:` the bundle carried both families and mentioned neither licence — every test green.
      //
      // NO EXCEPTIONS IN THIS LOOP. `MaterialIcons-Regular.otf` was exempt for one commit, on the honest
      // grounds that its licence position was an open invariant-10 question — the SDK ships a CC-BY-4.0 text
      // beside it, Google's icon repository states Apache-2.0, the binary carries no nameID 13, and the
      // shipped copy is tree-shaken. The developer ruled (2026-07-30) to comply with the stricter reading,
      // which satisfies both, so the obligation is discharged by MaterialIcons-LICENSE.txt travelling in the
      // bundle and the exemption is gone. Round 8's lesson was that an exemption inside a check is where the
      // drift hides; keeping one here after the ruling would have been the same mistake with a better excuse.
      for (final String path in shipped) {
        final String name = path.split('/').last;

        // Either a licence text ANYWHERE in the bundle, or a mention in the generated NOTICES. Anywhere, not
        // beside it: `fonts:` assets and `assets:` assets land in different directories, so a sibling check
        // would fail for a font the framework injects while the obligation is in fact discharged. Strip the
        // extension BEFORE deriving the family, or `CupertinoIcons.ttf` (no hyphen) yields a family of
        // "CupertinoIcons.ttf" and looks for a licence nobody would ever name that.
        final String family = name.substring(0, name.lastIndexOf('.')).split('-').first;
        final bool besideIt = Directory('build/web')
            .listSync(recursive: true)
            .whereType<File>()
            .any((File f) => f.path.endsWith('/$family-LICENSE.txt'));

        // Normalised comparison, because a package and its font disagree on spelling: the bundle ships
        // `CupertinoIcons.ttf` and NOTICES credits `cupertino_icons`. Dropping non-alphanumerics makes those
        // the same string without making the check so loose that any substring satisfies it.
        String squash(String s) => s.toLowerCase().replaceAll(RegExp('[^a-z0-9]'), '');

        final File notices = File('build/web/assets/NOTICES');
        final bool inNotices =
            notices.existsSync() && squash(notices.readAsStringSync()).contains(squash(family));

        expect(
          besideIt || inNotices,
          isTrue,
          reason: 'Apache-2.0 s4(a) and OFL-1.1 s2 require the licence to accompany what we distribute, and '
              '$name ships with neither a $family-LICENSE.txt beside it nor a mention in assets/NOTICES',
        );
      }
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

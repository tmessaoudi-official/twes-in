// This file is part of twes-in.
//
// (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:twes_in/branding.dart';
import 'package:twes_in/main.dart';

/// Every font this client ships must be bundled, declared, referenced AND licensed.
///
/// Four separate claims, because in this project's history each one has been true while another was false:
/// Roboto was bundled and declared before it was referenced by the theme, and Noto's `fontFallbackBaseUrl`
/// was pinned same-origin with no font behind the pin — a fix that stopped a GDPR transfer and rendered no
/// Arabic at all.
///
/// **What these tests deliberately do NOT claim.** None of them proves a glyph paints. A widget tree holding
/// an Arabic `Text` is identical whether the glyphs shape correctly or come out as tofu boxes, so the
/// rendered proof is a screenshot of `ScriptCoverageCheck` — see CLAUDE.md's visual-evidence rule, and
/// `api/translations/messages.ar.xlf`, which says in as many words that Arabic "must be proven with a
/// rendered document, never asserted". These tests cover the parts a screenshot cannot: that the assets are
/// where the manifest says, and that removing the theme wiring fails something.
void main() {
  group('the bundled fonts', () {
    final pubspec = File('pubspec.yaml').readAsStringSync();

    // Family -> the asset paths pubspec.yaml declares for it. Read from the manifest rather than hardcoded,
    // so adding a weight without shipping the file is a failure rather than an untested change.
    final declared = <String, List<String>>{};
    String? family;

    for (final line in pubspec.split('\n')) {
      final familyMatch = RegExp(r'^\s{4}- family:\s*(.+)$').firstMatch(line);

      if (familyMatch != null) {
        family = familyMatch.group(1)!.trim();
        declared[family] = <String>[];
        continue;
      }

      final assetMatch = RegExp(r'^\s+- asset:\s*(\S+)$').firstMatch(line);

      if (assetMatch != null && family != null) {
        declared[family]!.add(assetMatch.group(1)!);
      }
    }

    test('are declared for both Latin and Arabic', () {
      expect(
        declared.keys,
        containsAll(<String>['Roboto', 'Noto Sans Arabic']),
        reason: 'Roboto covers no Arabic script, and ar is a shipped locale.',
      );
    });

    test('all exist on disk, with a licence text and a REUSE sidecar beside each', () {
      expect(declared, isNotEmpty, reason: 'the pubspec font parse found nothing — fix this test, not the app');

      for (final entry in declared.entries) {
        for (final asset in entry.value) {
          final font = File(asset);
          expect(font.existsSync(), isTrue, reason: '$asset is declared in pubspec.yaml but not committed');

          // REUSE 3.0 sidecar: a font binary carries no comment syntax, so the SPDX tag lives beside it.
          // scripts/gates/dependency-licences.php cross-checks it against the font's own name table; this
          // only asserts it exists, because a Dart test has no business reparsing an OpenType table.
          expect(
            File('$asset.license').existsSync(),
            isTrue,
            reason: '$asset.license is missing — see scripts/gates/dependency-licences.php',
          );

          // OFL-1.1 section 2 and Apache-2.0 section 4(a) both require the licence to travel with the files.
          final prefix = asset.split('/').last.split('-').first;
          expect(
            File('assets/fonts/$prefix-LICENSE.txt').existsSync(),
            isTrue,
            reason: 'the licence text must sit beside the binary, not only in THIRD-PARTY-NOTICES.md',
          );
        }
      }
    });
  });

  group('the theme', () {
    // THE WIRING, and the one thing here worth a mutation check: deleting `fontFamilyFallback` from
    // ThemeData leaves the fonts bundled, declared, licensed and shipped — and unreachable, so the engine
    // falls back to fontFallbackBaseUrl instead. Verified 2026-07-30 that removing that one line fails this
    // test, which is the only reason it is worth having.
    testWidgets('names the bundled Arabic family as a fallback, so the engine never needs the network',
        (WidgetTester tester) async {
      await tester.pumpWidget(const TwesInApp(branding: Branding.placeholder()));

      final ThemeData theme = Theme.of(tester.element(find.byType(ScriptCoverageCheck)));

      // Read off the resolved text theme, not off ThemeData: `fontFamily` and `fontFamilyFallback` are
      // constructor parameters that ThemeData applies into its Typography and does not re-expose as getters,
      // so the resolved TextStyle is where the wiring is observable — and it is also what a widget actually
      // uses, which makes it the better assertion anyway.
      expect(theme.textTheme.bodyLarge?.fontFamily, 'Roboto');
      expect(
        theme.textTheme.bodyLarge?.fontFamilyFallback,
        contains('Noto Sans Arabic'),
        reason: 'Without this the bundled Noto is dead weight and Arabic reaches for fontFallbackBaseUrl.',
      );
    });

    testWidgets('renders the Arabic sample from the shipped catalogue', (WidgetTester tester) async {
      await tester.pumpWidget(const TwesInApp(branding: Branding.placeholder()));

      // Present and laid out RTL. That the glyphs SHAPE is a screenshot's job, not this test's.
      expect(find.text(ScriptCoverageCheck.arabicSample), findsOneWidget);
      expect(
        tester.widget<Directionality>(
          find.ancestor(
            of: find.text(ScriptCoverageCheck.arabicSample),
            matching: find.byType(Directionality),
          ).first,
        ).textDirection,
        TextDirection.rtl,
      );
    });
  });
}

// This file is part of twes-in.
//
// (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import 'package:flutter/material.dart';

import 'branding.dart';

/// Deliberately minimal.
///
/// `flutter create` scaffolds a counter demo — a `FloatingActionButton`, a mutable `_counter` and the
/// Flutter logo. None of it is ours and none of it is the client, so it is replaced here rather than left
/// to be deleted later: the first commit of this tier contains no demo code and no vendor marketing.
///
/// The real client lands in Wave 11 — see README.md for this tier's gate conditions and its six targets.
void main() {
  runApp(const TwesInApp(branding: Branding.placeholder()));
}

class TwesInApp extends StatelessWidget {
  const TwesInApp({required this.branding, super.key});

  final Branding branding;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      // From configuration, never a literal: licensing invariant 9. A test asserts that changing the
      // Branding changes what renders, so the invariant is checked rather than merely intended.
      title: branding.productName,
      theme: ThemeData(
        colorSchemeSeed: const Color(0xFF00695C),
        useMaterial3: true,
        // The BUNDLED Roboto, named explicitly. Without this, Flutter web's CanvasKit renderer fetches
        // Roboto from fonts.gstatic.com on every load — see pubspec.yaml for why that is a GDPR problem
        // and not merely a packaging one.
        fontFamily: 'Roboto',
        // Roboto covers no Arabic, and `ar` is a shipped locale (api/translations/messages.ar.xlf). Without
        // this line the bundled Noto Sans Arabic is dead weight that still ships: the engine would reach for
        // its own fallback path instead, which on the web means fontFallbackBaseUrl — pinned same-origin, so
        // a 404 loop and no glyphs. Naming the family here is what makes the fallback path unnecessary.
        fontFamilyFallback: const <String>['Noto Sans Arabic'],
      ),
      home: PlaceholderScreen(branding: branding),
    );
  }
}

class PlaceholderScreen extends StatelessWidget {
  const PlaceholderScreen({required this.branding, super.key});

  final Branding branding;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(branding.productName)),
      body: const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              Text(
                'Flutter client — scaffolded, not yet built.\n'
                'See docs/SPEC.md, Wave 11.',
                textAlign: TextAlign.center,
              ),
              SizedBox(height: 24),
              ScriptCoverageCheck(),
            ],
          ),
        ),
      ),
    );
  }
}

/// The bundled fonts' script coverage, rendered rather than asserted.
///
/// `api/translations/messages.ar.xlf` says it in as many words: Arabic reaches the admin, this client AND
/// the generated PDFs, and it "must be proven with a rendered document, never asserted". A widget test
/// cannot prove it — `flutter test` asserts a widget tree, and a tree containing an Arabic `Text` is
/// identical whether the glyphs paint or come out as tofu boxes. So the claim is made visible on whichever
/// of the six targets you happen to run, and a screenshot of this row is the evidence.
///
/// It earns its place for one build only. **Wave 11 replaces this with the real UI**, at which point the
/// proof moves to that UI's own golden or screenshot — an Arabic invoice is the real subject. Until then
/// deleting this leaves the font claim unproven on every target at once, which is how it was wrong before.
class ScriptCoverageCheck extends StatelessWidget {
  const ScriptCoverageCheck({super.key});

  /// From the shipped catalogue, not invented here: `error.not_found`, Arabic target.
  static const String arabicSample = 'المورد المطلوب غير موجود.';

  @override
  Widget build(BuildContext context) {
    return Column(
      children: <Widget>[
        Text(
          'Script coverage — bundled fonts only, no network:',
          style: Theme.of(context).textTheme.labelMedium,
        ),
        const SizedBox(height: 8),
        // Latin from Roboto. Arabic from the bundled Noto via fontFamilyFallback, laid out RTL so the
        // shaping and the direction are both visible: unshaped Arabic renders as disconnected letters, which
        // a screenshot shows and no assertion does.
        const Text('Latin — 1 234,567 TND'),
        Directionality(
          textDirection: TextDirection.rtl,
          child: Text(arabicSample, style: Theme.of(context).textTheme.bodyLarge),
        ),
      ],
    );
  }
}

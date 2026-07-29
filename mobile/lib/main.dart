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
          child: Text(
            'Flutter client — scaffolded, not yet built.\n'
            'See docs/plans/build-waves.plan.md, Wave 11.',
            textAlign: TextAlign.center,
          ),
        ),
      ),
    );
  }
}

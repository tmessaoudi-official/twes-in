// This file is part of twes-in.
//
// (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import 'package:flutter_test/flutter_test.dart';
import 'package:twes_in/branding.dart';
import 'package:twes_in/main.dart';

void main() {
  /// Licensing invariant 9 asserted rather than trusted.
  ///
  /// The product name must come from configuration, so overriding it must change what renders. A hardcoded
  /// name would pass a "renders the name" test and fail this one — which is the point. It matters more on
  /// this tier than on the web one: a value baked into a store build cannot be changed without shipping a
  /// new binary and waiting for review.
  testWidgets('renders the product name from Branding rather than a hardcoded string', (tester) async {
    await tester.pumpWidget(
      const TwesInApp(
        branding: Branding(productName: 'A Different Name', supportEmail: 'x@example.invalid'),
      ),
    );

    expect(find.text('A Different Name'), findsAtLeast(1));

    // And the placeholder default is NOT what rendered, so the override is genuinely taking effect.
    expect(find.text('twes-in'), findsNothing);
  });

  testWidgets('the placeholder default is used when nothing overrides it', (tester) async {
    await tester.pumpWidget(const TwesInApp(branding: Branding.placeholder()));

    expect(find.text('twes-in'), findsAtLeast(1));
  });
}

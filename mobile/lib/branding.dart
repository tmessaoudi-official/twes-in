// This file is part of twes-in.
//
// (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

/// Branding is CONFIGURATION, never a literal — `CLAUDE.md` licensing invariant 9.
///
/// The same seam as the Angular tier's `BRANDING` token, for the same reason: deployment starts Docker-only
/// with no public domain, so a later public one must be a **config change, not a code change**.
///
/// The mobile client has a constraint the web client does not: it is delivered through app stores, so a
/// value baked into a release build cannot be changed without shipping a new binary and waiting for review.
/// That makes the seam more important here, not less — and it is why the values arrive through the widget
/// tree rather than from a compile-time `const`.
class Branding {
  const Branding({required this.productName, required this.supportEmail});

  /// Placeholders, and deliberately not a product name — choosing one here would be the hardcoding this
  /// class exists to prevent. Wave 11 supplies the real values from deployment configuration.
  const Branding.placeholder()
      : productName = 'twes-in',
        supportEmail = 'support@example.invalid';

  final String productName;
  final String supportEmail;
}

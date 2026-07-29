/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { InjectionToken } from '@angular/core';

/**
 * Branding is CONFIGURATION, never a literal — `CLAUDE.md` licensing invariant 9.
 *
 * Deployment starts Docker-only with no public domain, so the product name, logo and e-mail identity must
 * be changeable for a later public deployment by a **config change, not a code change**. The seam exists
 * from this tier's first commit rather than being retrofitted, because a name hardcoded into forty
 * templates is not a refactor anybody schedules.
 *
 * A token, not a `const`: Wave 8 replaces the default with a value fetched at bootstrap, and every consumer
 * injects rather than imports, so that swap touches one provider.
 */
export interface Branding {
  readonly productName: string;
  readonly supportEmail: string;
}

export const BRANDING = new InjectionToken<Branding>('twes-in branding', {
  factory: (): Branding => ({
    // Placeholders, and deliberately not a product name: choosing one here would be the hardcoding this
    // token exists to prevent. Wave 8 supplies the real values from deployment config.
    productName: 'twes-in',
    supportEmail: 'support@example.invalid',
  }),
});

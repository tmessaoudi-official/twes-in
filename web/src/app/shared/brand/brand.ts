// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, signal, type Signal } from '@angular/core';
import { type Language, LanguageFacade } from '../i18n/language-facade';

/** What an installation calls itself on the pages people see before signing in. */
export interface BrandKit {
  readonly name: string;
  readonly tagline: Readonly<Record<Language, string>>;
}

/** The defaults the developer chose (docs/SPEC.md Decisions Log, 2026-09-16), until an operator sets their own. */
export const DEFAULT_BRAND_KIT: BrandKit = {
  name: 'twes-in',
  tagline: {
    fr: 'Tout en lieu sûr. Tout tracé. Rien ne se perd.',
    en: 'All kept safe. All on record. Nothing lost.',
  },
};

/**
 * The port the signed-out pages read the brand through. Its first adapter serves the defaults; row 36 answers it
 * from the platform settings without touching a page.
 */
@Injectable({ providedIn: 'root', useFactory: () => inject(DefaultBrand) })
export abstract class Brand {
  abstract readonly name: Signal<string>;
  /** In the interface language. */
  abstract readonly tagline: Signal<string>;
}

@Injectable({ providedIn: 'root' })
export class DefaultBrand extends Brand {
  private readonly kit = signal(DEFAULT_BRAND_KIT);
  private readonly language = inject(LanguageFacade).current;

  readonly name = computed(() => this.kit().name);
  readonly tagline = computed(() => this.kit().tagline[this.language()]);
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, effect, inject, Injectable, signal } from '@angular/core';
import {
  applyColourTokens,
  assertAccentColour,
  type ColourScheme,
  colourTokens,
} from './accent-theme';

export type Density = 'comfortable' | 'compact';

/**
 * The accent used until presentation settings supply one: browser storage at G2b, then the platform → company →
 * role → user chain at G3b (docs/SPEC.md § 3 Settings). It is a declared default, not a hardcoded look.
 */
export const DEFAULT_ACCENT = '#1f6feb';

/**
 * The look of the whole application, as signals. One effect writes the accent's colour tokens and the scheme and
 * density classes onto the document root, which is all styles.scss and Tailwind read.
 */
@Injectable({ providedIn: 'root' })
export class ThemeFacade {
  private readonly root = inject(DOCUMENT).documentElement;
  private readonly accentSignal = signal(DEFAULT_ACCENT);
  private readonly schemeSignal = signal<ColourScheme>('light');
  private readonly densitySignal = signal<Density>('comfortable');

  readonly accent = this.accentSignal.asReadonly();
  readonly scheme = this.schemeSignal.asReadonly();
  readonly density = this.densitySignal.asReadonly();

  constructor() {
    effect(() => {
      const scheme = this.schemeSignal();
      applyColourTokens(this.root, colourTokens(this.accentSignal(), scheme));
      this.root.classList.toggle('theme-dark', scheme === 'dark');
      this.root.classList.toggle('density-compact', this.densitySignal() === 'compact');
    });
  }

  /** Throws InvalidAccentColour for anything but #rrggbb, and keeps the current accent. */
  setAccent(accent: string): void {
    assertAccentColour(accent);
    this.accentSignal.set(accent.toLowerCase());
  }

  setScheme(scheme: ColourScheme): void {
    this.schemeSignal.set(scheme);
  }

  toggleScheme(): void {
    this.schemeSignal.update((scheme) => (scheme === 'dark' ? 'light' : 'dark'));
  }

  setDensity(density: Density): void {
    this.densitySignal.set(density);
  }
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, effect, inject, Injectable } from '@angular/core';
import { SettingsFacade } from '../settings/settings-facade';
import { type Density, PRESENTATION } from '../settings/settings-registry';
import {
  applyColourTokens,
  assertAccentColour,
  type ColourScheme,
  colourTokens,
  statusTokens,
} from './accent-theme';

export { DEFAULT_ACCENT, type Density } from '../settings/settings-registry';

/**
 * The look of the whole application, as signals read through the presentation settings, so a choice outlives
 * the page. One effect writes the accent's colour tokens and the scheme and density classes onto the document
 * root, which is all styles.scss and Tailwind read.
 */
@Injectable({ providedIn: 'root' })
export class ThemeFacade {
  private readonly root = inject(DOCUMENT).documentElement;
  private readonly settings = inject(SettingsFacade);

  readonly accent = this.settings.value(PRESENTATION.accent);
  readonly scheme = this.settings.value(PRESENTATION.scheme);
  readonly density = this.settings.value(PRESENTATION.density);

  constructor() {
    effect(() => {
      const scheme = this.scheme();
      applyColourTokens(this.root, {
        ...colourTokens(this.accent(), scheme),
        ...statusTokens(this.accent(), scheme),
      });
      this.root.classList.toggle('theme-dark', scheme === 'dark');
      this.root.classList.toggle('density-compact', this.density() === 'compact');
    });
  }

  /** Throws InvalidAccentColour for anything but #rrggbb, and keeps the current accent. */
  setAccent(accent: string): void {
    assertAccentColour(accent);
    this.settings.set(PRESENTATION.accent, accent.toLowerCase());
  }

  setScheme(scheme: ColourScheme): void {
    this.settings.set(PRESENTATION.scheme, scheme);
  }

  toggleScheme(): void {
    this.setScheme(this.scheme() === 'dark' ? 'light' : 'dark');
  }

  setDensity(density: Density): void {
    this.settings.set(PRESENTATION.density, density);
  }

  toggleDensity(): void {
    this.setDensity(this.density() === 'compact' ? 'comfortable' : 'compact');
  }
}

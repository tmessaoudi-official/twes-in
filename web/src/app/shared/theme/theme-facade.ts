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
  private readonly view = inject(DOCUMENT).defaultView;
  private readonly settings = inject(SettingsFacade);

  readonly accent = this.settings.value(PRESENTATION.accent);
  readonly scheme = this.settings.value(PRESENTATION.scheme);
  readonly density = this.settings.value(PRESENTATION.density);
  readonly sidebar = this.settings.value(PRESENTATION.sidebar);

  constructor() {
    effect(() => {
      const scheme = this.scheme();
      // Every transition is held while the colours change (styles.scss, .theme-changing), or each element with a
      // colour transition fades from the old scheme's colours: dark text on the dark ground for a fifth of a second
      // after every page load in dark, which is what axe read as an unstable contrast (docs/SPEC.md § 8 row 28).
      this.root.classList.add('theme-changing');
      applyColourTokens(this.root, {
        ...colourTokens(this.accent(), scheme),
        ...statusTokens(scheme),
      });
      this.root.classList.toggle('theme-dark', scheme === 'dark');
      this.root.classList.toggle('density-compact', this.density() === 'compact');
      // Reading a computed style makes the browser apply the new colours now, while transitions are still held.
      void this.view?.getComputedStyle(this.root).color;
      this.view?.requestAnimationFrame(() => this.root.classList.remove('theme-changing'));
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

  toggleSidebar(): void {
    this.settings.set(PRESENTATION.sidebar, this.sidebar() === 'rail' ? 'expanded' : 'rail');
  }
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  computed,
  DestroyRef,
  DOCUMENT,
  effect,
  inject,
  Injectable,
  InjectionToken,
  signal,
} from '@angular/core';
import { SettingsFacade } from '../settings/settings-facade';
import { type Density, PRESENTATION, type SchemePreference } from '../settings/settings-registry';
import {
  accentTokens,
  applyColourTokens,
  assertAccentColour,
  type ColourScheme,
  colourTokens,
  statusTokens,
} from './accent-theme';

export { DEFAULT_ACCENT, type Density, type SchemePreference } from '../settings/settings-registry';

/** What Automatique follows: the device's `prefers-color-scheme: dark`, and its changes. */
export type DeviceSchemeQuery = Pick<
  MediaQueryList,
  'matches' | 'addEventListener' | 'removeEventListener'
>;

export const DEVICE_SCHEME_QUERY = new InjectionToken<DeviceSchemeQuery | null>(
  'DEVICE_SCHEME_QUERY',
  {
    providedIn: 'root',
    factory: () =>
      inject(DOCUMENT).defaultView?.matchMedia?.('(prefers-color-scheme: dark)') ?? null,
  },
);

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
  private readonly deviceQuery = inject(DEVICE_SCHEME_QUERY);
  private readonly deviceDark = signal(this.deviceQuery?.matches ?? false);

  readonly accent = this.settings.value(PRESENTATION.accent);
  /** What the person chose, Automatique included. */
  readonly preference = this.settings.value(PRESENTATION.scheme);
  /** The scheme drawn: the choice, or the device's while the choice is Automatique. */
  readonly scheme = computed((): ColourScheme => {
    const preference = this.preference();
    return preference === 'auto' ? (this.deviceDark() ? 'dark' : 'light') : preference;
  });
  readonly density = this.settings.value(PRESENTATION.density);
  readonly sidebar = this.settings.value(PRESENTATION.sidebar);
  /** The settings area's own answer, so folding one menu does not fold the other. */
  readonly settingsSidebar = this.settings.value(PRESENTATION.settingsSidebar);
  /** Whether the menus show the parts of the vision not built yet, each marked « Bientôt ». */
  readonly showComing = this.settings.value(PRESENTATION.showComing);

  constructor() {
    const query = this.deviceQuery;
    if (query !== null) {
      const follow = () => this.deviceDark.set(query.matches);
      query.addEventListener('change', follow);
      inject(DestroyRef).onDestroy(() => query.removeEventListener('change', follow));
    }
    effect(() => {
      const scheme = this.scheme();
      // Every transition is held while the colours change (styles.scss, .theme-changing), or each element with a
      // colour transition fades from the old scheme's colours: dark text on the dark ground for a fifth of a second
      // after every page load in dark, which is what axe read as an unstable contrast (docs/SPEC.md § 8 row 28).
      this.root.classList.add('theme-changing');
      applyColourTokens(this.root, {
        ...colourTokens(this.accent(), scheme),
        ...accentTokens(this.accent(), scheme),
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

  setScheme(scheme: SchemePreference): void {
    this.settings.set(PRESENTATION.scheme, scheme);
  }

  setDensity(density: Density): void {
    this.settings.set(PRESENTATION.density, density);
  }

  toggleDensity(): void {
    this.setDensity(this.density() === 'compact' ? 'comfortable' : 'compact');
  }

  setShowComing(show: boolean): void {
    this.settings.set(PRESENTATION.showComing, show);
  }

  /** Folds or unfolds the menu of the area the person is in; each area remembers its own answer. */
  toggleSidebar(inSettings = false): void {
    const setting = inSettings ? PRESENTATION.settingsSidebar : PRESENTATION.sidebar;
    const current = inSettings ? this.settingsSidebar() : this.sidebar();
    this.settings.set(setting, current === 'rail' ? 'expanded' : 'rail');
  }
}

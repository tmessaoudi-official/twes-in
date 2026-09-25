// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  isDevMode,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatListModule } from '@angular/material/list';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { filter, map } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { ThemeFacade } from '../shared/theme/theme-facade';
import {
  COMING_NAV,
  type Gated,
  navSections,
  SETTINGS_NAV,
  SETTINGS_SECTIONS,
  visibleEntries,
  withComing,
} from './nav-manifest';

/** Where the list of settings stands alone: what the gear opens on a phone (docs/SPEC.md § 8 row 35). */
export const SETTINGS_INDEX = '/company';

/**
 * The company settings, reached from the gear in the top bar: their grouped navigation, with a filter, beside the
 * settings page. It is a layout route with no path of its own, so every settings page keeps its address. Below the
 * large window class the two panes take turns (variant A of row 32): the list alone at `/company`, then one page with
 * the way back to the list.
 */
@Component({
  selector: 'app-settings-area',
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    MatFormFieldModule,
    MatInputModule,
    MatListModule,
    MatIconModule,
    TranslatePipe,
  ],
  templateUrl: './settings-area.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsArea {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly translate = inject(TranslateService);
  private readonly theme = inject(ThemeFacade);

  protected readonly index = SETTINGS_INDEX;
  protected readonly query = signal('');
  private readonly url = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );
  /** A change of language re-reads the names the filter compares against. */
  private readonly language = toSignal(
    this.translate.onLangChange.pipe(map((event) => event.lang)),
    {
      initialValue: this.translate.getCurrentLang() ?? '',
    },
  );
  protected readonly atIndex = computed(() => this.url().split(/[?#]/)[0] === SETTINGS_INDEX);

  protected readonly sections = computed(() => {
    this.language();
    const words = normalized(this.query())
      .split(/\s+/)
      .filter((word) => word !== '');
    const visible = <T extends Gated>(entries: readonly T[]): readonly T[] =>
      visibleEntries(
        entries,
        (permission) => this.auth.hasPermission(permission),
        isDevMode(),
        (module) => this.auth.hasModule(module),
      );
    const settingsComing = COMING_NAV.filter((entry) => SETTINGS_SECTIONS.includes(entry.section));
    const entries = withComing(
      visible(SETTINGS_NAV),
      visible(settingsComing),
      this.theme.showComing(),
    ).filter((entry) => {
      const names = normalized(
        `${this.translate.instant(entry.labelKey)} ${this.translate.instant(`nav.sections.${entry.section}`)}`,
      );
      return words.every((word) => names.includes(word));
    });
    return navSections(entries, SETTINGS_SECTIONS);
  });
}

/** Lower case without accents, so "societe" finds "Société". */
function normalized(text: string): string {
  return text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLocaleLowerCase();
}

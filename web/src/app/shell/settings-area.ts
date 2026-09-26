// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  ElementRef,
  inject,
  Injector,
  isDevMode,
  signal,
  viewChild,
} from '@angular/core';
import { BreakpointObserver } from '@angular/cdk/layout';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatListModule } from '@angular/material/list';
import { MatTooltipModule } from '@angular/material/tooltip';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { filter, map } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { Label } from '../shared/a11y/label';
import { NavScroller } from '../shared/ui/nav-scroller';
import { SETTINGS_BESIDE_WINDOW } from '../shared/ui/window-class';
import { sectionFolds } from '../shared/ui/section-folds';
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
    NavScroller,
    MatButtonModule,
    MatTooltipModule,
    Label,
  ],
  templateUrl: './settings-area.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsArea {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly translate = inject(TranslateService);
  protected readonly theme = inject(ThemeFacade);
  private readonly injector = inject(Injector);

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
  /** Whether the list sits beside the page; narrower, the two take turns and the list never folds (row 151). */
  protected readonly beside = toSignal(
    inject(BreakpointObserver)
      .observe(SETTINGS_BESIDE_WINDOW)
      .pipe(map((state) => state.matches)),
    { initialValue: false },
  );
  /**
   * The list folded to its 80 px rail (docs/SPEC.md § 7, 2026-09-26 11:17, row 151): each page's icon with a short
   * name, the headings as lines, the filter as a search icon. Only where the list sits beside the page.
   */
  protected readonly rail = computed(() => this.beside() && this.theme.settingsList() === 'rail');
  private readonly filterField = viewChild<ElementRef<HTMLInputElement>>('filterField');

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
  /** The section holding the settings page on view, which opens whatever the person folded (row 152). */
  private readonly currentSection = computed(() => {
    const path = this.url().split(/[?#]/)[0];
    return (
      this.sections().find((group) =>
        group.entries.some((entry) => path === entry.route || path.startsWith(`${entry.route}/`)),
      )?.section ?? null
    );
  });
  /** Each section folds from its heading, like the main menu's (docs/SPEC.md § 7, 2026-09-26 12:05, row 152). */
  protected readonly folds = sectionFolds('settings', this.currentSection);

  /** The rail's search icon unfolds the list with the filter ready to type in. */
  protected filterFromRail(): void {
    this.theme.toggleSettingsList();
    // The field is drawn by the next render, once the list has unfolded.
    afterNextRender(() => this.filterField()?.nativeElement.focus(), { injector: this.injector });
  }
}

/** Lower case without accents, so "societe" finds "Société". */
function normalized(text: string): string {
  return text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLocaleLowerCase();
}

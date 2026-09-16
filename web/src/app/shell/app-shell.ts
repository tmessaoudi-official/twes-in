// SPDX-License-Identifier: AGPL-3.0-or-later

import { BreakpointObserver } from '@angular/cdk/layout';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  isDevMode,
  signal,
  untracked,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatDialog } from '@angular/material/dialog';
import { MatListModule } from '@angular/material/list';
import { MatMenuModule } from '@angular/material/menu';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { map } from 'rxjs';
import { SETTINGS_INDEX } from './settings-area';
import { AuthFacade } from '../auth/auth-facade';
import { CompanySwitcher } from '../company/company-switcher';
import { NotificationBell } from '../notifications/notification-bell';
import { Label } from '../shared/a11y/label';
import { ActivityBar } from '../shared/feedback/activity-bar';
import { RequestActivity } from '../shared/feedback/request-activity';
import {
  LANGUAGE_NAMES,
  LanguageFacade,
  SUPPORTED_LANGUAGES,
} from '../shared/i18n/language-facade';
import { LanguageMenu } from '../shared/i18n/language-menu';
import { SchemeMenu } from '../shared/theme/scheme-menu';
import { type SchemePreference, ThemeFacade } from '../shared/theme/theme-facade';
import { CommandPalette, type CommandPaletteData } from './command-palette';
import { type Command, MODULE_COMMANDS, navCommands } from './commands';
import {
  CORE_NAV,
  DEV_NAV,
  type Gated,
  MODULE_NAV,
  navSections,
  SETTINGS_NAV,
  SIDEBAR_SECTIONS,
  visibleEntries,
} from './nav-manifest';

/**
 * The window classes of the approved design (docs/SPEC.md § 7, 2026-09-16), as Material 3 draws them: a phone gets a
 * bar of destinations at the bottom and the rest in a drawer, a medium window a rail of icons, and only from 1200 px
 * is there room for labels, which the person may still fold into the rail.
 */
export type WindowClass = 'compact' | 'medium' | 'expanded';
const COMPACT = '(max-width: 599.98px)';
const EXPANDED = '(min-width: 1200px)';
/** How many destinations the phone's bottom bar holds before the Plus button. */
const BOTTOM_BAR_DESTINATIONS = 4;

/** "Amel Ben Salah" → "AS": the first and last word, which is how people recognise their own initials. */
export function initialsOf(displayName: string): string {
  const words = displayName.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) {
    return '?';
  }
  const first = words[0][0] ?? '';
  const last = words.length > 1 ? (words[words.length - 1][0] ?? '') : '';
  return (first + last).toUpperCase();
}

/**
 * Every signed-in page sits inside this: the navigation the user may see, where they work, the language and colour
 * scheme, and their account. Pages bring content only.
 */
@Component({
  selector: 'app-shell',
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    MatSidenavModule,
    MatToolbarModule,
    MatListModule,
    MatIconModule,
    MatButtonModule,
    MatMenuModule,
    MatDividerModule,
    MatTooltipModule,
    TranslatePipe,
    CompanySwitcher,
    NotificationBell,
    Label,
    LanguageMenu,
    SchemeMenu,
    ActivityBar,
  ],
  templateUrl: './app-shell.html',
  host: { '(document:keydown)': 'onKeydown($event)' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppShell {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly dialog = inject(MatDialog);
  private paletteOpen = false;
  private readonly activity = inject(RequestActivity);
  protected readonly theme = inject(ThemeFacade);
  protected readonly language = inject(LanguageFacade);

  protected readonly languages = SUPPORTED_LANGUAGES;
  protected readonly languageNames = LANGUAGE_NAMES;
  protected readonly schemes: readonly SchemePreference[] = ['auto', 'light', 'dark'];
  protected readonly me = this.auth.me;
  protected readonly signingOut = signal(false);
  protected readonly windowClass = toSignal(
    inject(BreakpointObserver)
      .observe([COMPACT, EXPANDED])
      .pipe(
        map((state): WindowClass =>
          state.breakpoints[COMPACT]
            ? 'compact'
            : state.breakpoints[EXPANDED]
              ? 'expanded'
              : 'medium',
        ),
      ),
    { initialValue: 'expanded' as WindowClass },
  );
  protected readonly handset = computed(() => this.windowClass() === 'compact');
  protected readonly sections = computed(() =>
    navSections(this.visible([...CORE_NAV, ...MODULE_NAV, ...DEV_NAV]), SIDEBAR_SECTIONS),
  );
  /**
   * The gear opens the first settings page this user may see, and is absent when there is none. On a phone the list and
   * a page do not fit side by side, so it opens the list.
   */
  protected readonly settingsRoute = computed(() => {
    const first = this.visible(SETTINGS_NAV)[0]?.route ?? null;
    return first !== null && this.handset() ? SETTINGS_INDEX : first;
  });
  protected readonly initials = computed(() => initialsOf(this.me()?.user.displayName ?? ''));
  /** What the command palette offers this user: the modules' commands, then a way to every screen they may see. */
  protected readonly commands = computed((): readonly Command[] =>
    this.visible([
      ...MODULE_COMMANDS,
      ...navCommands([...CORE_NAV, ...MODULE_NAV, ...SETTINGS_NAV]),
    ]),
  );
  /** The phone's bottom bar: the first destinations of the sidebar, in its order. */
  protected readonly bottomBar = computed(() =>
    this.sections()
      .flatMap((group) => group.entries)
      .slice(0, BOTTOM_BAR_DESTINATIONS),
  );
  /** A medium window always shows the rail; a wide one shows it when the person chose it; a phone the full drawer. */
  protected readonly rail = computed(
    () =>
      this.windowClass() === 'medium' ||
      (this.windowClass() === 'expanded' && this.theme.sidebar() === 'rail'),
  );

  constructor() {
    // A session that ended while the page was open (expired, or ended from another device) sends the person back to
    // sign in with a word of why, instead of leaving every screen failing one request at a time.
    effect(() => {
      if (!this.activity.sessionExpired()) return;
      untracked(() => {
        this.activity.acknowledgeExpiry();
        this.auth.sessionEnded();
        void this.router.navigateByUrl('/login?expired=1');
      });
    });
  }

  /**
   * `[` collapses or expands the sidebar in a window wide enough for labels, unless someone is typing, a menu or dialog is open, or another shortcut
   * is meant. The key already says a `[` was typed, however the keyboard types it: AltGr on a French PC reports Ctrl
   * and Alt together, Option on a French Mac reports Alt alone. Only Meta, or Ctrl without Alt, is a shortcut.
   */
  protected onKeydown(event: KeyboardEvent): void {
    // Ctrl K (⌘ K on a Mac) opens the palette from anywhere, a field included, as in most tools that have one. AltGr
    // reports Ctrl and Alt together and types a character, so it is not the shortcut.
    if (
      event.key.toLowerCase() === 'k' &&
      !event.defaultPrevented &&
      (event.metaKey || (event.ctrlKey && !event.altKey))
    ) {
      event.preventDefault();
      this.openCommands();
      return;
    }
    if (event.key !== '[' || event.defaultPrevented || event.metaKey) return;
    if ((event.ctrlKey && !event.altKey) || this.windowClass() !== 'expanded') return;
    const target = event.target;
    if (
      target instanceof Element &&
      target.closest('input, textarea, select, [contenteditable], .cdk-overlay-container')
    ) {
      return;
    }
    event.preventDefault();
    this.theme.toggleSidebar();
  }

  protected openCommands(): void {
    if (this.paletteOpen) return;
    this.paletteOpen = true;
    this.dialog
      .open<CommandPalette, CommandPaletteData>(CommandPalette, {
        data: { commands: this.commands() },
        width: 'min(40rem, calc(100vw - 2rem))',
        position: { top: '12vh' },
        panelClass: 'twes-palette-panel',
        autoFocus: 'first-tabbable',
      })
      .afterClosed()
      .subscribe(() => (this.paletteOpen = false));
  }

  private visible<T extends Gated>(entries: readonly T[]): readonly T[] {
    return visibleEntries(
      entries,
      (permission) => this.auth.hasPermission(permission),
      isDevMode(),
      (module) => this.auth.hasModule(module),
    );
  }

  protected async logout(): Promise<void> {
    this.signingOut.set(true);
    try {
      await this.auth.logout();
    } finally {
      this.signingOut.set(false);
      await this.router.navigateByUrl('/login');
    }
  }

  protected async useLanguage(language: string): Promise<void> {
    await this.language.use(language);
  }
}

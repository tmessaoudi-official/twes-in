// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
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
import { BrandMark } from '../shared/theme/brand-mark';
import { MatDialog } from '@angular/material/dialog';
import { MatListModule } from '@angular/material/list';
import { MatMenuModule } from '@angular/material/menu';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { filter, map } from 'rxjs';
import { SETTINGS_INDEX } from './settings-area';
import { AuthFacade } from '../auth/auth-facade';
import { SubscriptionNoticeBar } from '../licensing/subscription-notice';
import { CompanySwitcher } from '../company/company-switcher';
import { NotificationBell } from '../notifications/notification-bell';
import { Label } from '../shared/a11y/label';
import { runAction } from '../shared/actions/run-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { isBareKeystroke, isTypingTarget, matchesShortcut } from '../shared/actions/shortcuts';
import { ShortcutsSheet } from '../shared/actions/shortcuts-sheet';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { ActivityBar } from '../shared/feedback/activity-bar';
import { LiveChanges } from '../shared/realtime/live-changes';
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
import { type Command, MODULE_COMMANDS, navCommands, screenCommands } from './commands';
import {
  CORE_NAV,
  DEV_NAV,
  type Gated,
  isSettingsUrl,
  MODULE_NAV,
  navSections,
  SETTINGS_NAV,
  SIDEBAR_SECTIONS,
  visibleEntries,
} from './nav-manifest';
import { WINDOW_CLASS } from '../shared/ui/window-class';

/**
 * The window classes of the approved design (docs/SPEC.md § 7, 2026-09-16), as Material 3 draws them: a phone gets a
 * bar of destinations at the bottom and the rest in a drawer, a medium window a rail of icons, and only from 1200 px
 * is there room for labels, which the person may still fold into the rail.
 */
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
    BrandMark,
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
    SubscriptionNoticeBar,
  ],
  templateUrl: './app-shell.html',
  host: { '(document:keydown)': 'onKeydown($event)' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppShell {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly dialog = inject(MatDialog);
  private readonly screen = inject(ScreenActions);
  private paletteOpen = false;
  private shortcutsOpen = false;
  private readonly activity = inject(RequestActivity);
  protected readonly theme = inject(ThemeFacade);
  protected readonly language = inject(LanguageFacade);

  protected readonly languages = SUPPORTED_LANGUAGES;
  protected readonly languageNames = LANGUAGE_NAMES;
  protected readonly schemes: readonly SchemePreference[] = ['auto', 'light', 'dark'];
  protected readonly me = this.auth.me;
  protected readonly signingOut = signal(false);
  protected readonly windowClass = inject(WINDOW_CLASS);
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
  /** Which company the open screen was built for; the outlet is keyed on it. */
  protected readonly workingCompany = computed(() => this.me()?.company?.id ?? null);
  /**
   * What the command palette offers this user: what the screen on view can do, then the modules' commands, then a
   * way to every screen they may see. The screen's own actions are not gated here — a screen that declared one has
   * already decided the person may run it, and re-deciding from a permission name it never gave would drop it.
   */
  protected readonly commands = computed((): readonly Command[] => [
    ...screenCommands(this.screen.actions()),
    ...this.visible([
      ...MODULE_COMMANDS,
      ...navCommands([...CORE_NAV, ...MODULE_NAV, ...SETTINGS_NAV]),
    ]),
  ]);
  /** The phone's bottom bar: the first destinations of the sidebar, in its order. */
  protected readonly bottomBar = computed(() =>
    this.sections()
      .flatMap((group) => group.entries)
      .slice(0, BOTTOM_BAR_DESTINATIONS),
  );
  /**
   * Where the person is, so the shell can answer to it. Read from the router rather than from a child, because the
   * shell folds itself before the settings area exists.
   */
  private readonly url = toSignal(
    inject(Router).events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );

  /** Whether the settings area is open, which changes both the menu and the room the page is given. */
  protected readonly inSettings = computed(() => isSettingsUrl(this.url()));

  /**
   * A medium window always shows the rail; a wide one shows it when the person chose it; a phone the full drawer.
   * Settings force it: the area is a rail plus a docked list (design review finding 7), and a full menu beside that
   * list took about 650 px of a 1440 px window and cut the tables beside it. A phone keeps the drawer, which is
   * over the page rather than beside it, so nothing is taken from the page there.
   */
  protected readonly rail = computed(() => {
    if (this.windowClass() === 'medium') return true;
    if (this.windowClass() !== 'expanded') return false;
    return (this.inSettings() ? this.theme.settingsSidebar() : this.theme.sidebar()) === 'rail';
  });

  constructor() {
    // A session that ended while the page was open (expired, or ended from another device) sends the person back to
    // sign in with a word of why, instead of leaving every screen failing one request at a time. A refusal that landed
    // after the last redirect, while no shell was open, belongs to that ended session and must not eject a new one.
    this.activity.acknowledgeExpiry();
    // What ANOTHER person changed about this session (a role, a module switched, the company approved, suspended
    // or its subscription changed) shows at once. Not the company's name: nothing writes it — `reviseProfile`
    // sets `legalName` and the name is constructor-only (sweep, 2026-09-20). A change made in THIS tab never
    // arrives here at all, so each screen that writes something global refreshes the session itself.
    inject(LiveChanges).reloadOn(
      ['membership', 'role', 'module', 'company'],
      () => this.auth.refresh(),
      inject(DestroyRef),
    );
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
    // Everything below is a bare character, so it is a letter wherever a person is writing and while an overlay
    // owns the keyboard. One check, before the keys themselves, rather than one per key.
    if (isTypingTarget(event.target)) return;

    if (matchesShortcut(event, '?')) {
      event.preventDefault();
      this.openShortcuts();
      return;
    }

    if (matchesShortcut(event, '[')) {
      // Only where labels fit: on a rail or a phone drawer there is nothing to fold.
      if (this.windowClass() !== 'expanded') return;
      event.preventDefault();
      this.theme.toggleSidebar(this.inSettings());
      return;
    }

    // What the screen on view declared, run by the same rule its toolbar runs it by (row 45). The registry has
    // already refused a key the browser or this method owns, so anything reaching here belongs to the screen.
    if (!isBareKeystroke(event)) return;
    const action = this.screen.forKey(event.key);
    if (action === undefined) return;
    event.preventDefault();
    runAction(action, (confirm) =>
      this.dialog.open(ConfirmDialog, { data: confirm, autoFocus: 'dialog' }).afterClosed(),
    );
  }

  /** The "?" sheet: what this page's keys are, and the ones that work everywhere. */
  protected openShortcuts(): void {
    if (this.shortcutsOpen) return;
    this.shortcutsOpen = true;
    this.dialog
      .open(ShortcutsSheet, { width: 'min(32rem, calc(100vw - 2rem))', autoFocus: 'dialog' })
      .afterClosed()
      .subscribe(() => (this.shortcutsOpen = false));
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

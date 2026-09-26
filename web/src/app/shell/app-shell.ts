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
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatDialog } from '@angular/material/dialog';
import { MatListModule } from '@angular/material/list';
import { MatMenuModule, MatMenuTrigger } from '@angular/material/menu';
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
import type { ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { isBareKeystroke, isTypingTarget, matchesShortcut } from '../shared/actions/shortcuts';
import { keyName, ShortcutsSheet } from '../shared/actions/shortcuts-sheet';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { ActivityBar } from '../shared/feedback/activity-bar';
import { LiveChanges } from '../shared/realtime/live-changes';
import { RequestActivity } from '../shared/feedback/request-activity';
import {
  LANGUAGE_NAMES,
  LanguageFacade,
  SUPPORTED_LANGUAGES,
} from '../shared/i18n/language-facade';
import { type SchemePreference, ThemeFacade } from '../shared/theme/theme-facade';
import { SettingsFacade } from '../shared/settings/settings-facade';
import { PRESENTATION } from '../shared/settings/settings-registry';
import { ProductScanCard, type ProductScanCardData } from '../products/product-scan-card';
import { PRODUCTS_MODULE } from '../products/products-nav';
import { ProductOnView } from '../products/product-on-view';
import { Camera } from '../shared/scan/camera';
import { CameraScanPanel } from '../shared/scan/camera-scan-panel';
import { CustomerView } from '../shared/customer-view/customer-view';
import { PhonePairing } from '../shared/scan/phone-pairing';
import { PhonePairingDialog } from '../shared/scan/phone-pairing-dialog';
import { ScanBus } from '../shared/scan/scan-bus';
import { ScanCount } from '../shared/scan/scan-count';
import { SCAN_GAP_MS, ScanWedge } from '../shared/scan/scan-wedge';
import { CommandPalette, type CommandPaletteData } from './command-palette';
import { type Command, MODULE_COMMANDS, navCommands, screenCommands } from './commands';
import {
  CORE_NAV,
  MANAGE_NAV,
  DEV_NAV,
  type Gated,
  isSettingsUrl,
  MODULE_NAV,
  navSections,
  PHONE_BAR_FIRST,
  SETTINGS_NAV,
  SIDEBAR_SECTIONS,
  visibleEntries,
  COMING_NAV,
  withComing,
} from './nav-manifest';
import { WINDOW_CLASS } from '../shared/ui/window-class';

/**
 * The window classes of the approved design (docs/SPEC.md § 7, 2026-09-16), as Material 3 draws them: a phone gets a
 * bar of destinations at the bottom and the rest in a drawer, a medium window a rail of icons, and only from 1200 px
 * is there room for labels, which the person may still fold into the rail.
 */
/** How many destinations the phone's bottom bar holds beside « Créer » and Plus. */
const BOTTOM_BAR_DESTINATIONS = 3;

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
    NgTemplateOutlet,
    MatMenuModule,
    MatDividerModule,
    MatTooltipModule,
    TranslatePipe,
    CompanySwitcher,
    NotificationBell,
    Label,
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
  private cameraOpen = false;
  /** Whether this browser can open a camera here: a secure page and the media devices API. */
  protected readonly cameraAvailable = inject(Camera).available();
  /** A phone lent to this tab as a scanner (slice 4): it lives with the shell, so leaving or signing out ends it. */
  protected readonly phone = inject(PhonePairing);
  /**
   * Customer view (docs/SPEC.md § 7, 2026-09-23 slice 5): offered to whoever has a cost to hide, and always to whoever
   * has it on, so it can be turned off.
   */
  protected readonly customerView = inject(CustomerView);
  protected readonly mayHide = computed(
    () => this.auth.hasPermission('product.cost.read') || this.customerView.active(),
  );
  /** Every scan handler needs product.read, so a phone scanning for somebody without it would do nothing. */
  protected readonly mayScan = computed(
    () => this.auth.me() !== null && this.auth.hasPermission('product.read'),
  );
  private phoneOpen = false;
  private scanOpen = false;
  private readonly wedge = new ScanWedge();
  private readonly scans = inject(ScanBus);
  /** The product whose page is on view, which the scan card offers a code nobody holds to first. */
  private readonly productOnView = inject(ProductOnView);
  private readonly count = new ScanCount();
  /** The count typed for the next scan ("5×"), shown until that scan takes it. */
  protected readonly scanCount = this.scans.multiplier;
  /** A screen shortcut waiting out the scan gap before it runs; see `onKeydown`. */
  private heldShortcut: ReturnType<typeof setTimeout> | null = null;
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
    navSections(
      withComing(
        this.visible([...CORE_NAV, ...MODULE_NAV, ...MANAGE_NAV, ...DEV_NAV]),
        this.visible(COMING_NAV),
        this.theme.showComing(),
      ),
      SIDEBAR_SECTIONS,
    ),
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
      ...navCommands([...CORE_NAV, ...MODULE_NAV, ...MANAGE_NAV, ...SETTINGS_NAV]),
    ]),
  ]);
  /** What « Créer » offers this person: the modules' creations they may make, in the palette's order. */
  protected readonly createCommands = computed(() =>
    this.visible(MODULE_COMMANDS).filter((command) => command.group === 'create'),
  );
  /** « Créer »'s menu, wherever it is drawn: the rail's button from a tablet up, the phone's bar below. */
  private readonly createTrigger = viewChild('createTrigger', { read: MatMenuTrigger });
  /**
   * The scanning controls, which the rail does not carry: what keeps a slim bar above the page from a tablet up. The
   * camera shows wherever the browser can open one, whatever the person may do.
   */
  protected readonly scanControls = computed(
    () => this.cameraAvailable || this.mayScan() || this.mayHide() || this.scanCount() !== null,
  );
  /**
   * The phone's bottom bar: the most used destinations first (`PHONE_BAR_FIRST`), then the sidebar's next ones, three
   * in all; « Créer » goes between the second and the third.
   */
  protected readonly bottomBar = computed(() => {
    // What is not built yet never takes one of the phone's few places.
    const entries = this.sections()
      .flatMap((group) => group.entries)
      .filter((entry) => entry.coming === undefined);
    const first = PHONE_BAR_FIRST.flatMap((key) => entries.filter((entry) => entry.key === key));
    return [...first, ...entries.filter((entry) => !first.includes(entry))].slice(
      0,
      BOTTOM_BAR_DESTINATIONS,
    );
  });
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

  /**
   * What N creates here: the creation whose address is this list's own followed by `/new`, so N is a new invoice on
   * the invoices list and nothing on a record or on the home page (docs/SPEC.md § 7, 2026-09-24 22:51).
   */
  private readonly newOnThisList = computed(() => {
    const path = this.url().split(/[?#]/)[0];
    return this.createCommands().find((command) => command.route === `${path}/new`);
  });
  /** The person's single keys (row 125), which the rail's hints and the handler below both read. */
  protected readonly keys = inject(SettingsFacade).value(PRESENTATION.shortcuts);
  protected readonly keyName = keyName;

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
    // A shortcut still held when the shell goes (signing out) belongs to a screen that is gone with it.
    inject(DestroyRef).onDestroy(() => {
      this.dropHeldShortcut();
      this.phone.end();
    });
    // A scan no screen acts on opens the card of what it names.
    this.scans.fallback((scan) => this.openScan(scan.code));
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
    // A scanner typing a code with no field focused opens what the code names (docs/SPEC.md § 7, 2026-09-23 01:10).
    // Read before any screen key, so a letter inside a code never runs the screen's shortcut; in a field, or under
    // an overlay, the scan is the field's and the wedge only forgets what it had.
    const reading = this.wedge.read({
      key: event.key,
      at: event.timeStamp,
      editable: isTypingTarget(event.target),
      // AltGr reports Ctrl and Alt together and types a character ("]" on AZERTY): only a bare Ctrl or Meta is a command.
      modified: !isBareKeystroke(event),
    });
    if (reading.code !== null || reading.claimed) {
      // The key before this one was the first of a scanned code, not a shortcut: what it would have run never runs.
      this.dropHeldShortcut();
      this.count.reset();
      event.preventDefault();
      // The screen on view acts on it when it can (an invoice adds a line); otherwise the card opens.
      if (reading.code !== null) void this.scans.receive(reading.code, 'wedge');
      return;
    }

    // Everything below is a bare character, so it is a letter wherever a person is writing and while an overlay
    // owns the keyboard. One check, before the keys themselves, rather than one per key.
    if (isTypingTarget(event.target)) {
      this.count.reset();
      return;
    }

    // Ctrl Z outside a field takes back the latest scan, as a till's correction key does.
    if (
      event.key.toLowerCase() === 'z' &&
      !event.defaultPrevented &&
      (event.metaKey || (event.ctrlKey && !event.altKey)) &&
      this.scans.undoLast()
    ) {
      event.preventDefault();
      return;
    }

    // "5×" typed before a scan makes it count five (docs/SPEC.md § 7, 2026-09-23 09:30); Escape forgets the count.
    if (isBareKeystroke(event)) {
      const count = this.count.read(event.key, event.timeStamp);
      if (count !== null) {
        event.preventDefault();
        this.scans.multiplier.set(count);
        return;
      }
    }
    if (event.key === 'Escape' && this.scans.multiplier() !== null) {
      this.scans.multiplier.set(null);
      return;
    }

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

    // The shell's own keys (docs/SPEC.md § 7, 2026-09-24 22:51), the person's or the ruled ones: / searches, C opens « Créer », N a new document on
    // its list, E the next step. Each is taken only when it has something to do, so a key with nothing behind it on
    // this screen stays the browser's; and each is held for one scan gap like a screen's key, since a code may
    // begin with any of them.
    const keys = this.keys();
    if (matchesShortcut(event, keys.search)) {
      // Taken at once even so: Firefox opens its quick-find on a bare /.
      event.preventDefault();
      this.hold(() => this.openCommands());
      return;
    }
    if (matchesShortcut(event, keys.create) && this.createCommands().length > 0) {
      event.preventDefault();
      this.hold(() => this.createTrigger()?.openMenu());
      return;
    }
    const creation = this.newOnThisList();
    if (matchesShortcut(event, keys.new) && creation !== undefined) {
      event.preventDefault();
      this.hold(() => void this.router.navigateByUrl(creation.route));
      return;
    }
    const next = this.screen.next();
    if (matchesShortcut(event, keys.next) && next !== undefined) {
      event.preventDefault();
      this.hold(() => this.run(next));
      return;
    }

    // What the screen on view declared, run by the same rule its toolbar runs it by (row 45). The registry has
    // already refused a key the browser or this method owns, so anything reaching here belongs to the screen.
    if (!isBareKeystroke(event)) return;
    const action = this.screen.forKey(event.key);
    if (action === undefined) return;
    event.preventDefault();
    this.hold(() => this.run(action));
  }

  /**
   * Runs a bare key's work after one scan gap: a supplier's or an internal code is free text, and its first letter
   * cannot be told from a hand's until the second arrives — `e` would issue the invoice on view (docs/SPEC.md § 7,
   * 2026-09-23 02:05). Thirty milliseconds is below anything a person notices.
   */
  private hold(work: () => void): void {
    this.dropHeldShortcut();
    this.heldShortcut = setTimeout(() => {
      this.heldShortcut = null;
      work();
    }, SCAN_GAP_MS);
  }

  /** An action by the rule its button follows: asked first when it asks, nothing when refused for now. */
  private run(action: ScreenAction): void {
    runAction(action, (confirm) =>
      this.dialog.open(ConfirmDialog, { data: confirm, autoFocus: 'dialog' }).afterClosed(),
    );
  }

  private dropHeldShortcut(): void {
    if (this.heldShortcut === null) return;
    clearTimeout(this.heldShortcut);
    this.heldShortcut = null;
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

  protected clearScanCount(): void {
    this.scans.multiplier.set(null);
  }

  /** The card of what a scan names, for somebody who may read the products; one at a time. */
  private openScan(code: string): void {
    if (
      this.scanOpen ||
      !this.auth.hasModule(PRODUCTS_MODULE) ||
      !this.auth.hasPermission('product.read')
    ) {
      return;
    }
    this.scanOpen = true;
    this.dialog
      .open<ProductScanCard, ProductScanCardData>(ProductScanCard, {
        data: { code, onView: this.productOnView.product() },
        width: 'min(36rem, calc(100vw - 2rem))',
        position: { top: '12vh' },
        // The card itself, where its keys are read: the dialog's own container sits above it.
        autoFocus: '[data-testid="product-scan-card"]',
      })
      .afterClosed()
      .subscribe(() => (this.scanOpen = false));
  }

  /**
   * The camera as a scanner, in a panel that leaves the page usable beside it: no backdrop, bottom corner
   * (docs/SPEC.md § 7, 2026-09-23 09:45, slice 3). One at a time.
   */
  protected openCamera(): void {
    if (this.cameraOpen) return;
    this.cameraOpen = true;
    this.dialog
      .open(CameraScanPanel, {
        hasBackdrop: false,
        position: { bottom: '1rem', right: '1rem' },
        width: 'min(22rem, calc(100vw - 2rem))',
        autoFocus: '[data-testid="camera-close"]',
        panelClass: 'camera-scan',
      })
      .afterClosed()
      .subscribe(() => (this.cameraOpen = false));
  }

  /** The link a phone claims to scan for this tab (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4). One at a time. */
  protected openPhone(): void {
    if (this.phoneOpen) return;
    this.phoneOpen = true;
    this.dialog
      .open(PhonePairingDialog, { width: 'min(24rem, calc(100vw - 2rem))' })
      .afterClosed()
      .subscribe(() => (this.phoneOpen = false));
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

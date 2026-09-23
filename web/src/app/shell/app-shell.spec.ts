// SPDX-License-Identifier: AGPL-3.0-or-later

import { BreakpointObserver } from '@angular/cdk/layout';
import { Component, computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { BehaviorSubject, map, of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import type { SignedInState } from '../auth/auth-types';
import { CompanyFacade } from '../company/company-facade';
import { NotificationsFacade } from '../notifications/notifications-facade';
import { Feedback } from '../shared/feedback/feedback';
import { RequestActivity } from '../shared/feedback/request-activity';
import { RecordedFeedback } from '../shared/testing/feedback';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { AppShell } from './app-shell';
import type { ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { ShortcutsSheet } from '../shared/actions/shortcuts-sheet';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { ProductScanCard } from '../products/product-scan-card';
import { Camera } from '../shared/scan/camera';
import { PhonePairing } from '../shared/scan/phone-pairing';
import { PhonePairingDialog } from '../shared/scan/phone-pairing-dialog';
import { ScanBus } from '../shared/scan/scan-bus';
import { SCAN_GAP_MS } from '../shared/scan/scan-wedge';
import { CommandPalette } from './command-palette';
import type { Command } from './commands';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      app: { name: 'twes-in' },
      auth: { logout: 'Se déconnecter' },
      nav: {
        home: 'Accueil',
        members: 'Membres',
        customers: 'Clients',
        products: 'Produits',
        vendors: 'Fournisseurs',
        expenses: 'Dépenses',
        design: 'Design',
        sections: { main: 'Général', team: 'Équipe' },
      },
      shell: {
        menu: 'Menu',
        user_menu: 'Compte',
        language: 'Langue',
        scheme: 'Thème',
        more: 'Plus',
        collapse_menu: 'Réduire le menu',
        expand_menu: 'Déployer le menu',
        settings: 'Paramètres',
        commands: { open: 'Rechercher', find: 'Rechercher un client, une facture, un produit…' },
      },
      appearance: {
        scheme_menu: 'Thème : {{current}}',
        language_menu: 'Langue : {{current}}',
        schemes: { auto: 'Automatique', light: 'Clair', dark: 'Sombre' },
      },
    });
  }
}

const owner: SignedInState = {
  user: {
    id: '1',
    email: 'amel@example.test',
    displayName: 'Amel Ben Salah',
    locale: 'fr',
    isPlatformOperator: false,
  },
  company: {
    id: 'c1',
    name: 'Demo',
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
    status: 'active',
    role: 'owner',
    access: 'full' as const,
    subscription: null,
  },
  permissions: ['*'],
  modules: ['customers'],
  mfa: { enrolled: false, required: false, totp: false, passkeys: 0 },
};

/** A viewport of a given width: answers `(max-width: …)` and `(min-width: …)` queries as a browser would. */
function viewport(width: BehaviorSubject<number>) {
  const matches = (query: string, px: number) => {
    const [, kind, value] = /\((max|min)-width:\s*([\d.]+)px\)/.exec(query) ?? [];
    return kind === 'max' ? px <= Number(value) : px >= Number(value);
  };
  return {
    observe: (queries: string | string[]) =>
      width.pipe(
        map((px) => {
          const list = Array.isArray(queries) ? queries : [queries];
          const breakpoints = Object.fromEntries(list.map((q) => [q, matches(q, px)]));
          return { matches: Object.values(breakpoints).some(Boolean), breakpoints };
        }),
      ),
    isMatched: (query: string) => matches(query, width.value),
  };
}

@Component({ template: '' })
class BlankPage {}

/** Counts how many times it was built, which is how a rebuilt screen is told from a kept one. */
@Component({ template: '' })
class CountingPage {
  static built = 0;
  constructor() {
    CountingPage.built += 1;
  }
}

/** Lets every queued scan be worked through. */
const settled = () => new Promise((resolve) => setTimeout(resolve));

/** Longer than the gap between two keys of a scan, which a shortcut waits out before it runs. */
function pause(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, SCAN_GAP_MS + 10));
}

/**
 * Declares actions the way a screen does — a signal, from an injection context — so the shell reads them through
 * the real registry rather than a stand-in that could agree with a broken one.
 */
function declareScreenActions(actions: readonly ScreenAction[]): void {
  const source = signal(actions);
  TestBed.runInInjectionContext(() => TestBed.inject(ScreenActions).declare(source));
}

describe('AppShell', () => {
  const pairing = {
    state: signal<{ id: string; url: string; phone: 'waiting' | 'connected' } | null>(null),
    open: vi.fn(async () => undefined),
    end: vi.fn(),
  };
  const width = new BehaviorSubject(1280);
  const me = signal<SignedInState | null>(owner);
  const permissions = signal<readonly string[]>(['user.read']);
  const modules = signal<readonly string[]>(['customers']);
  const auth = {
    me: me.asReadonly(),
    subscription: computed(() => me()?.company?.subscription ?? null),
    access: computed(() => me()?.company?.access ?? 'full'),
    logout: vi.fn(async () => undefined),
    sessionEnded: vi.fn(),
    hasPermission: (permission: string) => permissions().includes(permission),
    hasModule: (module: string) => modules().includes(module),
  };
  const companies = {
    companies: signal([]).asReadonly(),
    current: computed(() => me()?.company ?? null),
    canSwitch: signal(false).asReadonly(),
    switching: signal(false).asReadonly(),
    load: vi.fn(async () => []),
    switchTo: vi.fn(),
  };
  const theme = {
    scheme: signal<'light' | 'dark'>('light'),
    preference: signal<'auto' | 'light' | 'dark'>('auto'),
    setScheme: vi.fn(),
    density: signal<'comfortable' | 'compact'>('comfortable'),
    toggleDensity: vi.fn(),
    sidebar: signal<'expanded' | 'rail'>('expanded'),
    settingsSidebar: signal<'expanded' | 'rail'>('rail'),
    toggleSidebar: vi.fn(),
  };
  const language = { current: signal('fr'), use: vi.fn(async () => undefined) };
  const sessionExpired = signal(false);
  const activity = {
    busy: signal(false),
    slow: signal(false),
    unavailable: signal(false),
    retryIn: signal(null),
    offline: signal(false),
    sessionExpired,
    retryNow: vi.fn(),
    acknowledgeExpiry: vi.fn(() => sessionExpired.set(false)),
  };

  beforeEach(async () => {
    me.set(owner);
    permissions.set(['user.read']);
    modules.set(['customers']);
    theme.sidebar.set('expanded');
    theme.settingsSidebar.set('rail');
    sessionExpired.set(false);
    width.next(1280);
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [AppShell],
      providers: [
        // Two addresses to move between: one inside the settings area and one outside it.
        provideRouter([
          { path: 'members', component: BlankPage },
          { path: 'invoices', component: BlankPage },
          { path: 'customers', component: CountingPage },
        ]),
        { provide: BreakpointObserver, useValue: viewport(width) },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: CompanyFacade, useValue: companies },
        { provide: ThemeFacade, useValue: theme },
        { provide: LanguageFacade, useValue: language },
        { provide: RequestActivity, useValue: activity },
        { provide: Feedback, useClass: RecordedFeedback },
        { provide: Camera, useValue: { available: () => true } },
        { provide: PhonePairing, useValue: pairing },
        {
          provide: NotificationsFacade,
          useValue: {
            items: signal([]).asReadonly(),
            unread: signal(0).asReadonly(),
            error: signal(false).asReadonly(),
            connect: vi.fn(),
            disconnect: vi.fn(),
            refresh: vi.fn(async () => undefined),
            markRead: vi.fn(),
            markAllRead: vi.fn(),
          },
        },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(AppShell);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const byTestId = (id: string) =>
      document.querySelector<HTMLElement>(`[data-testid="${id}"]`) ?? null;
    const click = async (id: string) => {
      const target = byTestId(id);
      if (!target) {
        throw new Error(`no element with data-testid="${id}"`);
      }
      target.click();
      await fixture.whenStable();
    };
    return { fixture, el, byTestId, click };
  }

  it('carries the subscription notice above the page when a period is ending', async () => {
    me.set({
      ...owner,
      company: {
        ...owner.company!,
        subscription: {
          stage: 'grace' as const,
          coveredUntil: '2026-09-15T23:59:59+01:00',
          graceEndsAt: '2026-09-22T23:59:59+01:00',
          daysLeft: 5,
        },
      },
    });

    const { byTestId } = await render();

    expect(byTestId('subscription-notice')).not.toBeNull();
    me.set(owner);
  });

  it('shows the navigation the user may see, with the product name', async () => {
    const { el, byTestId } = await render();
    expect(el.querySelector('[data-testid="brand"]')?.textContent).toContain('twes-in');
    // The mark beside the name is ours, drawn from the theme, and not a stock Material glyph (invariant 5):
    // nothing in the product may be another product's badge.
    const mark = el.querySelector('[data-testid="brand-mark"] svg');
    expect(mark).not.toBeNull();
    expect(mark?.getAttribute('viewBox')).toBe('0 0 32 32');
    expect(mark?.querySelector('rect')?.getAttribute('class')).toContain('fill-primary');
    // It sits beside the name in text, so announcing it again would say the product's name twice.
    expect(mark?.getAttribute('aria-hidden')).toBe('true');
    expect(el.querySelector('[data-testid="brand"]')?.parentElement?.textContent).not.toContain(
      'receipt_long',
    );
    expect(byTestId('nav-home')?.textContent).toContain('Accueil');
    // Each settings page lives in the settings area, not in the sidebar, which has one way in to all of them.
    expect(byTestId('nav-members')).toBeNull();
  });

  it('reaches the settings from the sidebar and from nowhere else', async () => {
    // Design review finding 8: a gear in the top bar and an entry in the sidebar opened the same area. The rail is
    // what shows you are in settings (finding 7), so the entry belongs there and the gear goes.
    const { byTestId } = await render();
    const entry = byTestId('nav-settings');
    expect(entry?.closest('[data-testid="shell-nav"]')).not.toBeNull();
    expect(entry?.getAttribute('href')).toBe('/members');
    expect(entry?.textContent).toContain('Paramètres');
    expect(byTestId('settings-gear')).toBeNull();
  });

  it('puts the language and the scheme in the top bar, out of the account menu', async () => {
    const { click, byTestId } = await render();
    expect(byTestId('language-menu')?.closest('header')).not.toBeNull();
    expect(byTestId('language-menu')?.getAttribute('aria-label')).toBe('Langue : Français');
    expect(byTestId('scheme-menu')?.closest('header')).not.toBeNull();
    expect(byTestId('scheme-menu')?.getAttribute('aria-label')).toBe('Thème : Automatique');

    await click('user-menu');
    expect(document.querySelectorAll('.mat-mdc-menu-panel [role="menuitemradio"]')).toHaveLength(0);
    expect(byTestId('account-settings')).toBeNull();

    await click('scheme-menu');
    await click('scheme-dark');
    expect(theme.setScheme).toHaveBeenCalledWith('dark');
  });

  it('folds the language and the scheme into the account menu on a phone', async () => {
    width.next(390);
    const { click, byTestId } = await render();
    expect(byTestId('language-menu')).toBeNull();
    expect(byTestId('scheme-menu')).toBeNull();

    await click('user-menu');
    expect(byTestId('account-scheme-auto')?.getAttribute('aria-checked')).toBe('true');
    await click('account-language-en');
    expect(language.use).toHaveBeenCalledWith('en');

    await click('user-menu');
    await click('account-scheme-dark');
    expect(theme.setScheme).toHaveBeenCalledWith('dark');
  });

  it('starts the search after the menu button and lets it fill the bar, capped', async () => {
    // Design review finding 6, measured: 496 px centred in a 1184 px header at 1440, empty on both sides. It now
    // starts where the reading does and grows to meet the right-hand controls; the cap only binds around 1920 px.
    const { fixture, byTestId } = await render();
    const search = byTestId('top-bar-search');
    expect(byTestId('command-open')?.closest('[data-testid="top-bar-search"]')).not.toBeNull();
    expect(
      search?.previousElementSibling?.querySelector('[data-testid="sidebar-toggle"]'),
    ).not.toBeNull();
    expect(search?.className).toContain('flex-1');
    expect(search?.className).toMatch(/max-w-\[800px\]/);
    // The placeholder says what it finds rather than only that it searches.
    expect(byTestId('command-open')?.textContent).toContain('Rechercher un client');

    width.next(900);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('top-bar-search')).toBeNull();
    expect(byTestId('command-open')?.textContent).not.toContain('Rechercher un client');
    expect(byTestId('command-open')?.getAttribute('aria-label')).toBe('Rechercher');
  });

  it('gives every control in the top bar that shows no words a tooltip saying what it does', async () => {
    const { byTestId } = await render();
    for (const id of ['sidebar-toggle', 'scheme-menu', 'language-menu']) {
      expect(byTestId(id)?.classList.contains('mat-mdc-tooltip-trigger'), id).toBe(true);
    }
  });

  it('rebuilds the open screen when the company changes, so it never shows the last one’s rows', async () => {
    // The session refreshes on a switch, but a page that loaded its data in its constructor kept showing the
    // previous company's until the browser was refreshed (developer, 2026-09-20).
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/customers');
    const { fixture } = await render();
    CountingPage.built = 0;
    fixture.detectChanges();
    await fixture.whenStable();
    const before = CountingPage.built;

    me.set({ ...owner, company: { ...owner.company!, id: 'c2', name: 'Autre' } });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(CountingPage.built).toBe(before + 1);
  });

  it('keeps the screen it has when the session refreshes without changing company', async () => {
    // Rebuilding on every refresh would throw away a half-filled form each time the shell re-reads the session.
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/customers');
    const { fixture } = await render();
    CountingPage.built = 0;
    fixture.detectChanges();
    await fixture.whenStable();
    const before = CountingPage.built;

    me.set({ ...owner, user: { ...owner.user, displayName: 'Amel B. Salah' } });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(CountingPage.built).toBe(before);
  });

  it('opens the settings on the first settings page the user may see', async () => {
    const { fixture, byTestId } = await render();
    expect(byTestId('nav-settings')?.getAttribute('href')).toBe('/members');

    permissions.set(['user.read', 'company.settings']);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('nav-settings')?.getAttribute('href')).toBe('/company/profile');
  });

  it('folds the menu to its rail while in settings, and gives the area the whole width', async () => {
    // Design review finding 7, measured: the settings menu floated as a card beside a FULL app menu, about 650 px
    // of a 1440 px window, and cut the tables beside it. In settings the app menu is the rail, so the settings list
    // docks against it; main drops its own gutter and cap so the list can sit flush rather than inset.
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/members');
    const { el, byTestId, fixture } = await render();

    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('rail');
    const main = el.querySelector('main');
    expect(main?.className).not.toContain('max-w-6xl');
    expect(main?.getAttribute('data-settings')).toBe('true');

    // Off a settings address the menu is whatever the person chose, and main is inset again.
    await router.navigateByUrl('/invoices');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('expanded');
    expect(el.querySelector('main')?.className).toContain('max-w-6xl');
    expect(el.querySelector('main')?.getAttribute('data-settings')).toBe('false');
  });

  it('lets the menu be unfolded inside settings too, where the toggle used to do nothing', async () => {
    // It was drawn there and inert: the rail was forced for the whole area, so the button and the [ key both
    // wrote a preference nothing then read (developer, 2026-09-20).
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/members');
    const { byTestId, click, fixture } = await render();
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('rail');
    expect(byTestId('sidebar-toggle')).not.toBeNull();

    await click('sidebar-toggle');
    expect(theme.toggleSidebar).toHaveBeenCalledWith(true);

    theme.settingsSidebar.set('expanded');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('expanded');
  });

  it('keeps each area’s answer apart, so folding one menu does not fold the other', async () => {
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/members');
    const { byTestId, fixture } = await render();

    // The settings menu unfolded; the general one is untouched and still whatever it was.
    theme.settingsSidebar.set('expanded');
    theme.sidebar.set('rail');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('expanded');

    await router.navigateByUrl('/invoices');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('rail');
  });

  it('writes the [ key to the menu the person is looking at', async () => {
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/members');
    const { fixture } = await render();

    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '[', bubbles: true }));
    expect(theme.toggleSidebar).toHaveBeenLastCalledWith(true);

    await router.navigateByUrl('/invoices');
    fixture.detectChanges();
    await fixture.whenStable();
    // A hand needs far longer than a scanner between two keys; two presses within SCAN_GAP_MS read as a scan.
    await new Promise((resolve) => setTimeout(resolve, SCAN_GAP_MS + 10));
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '[', bubbles: true }));
    expect(theme.toggleSidebar).toHaveBeenLastCalledWith(false);
  });

  it('shows the working company where the wordmark is, on a phone', async () => {
    // Design review finding 5, measured: at 390 px the account button was cut off on every screen. The company a
    // person acts for is what that space is worth; the wordmark stays on wider windows and the signed-out pages.
    width.next(390);
    const { byTestId, el } = await render();

    const company = byTestId('company-name');
    expect(company?.closest('header')).not.toBeNull();
    const leading = el.querySelector('[data-testid="top-bar-leading"]');
    expect(leading?.contains(company as Node)).toBe(true);
    // Truncated rather than pushing the controls off the bar, which is the defect this replaced.
    expect(company?.className).toContain('truncate');
    // The wordmark is the sidebar's on a phone, not the header's.
    expect(el.querySelector('header [data-testid="brand"]')).toBeNull();
  });

  it('keeps the wordmark in the menu above a phone, where there is room for both', async () => {
    // The company still names the bar at every width; what the phone gives up is the wordmark, which stays in the
    // menu on wider windows rather than being shown twice.
    width.next(900);
    const { el } = await render();
    expect(el.querySelector('header [data-testid="company-name"]')).not.toBeNull();
    expect(el.querySelector('[data-testid="shell-nav"] [data-testid="brand-mark"]')).not.toBeNull();
  });

  it('keeps the settings under Plus on a phone, not in the account menu as well', async () => {
    // On a phone the drawer IS "Plus", so the sidebar entry is already the one way in; a second entry in the
    // account menu is the pair of controls finding 8 removed, one window class down.
    width.next(390);
    const { click, byTestId } = await render();
    expect(byTestId('settings-gear')).toBeNull();
    // The list rather than a page: at this width the two do not fit side by side.
    expect(byTestId('nav-settings')?.getAttribute('href')).toBe('/company');
    await click('user-menu');
    expect(byTestId('account-settings')).toBeNull();
  });

  it('keeps every part of the shell inside a landmark, each named once', async () => {
    const { el } = await render();

    expect(el.querySelector('[data-testid="brand"]')?.closest('nav')).not.toBeNull();
    expect(el.querySelector('[data-testid="user-menu"]')?.closest('header')).not.toBeNull();
    const lists = [...el.querySelectorAll<HTMLElement>('mat-nav-list')];
    expect(lists.length).toBeGreaterThan(0);
    const names = lists.map(
      (list) =>
        list.getAttribute('aria-label') ??
        el.querySelector(`#${list.getAttribute('aria-labelledby')}`)?.textContent?.trim(),
    );
    expect(names).toEqual(['Général', 'Paramètres']);
  });

  it('hides an entry whose permission the user lacks', async () => {
    permissions.set([]);
    const { byTestId } = await render();
    expect(byTestId('nav-home')).not.toBeNull();
    expect(byTestId('settings-gear')).toBeNull();
  });

  it("shows a module's entries only while the company has the module on", async () => {
    permissions.set(['customer.read']);
    const { fixture, byTestId } = await render();
    expect(byTestId('nav-customers')?.textContent).toContain('Clients');

    modules.set([]);
    fixture.detectChanges();
    await fixture.whenStable();

    expect(byTestId('nav-customers')).toBeNull();
    expect(byTestId('nav-home')).not.toBeNull();
  });

  it('collapses the sidebar to a rail from the top bar, every entry still named', async () => {
    const { fixture, byTestId, click } = await render();
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('expanded');
    expect(byTestId('sidebar-toggle')?.getAttribute('aria-label')).toBe('Réduire le menu');
    expect(byTestId('nav-home')?.classList.contains('mat-mdc-tooltip-disabled')).toBe(true);

    await click('sidebar-toggle');
    expect(theme.toggleSidebar).toHaveBeenCalledTimes(1);

    theme.sidebar.set('rail');
    fixture.detectChanges();
    await fixture.whenStable();

    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('rail');
    expect(byTestId('sidebar-toggle')?.getAttribute('aria-label')).toBe('Déployer le menu');
    expect(byTestId('nav-home')?.querySelector('.sr-only')?.textContent).toContain('Accueil');
    expect(byTestId('nav-home')?.classList.contains('mat-mdc-tooltip-disabled')).toBe(false);
  });

  it('toggles the sidebar with the [ key, but not while typing or with a shortcut', async () => {
    const { el } = await render();
    const press = (target: EventTarget, init: KeyboardEventInit = {}) =>
      target.dispatchEvent(new KeyboardEvent('keydown', { key: '[', bubbles: true, ...init }));

    press(document.body);
    expect(theme.toggleSidebar).toHaveBeenCalledTimes(1);

    const input = document.createElement('input');
    el.appendChild(input);
    press(input);
    press(document.body, { ctrlKey: true });
    press(document.body, { metaKey: true });
    input.remove();
    expect(theme.toggleSidebar).toHaveBeenCalledTimes(1);

    // AltGr types [ on a French PC keyboard and reports Ctrl and Alt together; Option does on a French Mac, Alt alone.
    press(document.body, { ctrlKey: true, altKey: true });
    // A hand needs far longer than a scanner between two keys; two presses within SCAN_GAP_MS read as a scan.
    await new Promise((resolve) => setTimeout(resolve, SCAN_GAP_MS + 10));
    press(document.body, { altKey: true });
    expect(theme.toggleSidebar).toHaveBeenCalledTimes(3);
  });

  it('lays the navigation out by window width: labelled from 1200 px, a rail of named icons below', async () => {
    const { fixture, byTestId } = await render();
    expect(byTestId('shell-nav')?.getAttribute('data-window')).toBe('expanded');
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('expanded');
    expect(byTestId('bottom-bar')).toBeNull();

    width.next(900);
    fixture.detectChanges();
    await fixture.whenStable();

    expect(byTestId('shell-nav')?.getAttribute('data-window')).toBe('medium');
    // The setting says expanded, but a medium window has no room for labels: the rail is not a choice there.
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('rail');
    expect(byTestId('nav-home')?.querySelector('.sr-only')?.textContent).toContain('Accueil');
    expect(byTestId('sidebar-toggle')).toBeNull();
    expect(byTestId('bottom-bar')).toBeNull();
  });

  it("ignores the [ key below 1200 px, where the rail is not the person's choice", async () => {
    width.next(900);
    await render();
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '[', bubbles: true }));
    expect(theme.toggleSidebar).not.toHaveBeenCalled();
  });

  it('puts four destinations and a Plus button in a bar at the bottom of a phone', async () => {
    permissions.set(['customer.read', 'product.read', 'vendor.read', 'expense.read']);
    modules.set(['customers', 'products', 'vendors', 'expenses']);
    width.next(390);
    const { byTestId, click } = await render();

    const bar = byTestId('bottom-bar');
    expect(bar?.tagName).toBe('NAV');
    const destinations = [...(bar?.querySelectorAll<HTMLAnchorElement>('a') ?? [])];
    expect(destinations.map((link) => link.querySelector('span')?.textContent?.trim())).toEqual([
      'Accueil',
      'Clients',
      'Produits',
      'Fournisseurs',
    ]);
    expect(destinations[0].getAttribute('href')).toBe('/');
    expect(byTestId('menu-toggle')?.textContent).toContain('Plus');
    expect(byTestId('menu-toggle')?.closest('[data-testid="bottom-bar"]')).toBe(bar);
    expect(byTestId('shell-nav')?.getAttribute('data-window')).toBe('compact');
    expect(byTestId('sidebar-toggle')).toBeNull();

    // Everything else is one tap away: Plus opens the full drawer.
    await click('menu-toggle');
    expect(document.querySelector('mat-sidenav')?.classList.contains('mat-drawer-opened')).toBe(
      true,
    );
  });

  it('opens the command palette with Ctrl K or ⌘ K, offering only what the user may do', async () => {
    permissions.set(['customer.read', 'customer.write', 'expense.write']);
    await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);
    const press = (init: KeyboardEventInit) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'k', bubbles: true, cancelable: true, ...init }),
      );

    press({ ctrlKey: true });
    expect(open).toHaveBeenCalledTimes(1);
    const [component, config] = open.mock.calls[0] as [unknown, { data: { commands: Command[] } }];
    expect(component).toBe(CommandPalette);
    // Expenses is off for this company: its creation is not offered even with the permission.
    expect(config.data.commands.map((command) => command.key)).toEqual([
      'new-customer',
      'goto-home',
      'goto-customers',
    ]);

    press({ metaKey: true });
    expect(open).toHaveBeenCalledTimes(2);
    // AltGr+K is a character on some layouts, not a shortcut; a bare k is typing.
    press({ ctrlKey: true, altKey: true });
    press({});
    expect(open).toHaveBeenCalledTimes(2);
  });

  it('opens the shortcut sheet with ?, listing what this page and the shell answer', async () => {
    await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    document.body.dispatchEvent(
      new KeyboardEvent('keydown', { key: '?', bubbles: true, cancelable: true }),
    );

    expect(open).toHaveBeenCalledTimes(1);
    expect(open.mock.calls[0][0]).toBe(ShortcutsSheet);
  });

  it('runs what the screen on view declared for a key, and ignores that key while typing', async () => {
    const { el } = await render();
    let ran = 0;
    declareScreenActions([
      { id: 'issue', label: 'invoices.issue', shortcut: 'e', run: () => (ran += 1) },
    ]);
    const press = (target: EventTarget) =>
      target.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'e', bubbles: true, cancelable: true }),
      );

    press(document.body);
    // A shortcut waits out the scan gap: until then the key could be the first of a scanned code.
    await pause();
    expect(ran).toBe(1);

    // The same key inside a field is the letter E, which is the reason this whole check exists.
    const input = document.createElement('input');
    el.appendChild(input);
    press(input);
    input.remove();
    await pause();
    expect(ran).toBe(1);

    // And a key no screen declared is nobody's: it must not be swallowed.
    const other = new KeyboardEvent('keydown', { key: 'z', bubbles: true, cancelable: true });
    document.body.dispatchEvent(other);
    expect(other.defaultPrevented).toBe(false);
  });

  it('runs no shortcut for the first letter of a scanned code', async () => {
    permissions.set(['product.read']);
    modules.set(['products']);
    await render();
    let issued = 0;
    declareScreenActions([
      { id: 'issue', label: 'invoices.issue', shortcut: 'e', run: () => (issued += 1) },
    ]);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    // A supplier's own code is free text: this one starts with the key that issues an invoice.
    [...'E-4711', 'Enter'].forEach((key) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
      ),
    );
    await pause();

    expect(issued).toBe(0);
    expect(open).toHaveBeenCalledTimes(1);
  });

  it('opens what a scan names when a scanner types a code with no field focused', async () => {
    permissions.set(['product.read']);
    modules.set(['products']);
    const { el } = await render();
    let saved = 0;
    declareScreenActions([
      { id: 'save', label: 'products.save', shortcut: 's', run: () => (saved += 1) },
    ]);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);
    // A wedge types the whole code and Enter within a millisecond or two of each other.
    const scan = (target: EventTarget, code: string) =>
      [...code, 'Enter'].forEach((key) =>
        target.dispatchEvent(
          new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
        ),
      );

    scan(document.body, '0s123');

    await vi.waitFor(() => expect(open).toHaveBeenCalledTimes(1));
    const [component, config] = open.mock.calls[0] as [unknown, { data: { code: string } }];
    expect(component).toBe(ProductScanCard);
    expect(config.data.code).toBe('0s123');
    // The s inside the code is part of the scan, not the screen's save.
    expect(saved).toBe(0);

    // Inside a field the scan is the field's.
    const input = document.createElement('input');
    el.appendChild(input);
    scan(input, '3017620422003');
    input.remove();
    await settled();
    expect(open).toHaveBeenCalledTimes(1);
  });

  it('reads a scan whose characters a French keyboard types with AltGr, as a GS1 prefix is', async () => {
    permissions.set(['product.read']);
    modules.set(['products']);
    await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    // AltGr reports Ctrl and Alt together and TYPES the character: "]" is AltGr ) on AZERTY.
    [...']C10113017620422000', 'Enter'].forEach((key) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', {
          key,
          bubbles: true,
          cancelable: true,
          ...(key === ']' ? { ctrlKey: true, altKey: true } : {}),
        }),
      ),
    );

    await vi.waitFor(() => expect(open).toHaveBeenCalledTimes(1));
    expect((open.mock.calls[0] as [unknown, { data: { code: string } }])[1].data.code).toBe(
      ']C10113017620422000',
    );
  });

  it('lends a phone as a scanner to somebody who reads the products, and lets it go when the shell goes', async () => {
    permissions.set(['product.read']);
    modules.set(['products']);
    const { fixture, click } = await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    await click('phone-pair');

    expect(open).toHaveBeenCalledWith(PhonePairingDialog, expect.anything());
    fixture.destroy();
    expect(pairing.end).toHaveBeenCalled();
  });

  it('offers no phone to somebody who may not read the products', async () => {
    permissions.set(['customer.read']);
    const { byTestId } = await render();

    expect(byTestId('phone-pair')).toBeNull();
  });

  it('opens nothing on a scan for somebody who may not read the products', async () => {
    permissions.set(['customer.read']);
    modules.set(['products', 'customers']);
    await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    [...'3017620422003', 'Enter'].forEach((key) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
      ),
    );

    await settled();
    expect(open).not.toHaveBeenCalled();
  });

  it('gives a scan to the screen that acts on it, counted as typed before it, and Ctrl Z takes it back', async () => {
    const { fixture } = await render();
    const seen: number[] = [];
    let undone = 0;
    TestBed.runInInjectionContext(() =>
      TestBed.inject(ScanBus).handle((scan) => {
        seen.push(scan.times);
        return Promise.resolve({ kind: 'done', key: 'scan.added', undo: () => (undone += 1) });
      }),
    );
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open');
    const press = (key: string, init: KeyboardEventInit = {}) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init }),
      );

    // Typed by hand: the keys are far apart, as a person's are.
    press('5');
    await pause();
    press('x');
    fixture.detectChanges();
    expect(TestBed.inject(ScanBus).multiplier()).toBe(5);
    expect(fixture.nativeElement.querySelector('[data-testid="scan-count"]')).not.toBeNull();

    [...'3017620422003', 'Enter'].forEach((key) => press(key));
    await vi.waitFor(() => expect(seen).toEqual([5]));
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="scan-count"]')).toBeNull();
    expect(open).not.toHaveBeenCalled();

    press('z', { ctrlKey: true });
    expect(undone).toBe(1);
  });

  it('asks before a declared key runs something destructive, exactly as its button would', async () => {
    await render();
    let ran = 0;
    declareScreenActions([
      {
        id: 'cancel',
        label: 'invoices.cancel',
        shortcut: 'x',
        destructive: true,
        confirm: { title: 't', message: 'm', confirmLabel: 'c', keepLabel: 'k' },
        run: () => (ran += 1),
      },
    ]);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(false),
    } as never);

    document.body.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'x', bubbles: true, cancelable: true }),
    );
    await pause();

    expect(open.mock.calls[0][0]).toBe(ConfirmDialog);
    expect(ran).toBe(0);
  });

  it('leads the palette with what the screen on view can do, before creating and going', async () => {
    permissions.set(['customer.read', 'customer.write']);
    await render();
    declareScreenActions([
      { id: 'issue', label: 'invoices.issue', run: () => undefined },
      // Refused for now — offering a line that answers nothing when chosen reads as a broken palette.
      { id: 'pay', label: 'invoices.pay', disabled: true, run: () => undefined },
    ]);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    document.body.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'k', bubbles: true, cancelable: true, ctrlKey: true }),
    );

    const [, config] = open.mock.calls[0] as [unknown, { data: { commands: Command[] } }];
    expect(config.data.commands.map((command) => command.key)).toEqual([
      'screen-issue',
      'new-customer',
      'goto-home',
      'goto-customers',
    ]);
  });

  it('opens the command palette from the search button in the top bar', async () => {
    const { click, byTestId } = await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    expect(byTestId('command-open')?.closest('header')).not.toBeNull();
    expect(byTestId('command-open')?.getAttribute('aria-keyshortcuts')).toBe('Control+K Meta+K');
    // Named even on a phone, where its visible label and shortcut are hidden to save room.
    expect(byTestId('command-open')?.getAttribute('aria-label')).toBe('Rechercher');
    await click('command-open');

    expect(open).toHaveBeenCalledTimes(1);
  });

  it('sends the person back to sign in, saying why, when their session ends while the page is open', async () => {
    const { fixture } = await render();
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    sessionExpired.set(true);
    await fixture.whenStable();

    expect(auth.sessionEnded).toHaveBeenCalledOnce();
    expect(navigate).toHaveBeenCalledWith('/login?expired=1');
    expect(sessionExpired()).toBe(false);
  });

  it('forgets a session end that arrived after the last redirect, so a fresh sign-in is not sent straight back', async () => {
    // Several requests refused at once: the first sends the person away, a later one lands once the shell is gone.
    sessionExpired.set(true);
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    await render();

    expect(navigate).not.toHaveBeenCalledWith('/login?expired=1');
    expect(auth.sessionEnded).not.toHaveBeenCalled();
    expect(sessionExpired()).toBe(false);
  });

  it('names the signed-in user on the account menu', async () => {
    const { byTestId } = await render();
    expect(byTestId('user-menu')?.textContent).toContain('AS');
    expect(byTestId('user-menu')?.getAttribute('aria-label')).toContain('Amel Ben Salah');
  });

  it('signs out from the account menu and goes to the login page', async () => {
    const { click } = await render();
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    await click('user-menu');
    await click('logout');

    expect(auth.logout).toHaveBeenCalledTimes(1);
    expect(navigate).toHaveBeenCalledWith('/login');
  });

  it('switches to compact density from the account menu', async () => {
    const { click } = await render();
    await click('user-menu');
    await click('density-toggle');
    expect(theme.toggleDensity).toHaveBeenCalledTimes(1);
  });
});

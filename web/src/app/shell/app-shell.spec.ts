// SPDX-License-Identifier: AGPL-3.0-or-later

import { BreakpointObserver } from '@angular/cdk/layout';
import { computed, signal } from '@angular/core';
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
import { RequestActivity } from '../shared/feedback/request-activity';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { AppShell } from './app-shell';
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
        commands: { open: 'Rechercher' },
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

describe('AppShell', () => {
  const width = new BehaviorSubject(1280);
  const me = signal<SignedInState | null>(owner);
  const permissions = signal<readonly string[]>(['user.read']);
  const modules = signal<readonly string[]>(['customers']);
  const auth = {
    me: me.asReadonly(),
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
    sessionExpired.set(false);
    width.next(1280);
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [AppShell],
      providers: [
        provideRouter([]),
        { provide: BreakpointObserver, useValue: viewport(width) },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: CompanyFacade, useValue: companies },
        { provide: ThemeFacade, useValue: theme },
        { provide: LanguageFacade, useValue: language },
        { provide: RequestActivity, useValue: activity },
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

  it('shows the navigation the user may see, with the product name', async () => {
    const { el, byTestId } = await render();
    expect(el.querySelector('[data-testid="brand"]')?.textContent).toContain('twes-in');
    expect(byTestId('nav-home')?.textContent).toContain('Accueil');
    // Each settings page lives in the settings area, not in the sidebar, which has one way in to all of them.
    expect(byTestId('nav-members')).toBeNull();
  });

  it('reaches the settings from the sidebar as well as from the gear', async () => {
    const { byTestId } = await render();
    const entry = byTestId('nav-settings');
    expect(entry?.closest('[data-testid="shell-nav"]')).not.toBeNull();
    expect(entry?.getAttribute('href')).toBe('/members');
    expect(entry?.textContent).toContain('Paramètres');
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

  it('folds the language, the scheme and the settings into the account menu on a phone', async () => {
    width.next(390);
    const { click, byTestId } = await render();
    expect(byTestId('language-menu')).toBeNull();
    expect(byTestId('scheme-menu')).toBeNull();

    await click('user-menu');
    expect(byTestId('account-scheme-auto')?.getAttribute('aria-checked')).toBe('true');
    expect(byTestId('account-settings')?.getAttribute('href')).toBe('/company');
    await click('account-language-en');
    expect(language.use).toHaveBeenCalledWith('en');

    await click('user-menu');
    await click('account-scheme-dark');
    expect(theme.setScheme).toHaveBeenCalledWith('dark');
  });

  it('centres a wide search from 1200 px, and shows only its icon below', async () => {
    const { fixture, byTestId } = await render();
    expect(byTestId('command-open')?.closest('[data-testid="top-bar-centre"]')).not.toBeNull();
    expect(byTestId('command-open')?.textContent).toContain('Rechercher');

    width.next(900);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('top-bar-centre')).toBeNull();
    expect(byTestId('command-open')?.textContent).not.toContain('Rechercher');
    expect(byTestId('command-open')?.getAttribute('aria-label')).toBe('Rechercher');
  });

  it('gives every control in the top bar that shows no words a tooltip saying what it does', async () => {
    const { byTestId } = await render();
    for (const id of ['sidebar-toggle', 'settings-gear', 'scheme-menu', 'language-menu']) {
      expect(byTestId(id)?.classList.contains('mat-mdc-tooltip-trigger'), id).toBe(true);
    }
  });

  it('opens the settings from a gear, on the first settings page the user may see', async () => {
    const { fixture, byTestId } = await render();
    expect(byTestId('settings-gear')?.getAttribute('aria-label')).toBe('Paramètres');
    expect(byTestId('settings-gear')?.closest('header')).not.toBeNull();
    expect(byTestId('settings-gear')?.getAttribute('href')).toBe('/members');

    permissions.set(['user.read', 'company.settings']);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('settings-gear')?.getAttribute('href')).toBe('/company/profile');
  });

  it('opens the list of settings from the account menu on a phone, where the list and a page do not fit side by side', async () => {
    width.next(390);
    const { click, byTestId } = await render();
    expect(byTestId('settings-gear')).toBeNull();
    await click('user-menu');
    expect(byTestId('account-settings')?.getAttribute('href')).toBe('/company');
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

// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { SignedInState } from '../auth/auth-types';
import { CompanyFacade } from '../company/company-facade';
import { NotificationsFacade } from '../notifications/notifications-facade';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { AppShell } from './app-shell';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      app: { name: 'twes-in' },
      auth: { logout: 'Se déconnecter' },
      nav: {
        home: 'Accueil',
        members: 'Membres',
        customers: 'Clients',
        design: 'Design',
        sections: { main: 'Général', team: 'Équipe' },
      },
      shell: {
        menu: 'Menu',
        user_menu: 'Compte',
        language: 'Langue',
        dark_on: 'Mode sombre',
        dark_off: 'Mode clair',
        collapse_menu: 'Réduire le menu',
        expand_menu: 'Déployer le menu',
        settings: 'Paramètres',
      },
      languages: { fr: 'Français', en: 'English' },
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
};

describe('AppShell', () => {
  const me = signal<SignedInState | null>(owner);
  const permissions = signal<readonly string[]>(['user.read']);
  const modules = signal<readonly string[]>(['customers']);
  const auth = {
    me: me.asReadonly(),
    logout: vi.fn(async () => undefined),
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
    toggleScheme: vi.fn(),
    density: signal<'comfortable' | 'compact'>('comfortable'),
    toggleDensity: vi.fn(),
    sidebar: signal<'expanded' | 'rail'>('expanded'),
    toggleSidebar: vi.fn(),
  };
  const language = { current: signal('fr'), use: vi.fn(async () => undefined) };

  beforeEach(async () => {
    me.set(owner);
    permissions.set(['user.read']);
    modules.set(['customers']);
    theme.sidebar.set('expanded');
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [AppShell],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: auth },
        { provide: CompanyFacade, useValue: companies },
        { provide: ThemeFacade, useValue: theme },
        { provide: LanguageFacade, useValue: language },
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
    // The company settings live behind the gear, not in the sidebar.
    expect(byTestId('nav-members')).toBeNull();
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

  it('keeps every part of the shell inside a landmark, each named once', async () => {
    const { el } = await render();

    expect(el.querySelector('[data-testid="brand"]')?.closest('nav')).not.toBeNull();
    expect(el.querySelector('[data-testid="user-menu"]')?.closest('header')).not.toBeNull();
    const lists = [...el.querySelectorAll<HTMLElement>('mat-nav-list')];
    expect(lists.length).toBeGreaterThan(0);
    const names = lists.map((list) =>
      el.querySelector(`#${list.getAttribute('aria-labelledby')}`)?.textContent?.trim(),
    );
    expect(names).toEqual(['Général']);
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

  it('switches the language from the account menu', async () => {
    const { click } = await render();
    await click('user-menu');
    await click('language-en');
    expect(language.use).toHaveBeenCalledWith('en');
  });

  it('toggles dark mode from the account menu', async () => {
    const { click } = await render();
    await click('user-menu');
    await click('theme-toggle');
    expect(theme.toggleScheme).toHaveBeenCalledTimes(1);
  });

  it('switches to compact density from the account menu', async () => {
    const { click } = await render();
    await click('user-menu');
    await click('density-toggle');
    expect(theme.toggleDensity).toHaveBeenCalledTimes(1);
  });
});

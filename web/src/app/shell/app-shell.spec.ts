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
        design: 'Design',
        sections: { main: 'Général', admin: 'Administration' },
      },
      shell: {
        menu: 'Menu',
        user_menu: 'Compte',
        language: 'Langue',
        dark_on: 'Mode sombre',
        dark_off: 'Mode clair',
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
};

describe('AppShell', () => {
  const me = signal<SignedInState | null>(owner);
  const permissions = signal<readonly string[]>(['user.read']);
  const auth = {
    me: me.asReadonly(),
    logout: vi.fn(async () => undefined),
    hasPermission: (permission: string) => permissions().includes(permission),
  };
  const companies = {
    companies: signal([]).asReadonly(),
    current: computed(() => me()?.company ?? null),
    canSwitch: signal(false).asReadonly(),
    switching: signal(false).asReadonly(),
    load: vi.fn(async () => []),
    switchTo: vi.fn(),
  };
  const theme = { scheme: signal<'light' | 'dark'>('light'), toggleScheme: vi.fn() };
  const language = { current: signal('fr'), use: vi.fn(async () => undefined) };

  beforeEach(async () => {
    me.set(owner);
    permissions.set(['user.read']);
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [AppShell],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: auth },
        { provide: CompanyFacade, useValue: companies },
        { provide: ThemeFacade, useValue: theme },
        { provide: LanguageFacade, useValue: language },
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
    expect(byTestId('nav-members')?.textContent).toContain('Membres');
  });

  it('hides an entry whose permission the user lacks', async () => {
    permissions.set([]);
    const { byTestId } = await render();
    expect(byTestId('nav-home')).not.toBeNull();
    expect(byTestId('nav-members')).toBeNull();
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
});

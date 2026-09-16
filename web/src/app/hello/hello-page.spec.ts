// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { InvoicesFacade } from '../invoices/invoices-facade';
import { INVOICES_HOME } from '../invoices/invoices-nav';
import { todayIn } from '../shared/i18n/format';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { Session } from '../shared/session/session';
import type { SignedInState } from '../auth/auth-types';
import { HelloPage } from './hello-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      app: { name: 'twes-in' },
      auth: { logout: 'Se déconnecter' },
      hello: {
        greeting: 'Bonjour, {{name}}',
        company: 'Vous travaillez dans {{company}} en tant que {{role}}.',
        no_company: 'Aucune entreprise.',
        operator: 'Opérateur de la plateforme.',
        platform: 'Gérer la plateforme',
      },
      roles: { owner: 'propriétaire' },
    });
  }
}

const owner: SignedInState = {
  user: {
    id: '1',
    email: 'owner@example.test',
    displayName: 'Amel',
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

describe('HelloPage', () => {
  const me = signal<SignedInState | null>(owner);
  const logout = vi.fn(async () => {
    me.set(null);
  });
  const hasPermission = vi.fn().mockReturnValue(false);
  const hasModule = vi.fn().mockReturnValue(false);
  const invoices = {
    summary: signal(null).asReadonly(),
    error: signal(null).asReadonly(),
    loadSummary: vi.fn().mockResolvedValue(undefined),
  };

  beforeEach(async () => {
    me.set(owner);
    logout.mockClear();
    hasPermission.mockReset().mockReturnValue(false);
    hasModule.mockReset().mockReturnValue(false);
    await TestBed.configureTestingModule({
      imports: [HelloPage],
      providers: [
        provideRouter([]),
        {
          provide: AuthFacade,
          useValue: { me: me.asReadonly(), logout, hasPermission, hasModule },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: InvoicesFacade, useValue: invoices },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(HelloPage);
    await fixture.whenStable();
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    return {
      fixture,
      el,
      text: (id: string) => el.querySelector(`[data-testid="${id}"]`)?.textContent?.trim(),
    };
  }

  it('greets the user by name and names the company and role', async () => {
    const { text } = await render();
    expect(text('greeting')).toBe('Bonjour, Amel');
    expect(text('company-line')).toBe('Vous travaillez dans Demo en tant que propriétaire.');
    // The company's day, written out in its locale: the year is enough to tell it is today's, whatever the clock.
    expect(text('home-today')).toContain(todayIn('Africa/Tunis').slice(0, 4));
  });

  it('shows the invoices panel to a reader of invoices in a company that has them on, and to nobody else', async () => {
    const without = await render();
    // Give a panel that should not be there every chance to arrive: the same import, then a turn beyond it.
    await INVOICES_HOME[0]?.load();
    await new Promise((resolve) => setTimeout(resolve));
    without.fixture.detectChanges();
    expect(without.el.querySelector('app-invoices-home')).toBeNull();
    without.fixture.destroy();

    hasPermission.mockImplementation((permission: string) => permission === 'invoice.read');
    hasModule.mockImplementation((module: string) => module === 'invoices');
    const reader = await render();
    // The panel's component arrives through a dynamic import, several turns after the first render.
    await vi.waitFor(() => {
      reader.fixture.detectChanges();
      expect(reader.el.querySelector('app-invoices-home')).not.toBeNull();
    });
    expect(invoices.loadSummary).toHaveBeenCalledWith('c1');
  });

  it('says so when the user has no company, and shows the operator line', async () => {
    me.set({
      ...owner,
      user: { ...owner.user, isPlatformOperator: true },
      company: null,
      permissions: [],
    });
    const { text } = await render();
    expect(text('company-line')).toBe('Aucune entreprise.');
    expect(text('operator-line')).toBe('Opérateur de la plateforme.');
    expect(text('home-today')).toBeUndefined();
  });

  it('offers the platform page to an operator, and to nobody else', async () => {
    const asOwner = await render();
    expect(asOwner.el.querySelector('[data-testid="hello-platform-link"]')).toBeNull();
    asOwner.fixture.destroy();

    me.set({ ...owner, user: { ...owner.user, isPlatformOperator: true } });
    const asOperator = await render();
    const link = asOperator.el.querySelector('[data-testid="hello-platform-link"]');
    expect(link?.getAttribute('href')).toBe('/platform');
    expect(link?.textContent?.trim()).toBe('Gérer la plateforme');
  });
});

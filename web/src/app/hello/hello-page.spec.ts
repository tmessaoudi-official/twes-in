// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
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
        permissions: 'Permissions',
        none: 'aucune',
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
};

describe('HelloPage', () => {
  const me = signal<SignedInState | null>(owner);
  const logout = vi.fn(async () => {
    me.set(null);
  });

  beforeEach(async () => {
    me.set(owner);
    logout.mockClear();
    await TestBed.configureTestingModule({
      imports: [HelloPage],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: { me: me.asReadonly(), logout } },
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
    expect(text('permissions')).toContain('*');
    expect(text('user-name')).toBe('Amel');
    expect(text('company-name')).toBe('Demo');
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
    expect(text('permissions')).toContain('aucune');
  });

  it('signs out through the facade and goes to the login page', async () => {
    const { fixture, el } = await render();
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    el.querySelector<HTMLButtonElement>('[data-testid="logout"]')?.click();
    await fixture.whenStable();

    expect(logout).toHaveBeenCalledTimes(1);
    expect(navigate).toHaveBeenCalledWith('/login');
  });
});

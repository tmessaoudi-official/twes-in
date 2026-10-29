// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import type { SignedInState } from '../auth/auth-types';
import { CompanyClosing } from './company-closing';
import { CompanyClosingActions } from './company-closing-actions';

describe('CompanyClosing', () => {
  const me = signal<SignedInState | null>(null);
  const actions = { close: vi.fn(async () => true) };

  const as = (role: string): SignedInState => ({
    user: {
      id: 'u1',
      email: 'mounir@example.test',
      displayName: 'Mounir',
      locale: 'fr',
      isPlatformOperator: false,
      demo: false,
    },
    company: {
      id: 'c1',
      name: 'Quincaillerie Ben Salah',
      countryCode: 'TN',
      currency: 'TND',
      locale: 'fr',
      timezone: 'Africa/Tunis',
      status: 'active',
      role,
      access: 'full',
      subscription: null,
    },
    permissions: ['*'],
    modules: [],
    mfa: {
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 0,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    },
  });

  beforeEach(async () => {
    actions.close.mockClear();
    await TestBed.configureTestingModule({
      imports: [CompanyClosing],
      providers: [
        { provide: AuthFacade, useValue: { me } },
        { provide: CompanyClosingActions, useValue: actions },
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(CompanyClosing);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    return {
      fixture,
      query: (id: string) => el.querySelector<HTMLElement>(`[data-testid="${id}"]`),
    };
  }

  it('offers its owner to close the company, naming it', async () => {
    me.set(as('owner'));
    const { fixture, query } = await render();

    expect(query('company-close-owner-only')).toBeNull();
    query('company-close')?.click();
    await fixture.whenStable();

    expect(actions.close).toHaveBeenCalledWith({ id: 'c1', name: 'Quincaillerie Ben Salah' });
  });

  it('tells anyone else who may, offering them nothing the API would refuse', async () => {
    // An admin holds the settings permission, which opens this page; closing is the owner's alone.
    me.set(as('admin'));
    const { query } = await render();

    expect(query('company-close')).toBeNull();
    expect(query('company-close-owner-only')).not.toBeNull();
  });
});

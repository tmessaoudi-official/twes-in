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
import { AuthFacade } from './auth-facade';
import { Session } from '../shared/session/session';
import type { SignedInState } from './auth-types';
import { AwaitingApprovalPage } from './awaiting-approval-page';
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      auth: {
        awaiting: {
          title: 'Presque prêt',
          pending: '{{company}} attend l’approbation d’un opérateur.',
          suspended: '{{company}} est suspendue.',
          locked: '{{company}} est fermée en attendant le règlement.',
          sign_out: 'Se déconnecter',
        },
      },
    });
  }
}

function state(status: string, access: 'full' | 'read_only' | 'locked' = 'full'): SignedInState {
  return {
    user: {
      id: '1',
      email: 'new@example.test',
      displayName: 'Nadia',
      locale: 'fr',
      isPlatformOperator: false,
    },
    company: {
      id: 'c1',
      name: 'Nouvelle Société',
      countryCode: 'TN',
      currency: 'TND',
      locale: 'fr',
      timezone: 'Africa/Tunis',
      status,
      role: 'owner',
      access,
      subscription:
        access === 'locked'
          ? {
              stage: 'unpaid' as const,
              coveredUntil: '2026-08-31T23:59:59+01:00',
              graceEndsAt: '2026-09-07T23:59:59+01:00',
              daysLeft: null,
            }
          : null,
    },
    permissions: ['*'],
    modules: [],
    mfa: { enrolled: false, required: false, totp: false, passkeys: 0 },
  };
}

describe('AwaitingApprovalPage', () => {
  const me = signal<SignedInState | null>(state('pending'));
  const auth = { me, logout: vi.fn() };

  beforeEach(async () => {
    me.set(state('pending'));
    auth.logout.mockReset().mockResolvedValue(undefined);
    await TestBed.configureTestingModule({
      imports: [AwaitingApprovalPage],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        provideStillAppearance(),
        provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(AwaitingApprovalPage);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    return { fixture, query };
  }

  it('names the company and says it waits for an operator', async () => {
    const { query } = await render();

    expect(query('awaiting-pending')?.textContent).toContain('Nouvelle Société');
    expect(query('awaiting-suspended')).toBeNull();
  });

  it('says a suspended company is suspended', async () => {
    me.set(state('suspended'));

    const { query } = await render();

    expect(query('awaiting-suspended')?.textContent).toContain('Nouvelle Société');
    expect(query('awaiting-pending')).toBeNull();
  });

  it('says an unpaid company is closed until it is settled, before saying anything about its status', async () => {
    me.set(state('active', 'locked'));

    const { query } = await render();

    expect(query('awaiting-locked')?.textContent).toContain('Nouvelle Société');
    expect(query('awaiting-pending')).toBeNull();
    expect(query('awaiting-suspended')).toBeNull();
  });

  it('signs out and goes back to the sign-in page', async () => {
    const { fixture, query } = await render();
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    query<HTMLButtonElement>('awaiting-sign-out')!.click();
    await fixture.whenStable();
    // The navigation follows the sign-out's promise, a microtask after the click settles.
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(auth.logout).toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith('/login');
  });
});

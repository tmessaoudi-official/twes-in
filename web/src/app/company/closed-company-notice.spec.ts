// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { SignedInState } from '../auth/auth-types';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { ClosedCompanyNotice } from './closed-company-notice';
import { CompanyClosingActions } from './company-closing-actions';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      auth: {
        awaiting: {
          closed: '{{company}} est fermée.',
          closed_kept: 'Conservée jusqu’au {{day}}.',
          reopen_until: 'Rouvrable jusqu’au {{moment}}.',
          reopen: 'Rouvrir l’entreprise',
          reopen_ask_owner: 'Son propriétaire peut la rouvrir jusqu’au {{moment}}.',
        },
      },
    });
  }
}

function state(
  status: string,
  closing: { retainUntil: string; reopenUntil: string } | null,
  role = 'owner',
): SignedInState {
  return {
    user: {
      id: '1',
      email: 'new@example.test',
      displayName: 'Nadia',
      locale: 'fr',
      isPlatformOperator: false,
      demo: false,
    },
    company: {
      id: 'c1',
      name: 'Nouvelle Société',
      countryCode: 'TN',
      currency: 'TND',
      locale: 'fr',
      timezone: 'Africa/Tunis',
      status,
      role,
      access: 'full',
      closing,
      subscription: null,
    },
    permissions: role === 'owner' ? ['company.read', 'accounting.export'] : ['company.read'],
    modules: [],
    mfa: {
      enrolled: false,
      required: false,
      totp: false,
      passkeys: 0,
      recoveryCodes: 0,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    },
  };
}

/**
 * A company its owner closed is still read, in the shell (docs/SPEC.md § 7, 2026-10-13 12:39): the notice above every
 * page says it is closed, until when it is kept and, while the days to take it back last, offers its owner « Rouvrir ».
 */
describe('ClosedCompanyNotice', () => {
  const inAWeek = new Date(Date.now() + 7 * 86_400_000).toISOString();
  const yesterday = new Date(Date.now() - 86_400_000).toISOString();
  const me = signal<SignedInState | null>(null);
  const auth = { me, companyClosedDown: () => me()?.company?.status === 'closed' };
  const closingActions = { reopen: vi.fn(async () => true) };

  beforeEach(async () => {
    closingActions.reopen.mockClear();
    await TestBed.configureTestingModule({
      imports: [ClosedCompanyNotice],
      providers: [
        { provide: AuthFacade, useValue: auth },
        { provide: CompanyClosingActions, useValue: closingActions },
        { provide: Session, useExisting: AuthFacade },
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
    const fixture = TestBed.createComponent(ClosedCompanyNotice);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    return { fixture, query };
  }

  it('says nothing for a working company', async () => {
    me.set(state('active', null));
    const { query } = await render();

    expect(query('closed-notice')).toBeNull();
  });

  it('says it is closed, until when it is kept, and offers its owner to reopen it until the day the API set', async () => {
    me.set(state('closed', { retainUntil: '2036-12-31', reopenUntil: inAWeek }));
    const { fixture, query } = await render();

    expect(query('closed-notice')?.textContent).toContain('Nouvelle Société est fermée.');
    expect(query('closed-notice-kept')?.textContent).toContain('2036');
    expect(query('closed-notice-reopen-until')).not.toBeNull();
    query('closed-notice-reopen')?.click();
    await fixture.whenStable();

    expect(closingActions.reopen).toHaveBeenCalledWith({ id: 'c1', name: 'Nouvelle Société' });
  });

  it('tells a member the owner may reopen it, offering them nothing they would be refused', async () => {
    me.set(state('closed', { retainUntil: '2036-12-31', reopenUntil: inAWeek }, 'accountant'));
    const { query } = await render();

    expect(query('closed-notice-reopen')).toBeNull();
    expect(query('closed-notice-ask-owner')?.textContent).toContain('propriétaire');
  });

  it('stops offering to reopen when the days run out on a page left open', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const soon = new Date(Date.now() + 60_000).toISOString();
      me.set(state('closed', { retainUntil: '2036-12-31', reopenUntil: soon }));
      const { fixture, query } = await render();
      expect(query('closed-notice-reopen')).not.toBeNull();

      await vi.advanceTimersByTimeAsync(60_005);
      fixture.detectChanges();

      expect(query('closed-notice-reopen')).toBeNull();
      expect(query('closed-notice-reopen-until')).toBeNull();
      expect(query('closed-notice-kept')).not.toBeNull();
    } finally {
      vi.useRealTimers();
    }
  });

  it('offers nothing to reopen once the days are over: the closing is final', async () => {
    me.set(state('closed', { retainUntil: '2036-12-31', reopenUntil: yesterday }));
    const { query } = await render();

    expect(query('closed-notice-kept')).not.toBeNull();
    expect(query('closed-notice-reopen')).toBeNull();
    expect(query('closed-notice-ask-owner')).toBeNull();
  });
});

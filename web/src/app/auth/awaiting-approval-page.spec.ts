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
import { CompanyFacade } from '../company/company-facade';
import type { CompanyOption } from '../company/company-types';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
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
          declare_payment: 'Déclarer un règlement',
          ask_owner: 'Seul le propriétaire peut déclarer le règlement.',
          elsewhere: 'Vos autres entreprises',
          sign_out: 'Se déconnecter',
        },
      },
    });
  }
}

function state(
  status: string,
  access: 'full' | 'read_only' | 'locked' = 'full',
  permissions: string[] = ['*'],
  closing: { retainUntil: string; reopenUntil: string } | null = null,
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
      access,
      closing,
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
    permissions,
    modules: [],
    mfa: { enrolled: false, required: false, totp: false, passkeys: 0, recoveryCodes: 0 },
  };
}

describe('AwaitingApprovalPage', () => {
  const me = signal<SignedInState | null>(state('pending'));
  const auth = {
    me,
    logout: vi.fn(async () => true),
    refresh: vi.fn(async () => undefined),
    // The facade's own rule, read off the state the case sets: the role's grants, and paying always open.
    hasPermission: (permission: string) => {
      const held = me()?.permissions ?? [];
      return held.includes('*') || held.includes(permission);
    },
    companyClosed: () => {
      const company = me()?.company;
      return !!company && (company.status !== 'active' || company.access === 'locked');
    },
  };
  const option = (id: string, name: string): CompanyOption => ({
    id,
    name,
    status: 'active',
    role: 'member',
    pinned: false,
    logoVersion: null,
    supportAccessUntil: null,
    supportAccessReason: null,
  });
  const listed = signal<readonly CompanyOption[]>([]);
  const companies = {
    companies: listed,
    load: vi.fn(async () => listed()),
    switchTo: vi.fn(async () => true),
  };

  beforeEach(async () => {
    me.set(state('pending'));
    listed.set([option('c1', 'Nouvelle Société')]);
    auth.logout.mockReset().mockResolvedValue(true);
    auth.refresh.mockClear();
    companies.load.mockClear();
    companies.switchTo.mockReset().mockResolvedValue(true);
    await TestBed.configureTestingModule({
      imports: [AwaitingApprovalPage],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: auth },
        { provide: CompanyFacade, useValue: companies },
        { provide: Session, useExisting: AuthFacade },
        provideStillAppearance(),
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
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

  it('offers the payment to whoever may pay it, and tells anyone else to ask the owner', async () => {
    // A clerk pressed « Déclarer un règlement » and met a refusal: only the owner may declare.
    me.set(state('active', 'locked', ['company.read']));
    const clerk = await render();
    expect(clerk.query('awaiting-declare')).toBeNull();
    expect(clerk.query('awaiting-ask-owner')?.textContent).toContain('propriétaire');
    clerk.fixture.destroy();

    me.set(state('active', 'locked', ['*']));
    const owner = await render();
    expect(owner.query('awaiting-declare')).not.toBeNull();
    expect(owner.query('awaiting-ask-owner')).toBeNull();
  });

  it('offers the person’s other companies, and moves the session to the one chosen', async () => {
    // A member of a shut company was cut off from every other company they belong to: sign-out was the only way.
    listed.set([option('c1', 'Nouvelle Société'), option('c2', 'Atelier Ben Salah')]);
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const { fixture, query } = await render();

    expect(query('awaiting-company-c1')).toBeNull();
    query<HTMLButtonElement>('awaiting-company-c2')!.click();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(companies.switchTo).toHaveBeenCalledWith('c2');
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('opens the application as soon as the company opens', async () => {
    // The owner stayed on « en attente » after the operator approved, until they signed out and in again.
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const { fixture } = await render();
    expect(navigate).not.toHaveBeenCalledWith('/');

    me.set(state('active'));
    fixture.detectChanges();
    await fixture.whenStable();

    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('reads the session again when the window comes back in front', async () => {
    await render();
    auth.refresh.mockClear();

    document.dispatchEvent(new Event('visibilitychange'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(auth.refresh).toHaveBeenCalledTimes(1);
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

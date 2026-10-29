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
import { CompanyClosingActions } from '../company/company-closing-actions';
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
          refused: '{{company}} n’a pas été acceptée.',
          reason: 'Motif :',
          locked: '{{company}} est fermée en attendant le règlement.',
          declare_payment: 'Déclarer un règlement',
          ask_owner: 'Seul le propriétaire peut déclarer le règlement.',
          elsewhere: 'Vos autres entreprises',
          sign_out: 'Se déconnecter',
          no_contact: 'L’équipe de la plateforme n’a pas encore indiqué comment la joindre.',
        },
      },
      account: { title: 'Mon compte' },
      company: { closing: { close: 'Fermer l’entreprise' } },
      help: {
        contact: {
          title: 'Nous contacter',
          phone: 'Téléphone :',
          whatsapp: 'WhatsApp :',
          email: 'E-mail :',
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
  const closing = { close: vi.fn(async () => true) };
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
    closing.close.mockReset().mockResolvedValue(true);
    await TestBed.configureTestingModule({
      imports: [AwaitingApprovalPage],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: auth },
        { provide: CompanyFacade, useValue: companies },
        { provide: CompanyClosingActions, useValue: closing },
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
    expect(query('awaiting-reason')).toBeNull();
  });

  // The operator's reason, when one was given, and refused rather than suspended for a company never opened.
  it('gives the reason the operator gave for the suspension', async () => {
    const suspended = state('suspended');
    me.set({
      ...suspended,
      company: { ...suspended.company!, suspensionReason: 'Facture impayée' },
    });

    const { query } = await render();

    expect(query('awaiting-suspended')?.textContent).toContain('Nouvelle Société');
    expect(query('awaiting-reason')?.textContent).toContain('Motif : Facture impayée');
  });

  it('says a company never opened was refused, never that it is suspended', async () => {
    const suspended = state('suspended');
    me.set({
      ...suspended,
      company: { ...suspended.company!, refused: true, suspensionReason: 'Matricule introuvable' },
    });

    const { query } = await render();

    expect(query('awaiting-refused')?.textContent).toContain(
      'Nouvelle Société n’a pas été acceptée.',
    );
    expect(query('awaiting-suspended')).toBeNull();
    expect(query('awaiting-reason')?.textContent).toContain('Matricule introuvable');
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

  it('lets the owner of a company its subscription locked close it down, and nobody else', async () => {
    // A shop that had stopped trading could neither pay nor close: the owner stayed locked out with the company open.
    me.set(state('active', 'locked', ['subscription.read', 'subscription.pay'], null, 'owner'));
    const owner = await render();
    owner.query<HTMLButtonElement>('awaiting-close')!.click();
    await owner.fixture.whenStable();
    expect(closing.close).toHaveBeenCalledWith({ id: 'c1', name: 'Nouvelle Société' });
    owner.fixture.destroy();

    me.set(state('active', 'locked', ['subscription.read'], null, 'member'));
    const clerk = await render();
    expect(clerk.query('awaiting-close')).toBeNull();
    clerk.fixture.destroy();

    // A suspended or pending company is the operator's to close.
    for (const status of ['suspended', 'pending']) {
      me.set(state(status, 'full', ['*'], null, 'owner'));
      const other = await render();
      expect(other.query('awaiting-close')).toBeNull();
      other.fixture.destroy();
    }
  });

  it.each(['pending', 'suspended', 'locked'])(
    'leads to « Mon compte » whatever holds the company back (%s)',
    async (held) => {
      // Changing a password or deleting the account was out of reach while the company was shut.
      me.set(held === 'locked' ? state('active', 'locked') : state(held));

      const { query } = await render();

      expect(query<HTMLAnchorElement>('awaiting-account')?.getAttribute('href')).toBe('/account');
      expect(query('awaiting-account')?.textContent).toContain('Mon compte');
    },
  );

  it.each(['suspended', 'pending', 'locked'])(
    'says how to reach the platform while the company waits on it (%s)',
    async (held) => {
      // A suspended company's owner was told it was suspended, and nothing of whom to ask why.
      me.set({
        ...(held === 'locked' ? state('active', 'locked') : state(held)),
        support: { phone: '+216 71 000 000', whatsapp: '+216 20 000 000', email: 'aide@twes.test' },
      });

      const { query } = await render();

      expect(query('awaiting-contact')?.textContent).toContain('Nous contacter');
      expect(query<HTMLAnchorElement>('awaiting-contact-phone')?.getAttribute('href')).toBe(
        'tel:+21671000000',
      );
      expect(query<HTMLAnchorElement>('awaiting-contact-whatsapp')?.getAttribute('href')).toBe(
        'https://wa.me/21620000000',
      );
      expect(query<HTMLAnchorElement>('awaiting-contact-email')?.getAttribute('href')).toBe(
        'mailto:aide@twes.test',
      );
    },
  );

  it.each(['suspended', 'locked'])(
    'says the platform named no way to reach it, rather than only asking to contact it (%s)',
    async (held) => {
      // « Contactez l’équipe » with nothing under it left the owner looking for a way that does not exist.
      me.set({
        ...(held === 'locked' ? state('active', 'locked') : state(held)),
        support: { phone: null, whatsapp: null, email: null },
      });

      const { query } = await render();

      expect(query('awaiting-contact')).toBeNull();
      expect(query('awaiting-no-contact')?.textContent).toContain(
        'n’a pas encore indiqué comment la joindre',
      );
    },
  );

  it('says nothing of a way to reach the platform to a company only waiting for approval', async () => {
    me.set({ ...state('pending'), support: { phone: null, whatsapp: null, email: null } });

    const { query } = await render();

    expect(query('awaiting-no-contact')).toBeNull();
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

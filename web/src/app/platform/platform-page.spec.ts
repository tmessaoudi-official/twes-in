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
import { SubscriptionFacade } from '../licensing/subscription-facade';
import type { WaitingPayment } from '../licensing/subscription-types';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { PlatformFacade } from './platform-facade';
import { PlatformPage } from './platform-page';
import type {
  PlatformAccountRow,
  PlatformCompanyRow,
  PlatformError,
  PlatformSignup,
  PlatformDemo,
  PlatformImports,
  PlatformNumber,
  PlatformSupport,
  ModuleDemandRow,
  PlatformSubscriptionRow,
  FailedMessagesRead,
} from './platform-types';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      modules: { quotes: 'Devis et commandes', zakat: 'Zakat' },
      roles: { owner: 'propriétaire', member: 'membre', clerk: 'caissier / vendeur' },
      settings: {
        platform: {
          account_deletion_days: 'Jours avant d’effacer un compte supprimé',
          company_reopen_days: 'Jours pour rouvrir une entreprise fermée',
          demo_enabled: 'Démo ouverte aux visiteurs (« Essayer la démo »)',
          demo_sandboxes_max: 'Démos au plus en même temps',
          import_max_rows: 'Lignes au plus par fichier importé',
        },
      },
      platform: {
        demo: { title: 'Démo', bounds: 'De {{min}} à {{max}} démos', save: 'Enregistrer' },
        imports: { title: 'Imports', bounds: 'De {{min}} à {{max}} lignes', save: 'Enregistrer' },
        failed: {
          title: 'Messages non partis',
          total: 'Non partis : {{count}}',
          retry: 'Relancez-les sur le worker :',
          kinds: { invitation: 'Invitations', password_reset: 'Mots de passe oubliés' },
        },
        demand: {
          title: 'Modules attendus',
          companies: 'Sociétés : {{count}}',
          empty: 'Aucune société n’attend encore de module.',
        },
        title: 'Plateforme',
        tabs: {
          overview: 'Vue d’ensemble',
          companies: 'Entreprises',
          accounts: 'Comptes',
          payments: 'Paiements',
          demand: 'Demande',
        },
        list: {
          company: {
            name: 'Entreprise',
            country: 'Pays',
            status: 'Statut',
            owners: 'Propriétaires',
            subscription: 'Abonnement',
            created: 'Créée le',
          },
          account: {
            name: 'Compte',
            companies: 'Entreprises',
            state: 'État',
            created: 'Créé le',
            actions: 'Actions',
          },
        },
        signup: {
          title: 'Inscriptions',
          enabled: 'Inscriptions ouvertes',
          approval_required: 'Approbation requise',
        },
        waiting: {
          title: 'En attente',
          none: 'Aucune entreprise en attente.',
          owners: 'Propriétaires : {{owners}}',
          approve: 'Approuver',
          reject: 'Refuser',
          all: 'Voir les {{count}} en attente',
        },
        accounts: {
          title: 'Comptes',
          search: 'Adresse ou nom',
          find: 'Rechercher',
          none: 'Aucun compte trouvé.',
          active: 'Actif',
          inactive: 'Désactivé',
          operator: 'Opérateur',
          end_sessions: 'Terminer les sessions',
          deactivate: 'Désactiver',
          reactivate: 'Réactiver',
          states: { active: 'Actifs', inactive: 'Désactivés', operator: 'Opérateurs' },
        },
        companies: {
          title: 'Entreprises',
          name: 'Nom',
          country: 'Pays',
          owner: 'Adresse du propriétaire',
          create: 'Ouvrir',
          invite: 'Inviter',
          invited: 'Invitation envoyée à {{email}}.',
          none: 'Aucune entreprise.',
          new: 'Nouvelle entreprise',
          close: 'Fermer',
          no_owner: 'Aucun propriétaire.',
          countries: { TN: 'Tunisie', FR: 'France' },
          statuses: { pending: 'En attente', active: 'Active', suspended: 'Suspendue' },
        },
        payments: {
          title: 'Règlements à confirmer',
          none: 'Aucun règlement en attente.',
          periods: 'Périodes couvertes',
          confirm: 'Confirmer',
          reject: 'Refuser',
          confirmed: 'Le règlement a été confirmé.',
          rejected: 'Le règlement a été refusé.',
        },
        errors: {
          not_found: 'Introuvable',
          refused: 'Refusé',
          network: 'Injoignable',
          own_account: 'Pas le vôtre',
        },
      },
      licensing: { payment: { methods: { cash: 'Espèces' } } },
    });
  }
}

const declared: WaitingPayment = {
  id: 'p1',
  companyId: 'c1',
  companyName: 'Nouvelle Société',
  amount: '600.000',
  currency: 'TND',
  method: 'cash',
  paidOn: '2026-09-16',
  reference: 'REC-12',
  note: null,
  status: 'declared',
  declaredAt: '2026-09-16T10:00:00+00:00',
  decidedAt: null,
  decisionNote: null,
};

const account: PlatformAccountRow = {
  id: 'u1',
  email: 'nadia@acme.test',
  displayName: 'Nadia',
  active: true,
  platformOperator: false,
  createdAt: '2026-09-15T10:00:00+00:00',
  companies: [
    { id: 'c1', name: 'Acme', role: 'owner' },
    { id: 'c2', name: 'Globex', role: 'Comptable' },
  ],
};

const row: PlatformCompanyRow = {
  id: 'c1',
  name: 'Nouvelle Société',
  countryCode: 'TN',
  status: 'pending',
  createdAt: '2026-09-15T10:00:00+00:00',
  owners: ['nadia@example.test'],
  subscription: null,
};

describe('PlatformPage', () => {
  const waiting = signal<readonly PlatformCompanyRow[]>([row]);
  const signup = signal<PlatformSignup | null>({ enabled: false, approvalRequired: true });
  const support = signal<PlatformSupport | null>({
    phone: '',
    whatsapp: '',
    email: '',
    status_url: '',
  });
  const delayRows: readonly PlatformNumber[] = [
    {
      key: 'account.deletion_days',
      labelKey: 'settings.platform.account_deletion_days',
      value: 7,
      min: 1,
      max: 90,
    },
    {
      key: 'company.reopen_days',
      labelKey: 'settings.platform.company_reopen_days',
      value: 7,
      min: 1,
      max: 90,
    },
  ];
  const delays = signal<readonly PlatformNumber[] | null>(delayRows);
  const demoState: PlatformDemo = {
    enabled: true,
    most: {
      key: 'demo.sandboxes_max',
      labelKey: 'settings.platform.demo_sandboxes_max',
      value: 10,
      min: 3,
      max: 100,
    },
  };
  const demo = signal<PlatformDemo | null>(demoState);
  const importsState: PlatformImports = {
    rows: {
      key: 'import.max_rows',
      labelKey: 'settings.platform.import_max_rows',
      value: 5000,
      min: 1,
      max: 5000,
    },
  };
  const imports = signal<PlatformImports | null>(importsState);
  const busy = signal(false);
  const error = signal<PlatformError | null>(null);
  const accounts = signal<readonly PlatformAccountRow[]>([account]);
  const companies = signal<readonly PlatformCompanyRow[]>([row]);
  const subscription = signal<PlatformSubscriptionRow | null>(null);
  const waitingPayments = signal<readonly WaitingPayment[]>([declared]);
  const openedSubscription = signal<string | null>(null);
  const demand = signal<readonly ModuleDemandRow[]>([]);
  const failed = signal<FailedMessagesRead>({ total: 0, kinds: [] });
  const waitingTotal = signal(1);
  const companiesTotal = signal(1);
  const accountsTotal = signal(1);
  const facade = {
    waitingTotal: waitingTotal.asReadonly(),
    companiesTotal: companiesTotal.asReadonly(),
    accountsTotal: accountsTotal.asReadonly(),
    companyCount: companiesTotal.asReadonly(),
    accountCount: accountsTotal.asReadonly(),
    loadCompanies: vi.fn(async () => undefined),
    loadAccounts: vi.fn(async () => undefined),
    demand: demand.asReadonly(),
    failed: failed.asReadonly(),
    waiting,
    companies,
    openCompany: vi.fn(),
    inviteOwner: vi.fn(),
    accounts,
    signup,
    support: support.asReadonly(),
    saveSupport: vi.fn(async () => true),
    delays: delays.asReadonly(),
    saveDelays: vi.fn(async () => true),
    demo: demo.asReadonly(),
    saveDemoMost: vi.fn(async () => true),
    imports: imports.asReadonly(),
    saveImportRows: vi.fn(async () => true),
    busy,
    error,
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
    approve: vi.fn(),
    reject: vi.fn(),
    setSwitch: vi.fn(),
    actOnAccount: vi.fn(),
    subscription: subscription.asReadonly(),
    openedSubscription: openedSubscription.asReadonly(),
    openSubscription: vi.fn(async (companyId: string) => {
      openedSubscription.set(companyId);
    }),
    closeSubscription: vi.fn(() => openedSubscription.set(null)),
    saveSubscription: vi.fn(async () => true),
    stopSubscription: vi.fn(async () => true),
    clearError: vi.fn(),
  };
  const payments = {
    waiting: waitingPayments.asReadonly(),
    loadWaiting: vi.fn(async () => undefined),
    confirm: vi.fn(async () => true),
    reject: vi.fn(async () => true),
  };

  beforeEach(async () => {
    waiting.set([row]);
    signup.set({ enabled: false, approvalRequired: true });
    support.set({ phone: '', whatsapp: '', email: '', status_url: '' });
    delays.set(delayRows);
    demo.set(demoState);
    imports.set(importsState);
    error.set(null);
    accounts.set([account]);
    companies.set([row]);
    waitingPayments.set([declared]);
    demand.set([]);
    failed.set({ total: 0, kinds: [] });
    waitingTotal.set(1);
    companiesTotal.set(1);
    accountsTotal.set(1);
    openedSubscription.set(null);
    subscription.set(null);
    Object.values(payments)
      .filter((value) => typeof value === 'function' && 'mockReset' in value)
      .forEach((fn) => (fn as ReturnType<typeof vi.fn>).mockReset().mockResolvedValue(true));
    Object.values(facade)
      .filter((value) => typeof value === 'function' && 'mockReset' in value)
      .forEach((fn) => (fn as ReturnType<typeof vi.fn>).mockReset().mockResolvedValue(true));
    await TestBed.configureTestingModule({
      imports: [PlatformPage],
      providers: [
        provideRouter([]),
        ...provideQuietFeedback(),
        { provide: PlatformFacade, useValue: facade },
        { provide: SubscriptionFacade, useValue: payments },
        {
          provide: AuthFacade,
          useValue: { me: () => ({ user: { id: 'op1' }, company: null }), refresh: vi.fn() },
        },
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

  /** The page on one of its tabs, as an address names it. */
  async function render(address = '/') {
    await TestBed.inject(Router).navigateByUrl(address);
    const fixture = TestBed.createComponent(PlatformPage);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    const switchOf = (id: string) =>
      query(id)!.querySelector('button[role="switch"]') as HTMLButtonElement;
    return { fixture, query, switchOf };
  }

  it('opens a company subscription panel, saves its terms and closes it again', async () => {
    const { fixture, query } = await render('/?tab=companies&company=c1');

    query('subscription-Nouvelle Société')!.click();
    await fixture.whenStable();
    expect(facade.openSubscription).toHaveBeenCalledWith('c1');

    openedSubscription.set('c1');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(query('subscription-panel-Nouvelle Société')).not.toBeNull();

    const paidThrough = query<HTMLInputElement>('field-paidThrough')!;
    paidThrough.value = '2026-12-31';
    paidThrough.dispatchEvent(new Event('input'));
    // The two choices are Selects: opened, the option taken, as a person does.
    for (const [select, option] of [
      ['field-periodUnit', 'platform.subscription.units.year'],
      ['field-unpaidMode', 'platform.subscription.modes.locked'],
    ] as const) {
      query(select)!.click();
      await fixture.whenStable();
      fixture.detectChanges();
      optionNamed(option).click();
      await fixture.whenStable();
      fixture.detectChanges();
    }
    query('subscription-save')!.click();
    await fixture.whenStable();

    expect(facade.saveSubscription).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        paidThrough: '2026-12-31',
        periodUnit: 'year',
        unpaidMode: 'locked',
        graceDays: null,
      }),
      0,
    );

    query('subscription-Nouvelle Société')!.click();
    await fixture.whenStable();
    expect(facade.closeSubscription).toHaveBeenCalled();
  });

  it('takes the terms another operator set since where none was typed, and offers both ways one both changed', async () => {
    const held: PlatformSubscriptionRow = {
      companyId: 'c1',
      periodCount: 1,
      periodUnit: 'month',
      trialEndsOn: null,
      paidThrough: '2026-12-31',
      price: '600.000',
      currency: 'TND',
      graceDays: 3,
      unpaidMode: null,
      holdDays: null,
      stage: 'paid',
      access: 'full',
      coveredUntil: '2026-12-31T23:59:59+01:00',
      graceEndsAt: '2027-01-03T23:59:59+01:00',
      daysLeft: 70,
      updatedAt: '2026-10-17T10:00:00+00:00',
      version: 3,
    };
    const theirs: PlatformSubscriptionRow = {
      ...held,
      price: '700.000',
      graceDays: 5,
      holdDays: 4,
      version: 4,
    };
    facade.openSubscription.mockImplementation(async (companyId: string) => {
      openedSubscription.set(companyId);
      subscription.set(held);
    });
    const { fixture, query } = await render('/?tab=companies&company=c1');
    query('subscription-Nouvelle Société')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    for (const [field, value] of [
      ['field-graceDays', '9'],
      ['field-holdDays', '4'],
    ] as const) {
      const input = query<HTMLInputElement>(field)!;
      input.value = value;
      input.dispatchEvent(new Event('input'));
    }
    facade.saveSubscription.mockImplementationOnce(async () => {
      error.set('record_changed');
      subscription.set(theirs);
      return false;
    });
    query('subscription-save')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(facade.saveSubscription).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ graceDays: 9, holdDays: 4, price: '600.000' }),
      3,
    );
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      { kind: 'failure', key: 'live.saved_since', params: undefined },
    ]);
    // Their price is taken, the hold days both typed alike need nothing, and the grace days both changed are offered.
    expect(query<HTMLInputElement>('field-price')!.value).toContain('700');
    expect(query('field-conflict-holdDays')).toBeNull();
    expect(query('field-conflict-graceDays')).not.toBeNull();
    expect(query<HTMLInputElement>('field-graceDays')!.value).toBe('9');

    query('field-take-theirs-graceDays')!.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(query('field-conflict-graceDays')).toBeNull();
    expect(query<HTMLInputElement>('field-graceDays')!.value).toBe('5');

    // Refused again: the price taken from them the first time is theirs now, so their next price is taken too.
    facade.saveSubscription.mockImplementationOnce(async () => {
      subscription.set({ ...theirs, price: '800.000', version: 5 });
      return false;
    });
    query('subscription-save')!.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(facade.saveSubscription).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ graceDays: 5, holdDays: 4, price: '700.000' }),
      4,
    );
    expect(query('field-conflict-price')).toBeNull();
    expect(query<HTMLInputElement>('field-price')!.value).toContain('800');
  });

  it('loads the platform when it opens', async () => {
    await render();

    expect(facade.load).toHaveBeenCalled();
  });

  it('shows each waiting company with its country and owners', async () => {
    const { query } = await render();

    const line = query('waiting-Nouvelle Société')!;
    expect(line.textContent).toContain('Nouvelle Société');
    expect(line.textContent).toContain('TN');
    expect(line.textContent).toContain('nadia@example.test');
    expect(query('platform-waiting-empty')).toBeNull();
  });

  it('says so when no company waits', async () => {
    waiting.set([]);

    const { query } = await render();

    expect(query('platform-waiting-empty')?.textContent).toContain('Aucune entreprise en attente.');
  });

  it('approves or rejects the company of that line', async () => {
    const { fixture, query } = await render();

    query<HTMLButtonElement>('approve-Nouvelle Société')!.click();
    await fixture.whenStable();
    expect(facade.approve).toHaveBeenCalledWith('c1');

    query<HTMLButtonElement>('reject-Nouvelle Société')!.click();
    await fixture.whenStable();
    expect(facade.reject).toHaveBeenCalledWith('c1');
  });

  it('shows the two signup switches as the platform holds them and turns one', async () => {
    const { fixture, switchOf } = await render();

    expect(switchOf('platform-signup-enabled').getAttribute('aria-checked')).toBe('false');
    expect(switchOf('platform-approval-required').getAttribute('aria-checked')).toBe('true');

    switchOf('platform-signup-enabled').click();
    await fixture.whenStable();

    expect(facade.setSwitch).toHaveBeenCalledWith('signup.enabled', true);
  });

  it('shows the demo switch as the platform holds it and turns the demo off', async () => {
    const { fixture, query, switchOf } = await render();

    expect(query('platform-demo')?.textContent).toContain('Démo ouverte aux visiteurs');
    expect(switchOf('platform-demo-enabled').getAttribute('aria-checked')).toBe('true');

    switchOf('platform-demo-enabled').click();
    await fixture.whenStable();

    expect(facade.setSwitch).toHaveBeenCalledWith('demo.enabled', false);
  });

  it('saves how many demos may exist at once, within its bounds, and leaves it out of the delays', async () => {
    const { fixture, query } = await render();
    expect(
      query('platform-delays')?.querySelector('[data-testid="platform-delay-demo.sandboxes_max"]'),
    ).toBeNull();
    const most = query<HTMLInputElement>('platform-demo-most')!;
    const save = query<HTMLButtonElement>('platform-demo-save')!;
    expect(most.value).toBe('10');
    expect(query('platform-demo')?.textContent).toContain('De 3 à 100 démos');
    expect(save.disabled).toBe(true);

    most.value = '2';
    most.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(save.disabled).toBe(true);

    most.value = '20';
    most.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(save.disabled).toBe(false);
    save.click();
    await fixture.whenStable();

    expect(facade.saveDemoMost).toHaveBeenCalledWith(20);
  });

  it('saves how many rows an imported file may hold, within its bounds, and leaves it out of the delays', async () => {
    const { fixture, query } = await render();
    expect(
      query('platform-delays')?.querySelector('[data-testid="platform-delay-import.max_rows"]'),
    ).toBeNull();
    const rows = query<HTMLInputElement>('platform-import-rows')!;
    const save = query<HTMLButtonElement>('platform-import-save')!;
    expect(query('platform-imports')?.textContent).toContain('Lignes au plus par fichier importé');
    expect(rows.value).toBe('5000');
    expect(query('platform-imports')?.textContent).toContain('De 1 à 5000 lignes');
    expect(save.disabled).toBe(true);

    rows.value = '5001';
    rows.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(save.disabled).toBe(true);

    rows.value = '1200';
    rows.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(save.disabled).toBe(false);
    save.click();
    await fixture.whenStable();

    expect(facade.saveImportRows).toHaveBeenCalledWith(1200);
  });

  it('saves only the support channels typed, and only once one differs from what the platform holds', async () => {
    const { fixture, query } = await render();
    const save = query<HTMLButtonElement>('platform-support-save')!;
    expect(save.disabled).toBe(true);

    const whatsapp = query<HTMLInputElement>('platform-support-whatsapp')!;
    whatsapp.value = ' +216 20 123 456 ';
    whatsapp.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(save.disabled).toBe(false);

    save.click();
    await fixture.whenStable();

    expect(facade.saveSupport).toHaveBeenCalledWith({ whatsapp: '+216 20 123 456' });
  });

  it('draws a field per delay the platform declares, labelled and bounded as declared', async () => {
    const { query } = await render();
    const deletion = query<HTMLInputElement>('platform-delay-account.deletion_days')!;

    expect(deletion.type).toBe('number');
    expect(deletion.value).toBe('7');
    expect(deletion.min).toBe('1');
    expect(deletion.max).toBe('90');
    expect(query('platform-delays')?.textContent).toContain(
      'Jours avant d’effacer un compte supprimé',
    );
    expect(query('platform-delay-company.reopen_days')).not.toBeNull();
  });

  it('saves only the delays changed, and refuses one outside its bounds', async () => {
    const { fixture, query } = await render();
    const save = query<HTMLButtonElement>('platform-delays-save')!;
    expect(save.disabled).toBe(true);

    const reopen = query<HTMLInputElement>('platform-delay-company.reopen_days')!;
    reopen.value = '0';
    reopen.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(save.disabled).toBe(true);

    reopen.value = '14';
    reopen.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(save.disabled).toBe(false);
    save.click();
    await fixture.whenStable();

    expect(facade.saveDelays).toHaveBeenCalledWith({ 'company.reopen_days': 14 });
  });

  it('takes the status page as an address, saved as the other channels are', async () => {
    const { fixture, query } = await render();
    const status = query<HTMLInputElement>('platform-support-status_url')!;
    expect(status.type).toBe('url');

    status.value = 'https://status.twes.test';
    status.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    query<HTMLButtonElement>('platform-support-save')!.click();
    await fixture.whenStable();

    expect(facade.saveSupport).toHaveBeenCalledWith({ status_url: 'https://status.twes.test' });
  });

  it('reads the accounts again from the query the list asks for, and says how many there are', async () => {
    accountsTotal.set(41);

    const { query } = await render('/?tab=accounts');

    expect(facade.loadAccounts).toHaveBeenCalledWith(
      expect.objectContaining({ page: 1, q: '', active: null }),
    );
    expect(query('platform-tab-count-accounts')?.textContent).toContain('41');
  });

  it('shows the companies of an account with its role in each', async () => {
    const { query } = await render('/?tab=accounts');

    const line = query('account-nadia@acme.test')!.textContent!.replace(/\s+/g, ' ');
    expect(line).toContain('Acme (propriétaire)');
    // A company's own role is shown as the company named it.
    expect(line).toContain('Globex (Comptable)');
  });

  it('ends the sessions of an active account or deactivates it', async () => {
    const { fixture, query } = await render('/?tab=accounts');

    const line = query('account-nadia@acme.test')!;
    expect(line.textContent).toContain('Nadia');
    expect(line.textContent).toContain('Actif');
    expect(query('reactivate-nadia@acme.test')).toBeNull();

    query<HTMLButtonElement>('end-sessions-nadia@acme.test')!.click();
    await fixture.whenStable();
    expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'end-sessions');

    query<HTMLButtonElement>('deactivate-nadia@acme.test')!.click();
    await fixture.whenStable();
    expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'deactivate');
  });

  it('only reactivates a deactivated account, which has no session left to end', async () => {
    accounts.set([{ ...account, active: false }]);

    const { fixture, query } = await render('/?tab=accounts');

    expect(query('account-nadia@acme.test')!.textContent).toContain('Désactivé');
    expect(query('deactivate-nadia@acme.test')).toBeNull();
    expect(query('end-sessions-nadia@acme.test')).toBeNull();
    query<HTMLButtonElement>('reactivate-nadia@acme.test')!.click();
    await fixture.whenStable();
    expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'reactivate');
  });

  it('opens a company from its name, country and first owner, and says the invitation went out', async () => {
    const { fixture, query } = await render('/?tab=companies');

    query<HTMLButtonElement>('platform-company-new')!.click();
    await fixture.whenStable();

    const name = query<HTMLInputElement>('platform-company-name')!;
    name.value = 'Globex';
    name.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('platform-company-country')!.click();
    await fixture.whenStable();
    fixture.detectChanges();
    document.body
      .querySelector<HTMLElement>('[data-testid="platform-company-country-option-FR"]')!
      .click();
    await fixture.whenStable();
    fixture.detectChanges();
    const owner = query<HTMLInputElement>('platform-company-owner')!;
    owner.value = 'nadia@example.test';
    owner.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('platform-company-create')!.click();
    await fixture.whenStable();

    expect(facade.openCompany).toHaveBeenCalledWith('Globex', 'FR', 'nadia@example.test');
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      {
        kind: 'success',
        key: 'platform.companies.invited',
        params: { email: 'nadia@example.test' },
      },
    ]);
  });

  it('lists a page of companies with their status, and reads the next one the list asks for', async () => {
    companiesTotal.set(130);

    const { query } = await render('/?tab=companies');

    const line = query('company-Nouvelle Société')!;
    expect(line.textContent).toContain('En attente');
    expect(line.textContent).toContain('nadia@example.test');
    expect(facade.loadCompanies).toHaveBeenCalledWith(
      expect.objectContaining({ page: 1, statuses: [] }),
    );
    expect(query('platform-tab-count-companies')?.textContent).toContain('130');
  });

  it('opens the sheet of the company the address names and invites an owner from it', async () => {
    const { fixture, query } = await render('/?tab=companies&company=c1');

    expect(query('company-sheet-Nouvelle Société')).not.toBeNull();
    const address = query<HTMLInputElement>('owner-email-Nouvelle Société')!;
    address.value = 'karim@example.test';
    address.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('invite-owner-Nouvelle Société')!.click();
    await fixture.whenStable();

    expect(facade.inviteOwner).toHaveBeenCalledWith('c1', 'karim@example.test');
  });

  it('shows no sheet where the address names no company', async () => {
    const { query } = await render('/?tab=companies');

    expect(query('company-sheet-Nouvelle Société')).toBeNull();
  });

  it('shows one tab at a time, the overview where the address names none', async () => {
    const { query, fixture } = await render();

    expect(query('platform-signup-enabled')).not.toBeNull();
    expect(query('platform-companies-table')).toBeNull();
    expect(query('platform-accounts-table')).toBeNull();
    expect(
      fixture.nativeElement.querySelectorAll('[data-testid^="platform-tab-"]').length,
    ).toBeGreaterThan(4);
  });

  it('counts the companies waiting on the overview tab and links to them', async () => {
    waitingTotal.set(7);

    const { query } = await render();

    expect(query('platform-tab-count-overview')?.textContent).toContain('7');
    expect(query('platform-waiting-all')?.textContent).toContain('Voir les 7 en attente');
  });

  it('lists the payments waiting for a decision and answers one', async () => {
    const { fixture, query } = await render('/?tab=payments');

    const waiting = query('payment-p1')!;
    expect(waiting.textContent).toContain('Nouvelle Société');
    // The amount and the day as the operator's screen writes them, never the API's own strings.
    expect(waiting.textContent).toContain('600,000 TND');
    expect(waiting.textContent).toContain('16/09/2026');
    expect(waiting.textContent).toContain('Espèces');

    // The operator says how many periods it covers before confirming; one unless they change it.
    const periods = query<HTMLInputElement>('payment-periods-p1')!;
    periods.value = '2';
    periods.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('payment-confirm-p1')!.click();
    await fixture.whenStable();

    expect(payments.confirm).toHaveBeenCalledWith('p1', 2, null);
    // The companies list is read again, since a confirmation moves the company's covered time.
    expect(facade.load).toHaveBeenCalled();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      { kind: 'success', key: 'platform.payments.confirmed', params: undefined },
    ]);
  });

  it('rejects a payment without asking for periods', async () => {
    const { fixture, query } = await render('/?tab=payments');

    query<HTMLButtonElement>('payment-reject-p1')!.click();
    await fixture.whenStable();

    expect(payments.reject).toHaveBeenCalledWith('p1', null);
  });

  it('says so when no payment waits', async () => {
    waitingPayments.set([]);

    const { query } = await render('/?tab=payments');

    expect(query('platform-payments-empty')?.textContent).toContain('Aucun règlement en attente.');
  });

  it('names a refusal', async () => {
    error.set('not_found');

    const { query } = await render();

    expect(query('platform-error')?.textContent).toContain('Introuvable');
  });
  // Row 56: a mail the worker gave up on is said on the overview, by kind, with how to send it again; nothing when none.
  it('says on the overview how many messages failed for good, of which kinds, and how to retry them', async () => {
    const { fixture, query } = await render();
    expect(query('platform-failed')).toBeNull();

    failed.set({
      total: 3,
      kinds: [
        { kind: 'PasswordResetAsked', count: 2 },
        { kind: 'SomethingNew', count: 1 },
      ],
    });
    fixture.detectChanges();

    expect(query('platform-failed-total')?.textContent).toContain('Non partis : 3');
    const kinds = Array.from(
      query('platform-failed')!.querySelectorAll('[data-testid^="failed-kind-"]'),
    ).map((item) => item.textContent?.replace(/\s+/g, ' ').trim());
    expect(kinds).toEqual(['Mots de passe oubliés 2', 'SomethingNew 1']);
    expect(query('platform-failed')?.textContent).toContain('messenger:failed:retry');
  });
  // « Me prévenir » (row 150): the operator reads which planned modules companies wait for, and how many.
  it('lists the planned modules companies wait for, the most asked for first, and says when none is', async () => {
    const { fixture, query } = await render('/?tab=demand');
    fixture.detectChanges();
    expect(query('platform-demand-empty')?.textContent).toContain('Aucune société');

    demand.set([
      { key: 'quotes', labelKey: 'modules.quotes', planned: 'v1', companies: 2 },
      { key: 'zakat', labelKey: 'modules.zakat', planned: 'later', companies: 0 },
    ]);
    fixture.detectChanges();

    expect(query('platform-demand-empty')).toBeNull();
    const rows = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid^="demand-"]') as NodeListOf<HTMLElement>,
    );
    expect(rows.map((row) => row.getAttribute('data-testid'))).toEqual(['demand-quotes']);
    expect(rows[0].textContent?.replace(/\s+/g, ' ')).toContain('Devis et commandes');
    expect(rows[0].textContent).toContain('Sociétés : 2');
  });
});

/** An option of the Select open now, by what it reads. */
function optionNamed(label: string): HTMLElement {
  const option = Array.from(document.body.querySelectorAll<HTMLElement>('[role="option"]')).find(
    (candidate) => candidate.textContent?.includes(label),
  );
  if (option === undefined) throw new Error(`No option reads ${label}.`);
  return option;
}

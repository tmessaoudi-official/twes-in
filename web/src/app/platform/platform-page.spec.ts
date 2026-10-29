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
import { PlatformFacade, type PlatformRefusals } from './platform-facade';
import { TextsParts } from '../shared/i18n/texts-part';
import { PlatformPage } from './platform-page';
import type {
  PlatformAccountRow,
  PlatformCompanyRow,
  PlatformCountry,
  PlatformError,
  PlatformSignup,
  PlatformDemo,
  PlatformImports,
  PlatformNumber,
  PlatformSupport,
  PlatformSupportRules,
  ModuleDemandRow,
  PlatformSubscriptionRow,
  FailedMessagesRead,
} from './platform-types';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { EndOtherSessions } from '../account/end-other-sessions';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';
import { ScreenActions } from '../shared/actions/screen-actions';
import { CompanyFacade } from '../company/company-facade';
import type { CompanyOption } from '../company/company-types';
import { apiPattern } from '../shared/testing/hint-examples';
import { announceSaved } from '../shared/testing/live';

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
        presentation: { show_coming: 'Montrer ce qui arrive' },
      },
      import: {
        rejections: {
          whole_number_between: 'Attendu : un nombre entier de {{min}} à {{max}}.',
          not_a_whole_number: 'Attendu : un nombre entier.',
          at_least: 'Attendu : {{min}} ou plus.',
          invalid_whatsapp:
            'Un numéro WhatsApp s’écrit au format international (exemple : +216 20 123 456).',
          value_required: 'Cette case doit être remplie.',
          invalid_text: 'Texte refusé : {{max}} caractères au plus.',
          phone_length:
            'Ce numéro n’a pas le nombre de chiffres d’un téléphone de son pays (exemple : {{example}}).',
          invalid_phone:
            'Un téléphone s’écrit en chiffres, avec + devant l’indicatif (exemple : +216 71 900 868).',
          invalid_phone_digits:
            'Un téléphone compte de 6 à 15 chiffres (exemple : +216 71 900 868).',
          invalid_email:
            'Une adresse e-mail s’écrit nom@domaine.extension (exemple : compta@exemple.tn).',
        },
      },
      form: { errors: { email: 'Adresse e-mail invalide.' } },
      platform: {
        support: { same_as_phone: 'Même numéro que le téléphone' },
        grants: {
          title: 'Accès du support',
          none: 'Aucune entreprise n’ouvre son accès au support pour le moment.',
          until: 'jusqu’au {{until}}',
          open: 'Ouvrir en lecture',
        },
        numbers: { empty: 'Indiquez un nombre.' },
        demo: { title: 'Démo', bounds: 'De {{min}} à {{max}} démos', save: 'Enregistrer' },
        imports: { title: 'Imports', bounds: 'De {{min}} à {{max}} lignes', save: 'Enregistrer' },
        delays: { bounds: 'De {{min}} à {{max}} jours' },
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
          unlock: 'Déverrouiller le mot de passe',
          password_locked_until: 'Mot de passe verrouillé jusqu’au {{moment}}',
          keep: 'Ne rien changer',
          end_sessions_title: 'Terminer les sessions ?',
          end_sessions_message: '{{name}} est déconnecté sur chacun de ses appareils.',
          deactivate_title: 'Désactiver ce compte ?',
          deactivate_message: '{{name}} ne pourra plus se connecter.',
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
          owner_invitation_open:
            'Invitation de propriétaire envoyée à {{email}}, valable jusqu’au {{day}}.',
          owner_invitation_expired:
            'L’invitation de propriétaire envoyée à {{email}} a expiré le {{day}} : invitez de nouveau.',
          statuses: {
            pending: 'En attente',
            active: 'Active',
            suspended: 'Suspendue',
            refused: 'Refusée',
            closed: 'Fermée',
          },
          actions: {
            approve: 'Approuver',
            approve_title: 'Approuver cette entreprise ?',
            approve_message:
              '{{name}} s’ouvre à ses membres, et ses propriétaires en sont prévenus par courrier.',
            restore: 'Rétablir l’accès',
            reject: 'Refuser',
            reject_title: 'Refuser cette entreprise ?',
            suspend: 'Suspendre',
            suspend_title: 'Suspendre cette entreprise ?',
            reason: 'Motif (facultatif)',
            keep: 'Ne rien changer',
          },
        },
        payments: {
          title: 'Règlements à confirmer',
          none: 'Aucun règlement en attente.',
          periods: 'Périodes couvertes',
          periods_bounds: 'De {{min}} à {{max}} périodes',
          confirm: 'Confirmer',
          reject: 'Refuser',
          confirmed: 'Le règlement a été confirmé.',
          rejected: 'Le règlement a été refusé.',
          keep: 'Laisser en attente',
          confirm_title: 'Confirmer ce règlement ?',
          confirm_message: 'Le règlement de {{name}} couvre des périodes : {{periods}}.',
          reject_title: 'Refuser ce règlement ?',
          reject_message: 'L’attente que ce règlement gardait à {{name}} prend fin tout de suite.',
        },
        subscription: {
          stop: 'Ne plus gérer et effacer les conditions',
          stop_title: 'Ne plus gérer cette entreprise ?',
          stop_message: 'Les conditions de {{name}} sont effacées, et rien ne les garde.',
          keep: 'Garder ses conditions',
          stopped: '{{name}} n’est plus gérée.',
          unmanaged: 'Non géré : accès complet',
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
  periodsMax: 60,
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
  passwordLockedUntil: null,
};

const row: PlatformCompanyRow = {
  id: 'c1',
  name: 'Nouvelle Société',
  countryCode: 'TN',
  status: 'pending',
  createdAt: '2026-09-15T10:00:00+00:00',
  owners: ['nadia@example.test'],
  ownerInvitations: [],
  opened: false,
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
  // The rules the API sends with each channel, which the browser checks before the API does.
  const supportRules = signal<PlatformSupportRules | null>({
    phone: {
      pattern: apiPattern('support_phone'),
      patternReason: 'invalid_phone_digits',
      maxLength: 30,
    },
    whatsapp: {
      pattern: apiPattern('whatsapp'),
      patternReason: 'invalid_whatsapp',
      maxLength: 30,
    },
    email: {
      pattern: '/^([a-z]+@[a-z]+(?:\\.[a-z]+)+)?$/D',
      patternReason: 'invalid_email',
      maxLength: 254,
    },
    status_url: { pattern: null, patternReason: null, maxLength: 200 },
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
  const countryState: PlatformCountry = {
    key: 'platform.country',
    value: 'TN',
    choices: ['FR', 'TN'],
  };
  const country = signal<PlatformCountry | null>(countryState);
  const showComing = signal<boolean | null>(false);
  const busy = signal(false);
  const error = signal<PlatformError | null>(null);
  const accounts = signal<readonly PlatformAccountRow[]>([account]);
  const endOthers = { run: vi.fn(() => Promise.resolve(1)) };
  const companies = signal<readonly PlatformCompanyRow[]>([row]);
  const subscription = signal<PlatformSubscriptionRow | null>(null);
  const waitingPayments = signal<readonly WaitingPayment[]>([declared]);
  const openedSubscription = signal<string | null>(null);
  const demand = signal<readonly ModuleDemandRow[]>([]);
  const failed = signal<FailedMessagesRead>({ total: 0, kinds: [] });
  const waitingTotal = signal(1);
  const companiesTotal = signal(1);
  const accountsTotal = signal(1);
  const refused = signal<PlatformRefusals>({ at: null, refusals: [] });
  const facade = {
    refused: refused.asReadonly(),
    // Plain functions, which the reset of every mock below leaves as they are.
    refuse: (at: string, refusals: PlatformRefusals['refusals']) => refused.set({ at, refusals }),
    forgetRefusal: (field: string) =>
      refused.update((held) => ({
        ...held,
        refusals: held.refusals.filter((refusal) => refusal.field !== field),
      })),
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
    supportRules: supportRules.asReadonly(),
    saveSupport: vi.fn(async () => true),
    delays: delays.asReadonly(),
    saveDelays: vi.fn(async () => true),
    demo: demo.asReadonly(),
    saveDemoMost: vi.fn(async () => true),
    imports: imports.asReadonly(),
    saveImportRows: vi.fn(async () => true),
    country: country.asReadonly(),
    saveCountry: vi.fn(async () => true),
    showComing: showComing.asReadonly(),
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
  const offered = signal<readonly CompanyOption[]>([]);
  const companyFacade = {
    companies: offered.asReadonly(),
    load: vi.fn(async () => offered()),
    switchTo: vi.fn(async () => true),
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
    country.set(countryState);
    showComing.set(false);
    error.set(null);
    refused.set({ at: null, refusals: [] });
    accounts.set([account]);
    endOthers.run.mockClear();
    facade.actOnAccount.mockClear();
    companies.set([row]);
    waitingPayments.set([declared]);
    demand.set([]);
    failed.set({ total: 0, kinds: [] });
    waitingTotal.set(1);
    companiesTotal.set(1);
    accountsTotal.set(1);
    openedSubscription.set(null);
    subscription.set(null);
    offered.set([]);
    companyFacade.load.mockClear();
    companyFacade.switchTo.mockClear();
    facade.saveSupport.mockClear();
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
        { provide: TextsParts, useValue: { open: vi.fn(() => Promise.resolve()) } },
        { provide: SubscriptionFacade, useValue: payments },
        { provide: CompanyFacade, useValue: companyFacade },
        {
          provide: AuthFacade,
          useValue: { me: () => ({ user: { id: 'op1' }, company: null }), refresh: vi.fn() },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: EndOtherSessions, useValue: endOthers },
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
    expect(TestBed.inject(UnsavedChanges).count()).toBe(3);
    query('subscription-save')!.click();
    await fixture.whenStable();
    expect(TestBed.inject(UnsavedChanges).count()).toBe(0);

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
    // A country is named one way everywhere (ruled 2026-10-29): its flag and the browser's name, no text of ours.
    expect(line.textContent).toContain('\u{1F1F9}\u{1F1F3} Tunisie');
    expect(line.textContent).toContain('nadia@example.test');
    expect(query('platform-waiting-empty')).toBeNull();
  });

  it('says so when no company waits', async () => {
    waiting.set([]);

    const { query } = await render();

    expect(query('platform-waiting-empty')?.textContent).toContain('Aucune entreprise en attente.');
  });

  it('asks before it approves a waiting company, saying its owners are mailed, then says it was', async () => {
    const { fixture, query } = await render();

    const card = query('waiting-Nouvelle Société')!;
    card.querySelector<HTMLButtonElement>('[data-testid="document-action-approve"]')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'Nouvelle Société s’ouvre à ses membres, et ses propriétaires en sont prévenus',
    );
    expect(facade.approve).not.toHaveBeenCalled();

    await answer(fixture, 'run');
    await vi.waitFor(() => expect(facade.approve).toHaveBeenCalledWith('c1'));
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({
          key: 'platform.companies.done.approved',
          params: { name: 'Nouvelle Société' },
          effect: 'corrigeable',
        }),
      ]),
    );
  });

  it('asks before it refuses a waiting company, and refuses nothing when the answer is no', async () => {
    const { fixture, query } = await render();

    const card = query('waiting-Nouvelle Société')!;
    card.querySelector<HTMLButtonElement>('[data-testid="document-action-suspend"]')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(document.querySelector('[data-testid="confirm-title"]')?.textContent).toContain(
      'Refuser cette entreprise',
    );
    await answer(fixture, 'keep');
    expect(facade.reject).not.toHaveBeenCalled();

    card.querySelector<HTMLButtonElement>('[data-testid="document-action-suspend"]')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    // The reason is the operator's to give or not: the mail says it, or that none was given.
    // The question just answered « keep » may still be closing: the note is the last one drawn, as its button is.
    const notes = document.querySelectorAll<HTMLTextAreaElement>('[data-testid="confirm-note"]');
    const note = notes[notes.length - 1];
    expect(note.closest('mat-form-field')?.textContent).toContain('0 / 500');
    note.value = 'Matricule introuvable';
    note.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    await answer(fixture, 'run');
    await vi.waitFor(() =>
      expect(facade.reject).toHaveBeenCalledWith('c1', 'Matricule introuvable'),
    );
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({ key: 'platform.companies.done.rejected' }),
      ]),
    );
  });

  it('offers an open company its suspension, on its row and its sheet, which the keys and Ctrl K read', async () => {
    companies.set([{ ...row, status: 'active' }]);
    const { fixture, query } = await render('/?tab=companies&company=c1');

    const sheet = query('company-sheet-Nouvelle Société')!;
    expect(sheet.textContent).toContain('\u{1F1F9}\u{1F1F3} Tunisie');
    expect(
      query('company-Nouvelle Société')!.querySelector('[data-column="country"]')?.textContent,
    ).toContain('\u{1F1F9}\u{1F1F3} Tunisie');
    expect(sheet.querySelector('[data-testid="document-action-approve"]')).toBeNull();
    // Rare, so behind the bar's « ⋮ » rather than under the pointer.
    expect(sheet.querySelector('[data-testid="document-action-suspend"]')).toBeNull();
    expect(
      TestBed.inject(ScreenActions)
        .actions()
        .map((action) => [action.id, action.label]),
    ).toEqual([['suspend', 'platform.companies.actions.suspend']]);
    expect(query('row-action-approve-c1')).toBeNull();
    expect(query('row-more-c1')).not.toBeNull();

    TestBed.inject(ScreenActions).actions()[0].run!();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(document.querySelector('[data-testid="confirm-title"]')?.textContent).toContain(
      'Suspendre',
    );
    await answer(fixture, 'run');
    await vi.waitFor(() => expect(facade.reject).toHaveBeenCalledWith('c1', null));
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({ key: 'platform.companies.done.suspended' }),
      ]),
    );
  });

  it('says on a company sheet each owner invitation nobody used, open or run out, so a lapsed one is sent again', async () => {
    companies.set([
      {
        ...row,
        status: 'active',
        owners: [],
        ownerInvitations: [
          { email: 'open@example.test', expiresAt: '2026-10-30T10:00:00+00:00', expired: false },
          { email: 'late@example.test', expiresAt: '2026-10-01T10:00:00+00:00', expired: true },
        ],
      },
    ]);
    const { query } = await render('/?tab=companies&company=c1');

    const open = query('owner-invitation-open@example.test')!;
    // 10:00 UTC falls on the same day wherever the spec runs.
    expect(open.textContent).toContain('envoyée à open@example.test, valable jusqu’au 30/10/2026.');
    expect(open.classList).not.toContain('text-error');
    const late = query('owner-invitation-late@example.test')!;
    expect(late.textContent).toContain('envoyée à late@example.test a expiré le 01/10/2026');
    expect(late.textContent).toContain('invitez de nouveau.');
    expect(late.classList).toContain('text-error');
  });

  it('offers a suspended company its access back, and no second suspension', async () => {
    companies.set([{ ...row, status: 'suspended', opened: true }]);
    const { fixture, query } = await render('/?tab=companies&company=c1');

    const sheet = query('company-sheet-Nouvelle Société')!;
    const restore = sheet.querySelector<HTMLButtonElement>(
      '[data-testid="document-action-approve"]',
    )!;
    expect(restore.textContent).toContain('Rétablir l’accès');
    expect(query('row-action-approve-c1')).not.toBeNull();
    expect(
      TestBed.inject(ScreenActions)
        .actions()
        .map((action) => action.id),
    ).toEqual(['approve']);

    restore.click();
    fixture.detectChanges();
    await fixture.whenStable();
    await answer(fixture, 'run');
    await vi.waitFor(() => expect(facade.approve).toHaveBeenCalledWith('c1'));
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({
          key: 'platform.companies.done.restored',
          effect: 'corrigeable',
        }),
      ]),
    );
  });

  it('approves a refused company, never opened, rather than giving it back an access it never had', async () => {
    companies.set([{ ...row, status: 'suspended', opened: false }]);
    const { fixture, query } = await render('/?tab=companies&company=c1');

    const approve = query('company-sheet-Nouvelle Société')!.querySelector<HTMLButtonElement>(
      '[data-testid="document-action-approve"]',
    )!;
    expect(approve.textContent).toContain('Approuver');
    expect(approve.textContent).not.toContain('Rétablir');

    approve.click();
    fixture.detectChanges();
    await fixture.whenStable();
    await answer(fixture, 'run');
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({ key: 'platform.companies.done.approved' }),
      ]),
    );
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

  it('opens the overview on the platform’s content, its legal pages and its texts, and no other tab repeats them', async () => {
    const { query } = await render();

    const content = query('platform-content')!;
    const panel = content.closest('mat-tab-nav-panel')!;
    expect(panel.querySelector('[data-testid]')).toBe(content);
    expect(query('platform-legal-link')!.getAttribute('href')).toBe('/platform/legal');
    expect(query('platform-texts-link')!.getAttribute('href')).toBe('/platform/texts');
    expect(panel.querySelectorAll('[data-testid="platform-legal-link"]')).toHaveLength(1);
    expect(panel.querySelectorAll('[data-testid="platform-texts-link"]')).toHaveLength(1);

    const demand = await render('/?tab=demand');
    expect(demand.query('platform-legal-link')).toBeNull();
    expect(demand.query('platform-texts-link')).toBeNull();
  });

  it('shows what is coming as the installation holds it, hidden until the operator shows it', async () => {
    const { fixture, query, switchOf } = await render();

    expect(query('platform-coming')?.textContent).toContain('Montrer ce qui arrive');
    expect(switchOf('platform-show-coming').getAttribute('aria-checked')).toBe('false');

    switchOf('platform-show-coming').click();
    await fixture.whenStable();

    expect(facade.setSwitch).toHaveBeenCalledWith('presentation.show-coming', true);
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

  it('says on the field why a typed count cannot be saved: emptied, not whole, or out of its bounds', async () => {
    const { fixture, query } = await render();
    const most = query<HTMLInputElement>('platform-demo-most')!;
    expect(query('platform-demo-most-error')).toBeNull();
    expect(most.getAttribute('aria-invalid')).toBe('false');

    const says = (typed: string): string | undefined => {
      most.value = typed;
      most.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      return query('platform-demo-most-error')?.textContent?.trim();
    };
    expect(says('')).toBe('Indiquez un nombre.');
    expect(most.getAttribute('aria-invalid')).toBe('true');
    // Its outline turns red as a form's refused field does, not only the words under it.
    expect(most.closest('mat-form-field')?.classList.contains('mat-form-field-invalid')).toBe(true);
    expect(says('4.5')).toBe('Attendu : un nombre entier.');
    expect(says('4,5')).toBe('Attendu : un nombre entier.');
    expect(says('200')).toBe('Attendu : un nombre entier de 3 à 100.');
    expect(query<HTMLButtonElement>('platform-demo-save')!.disabled).toBe(true);
    expect(says('20')).toBeUndefined();
    expect(query('platform-demo')?.textContent).toContain('De 3 à 100 démos');

    const delay = query<HTMLInputElement>('platform-delay-company.reopen_days')!;
    delay.value = '';
    delay.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(query('platform-delay-company.reopen_days-error')?.textContent?.trim()).toBe(
      'Indiquez un nombre.',
    );
  });

  it('says each value the platform refused under its own field, until it is typed again', async () => {
    const { fixture, query } = await render();
    refused.set({
      at: 'settings',
      refusals: [{ field: 'support.whatsapp', reason: 'invalid_whatsapp', params: {} }],
    });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(query('field-error-whatsapp')?.textContent).toContain('+216 20 123 456');
    expect(query('field-error-phone')).toBeNull();

    const whatsapp = query<HTMLInputElement>('field-whatsapp')!;
    whatsapp.value = '+216 20 123 457';
    whatsapp.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(query('field-error-whatsapp')).toBeNull();
  });

  // Material's own moment: nothing while a value is typed, the API's sentence once the field is left, then said again
  // or taken back at every key, until the value is right.
  it('says a wrong support channel once its field is left, then at every key until it is right', async () => {
    const { fixture, query } = await render();
    const phone = query<HTMLInputElement>('field-phone')!;
    const type = (value: string) => {
      phone.value = value;
      phone.dispatchEvent(new Event('input'));
      fixture.detectChanges();
    };

    type('+216 7');
    expect(query('field-error-phone')).toBeNull();
    phone.dispatchEvent(new Event('blur'));
    fixture.detectChanges();
    // A length its country's numbers do not have is said with an example of that country.
    expect(query('field-error-phone')?.textContent).toContain('exemple : +216 ');

    type('+216 71 900 868');
    expect(query('field-error-phone')).toBeNull();
    type('------');
    expect(query('field-error-phone')?.textContent).toContain('s’écrit en chiffres');
  });

  // The browser knows the value is wrong: no save, so no password asked for one the API would refuse anyway.
  it('saves nothing while a support channel is wrong, says every one and puts the person on the first', async () => {
    const { fixture, query } = await render();
    const type = (id: string, value: string) => {
      const input = query<HTMLInputElement>(id)!;
      input.value = value;
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
    };
    type('field-phone', '------');
    type('field-email', 'a@b');

    query<HTMLButtonElement>('platform-support-save')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(facade.saveSupport).not.toHaveBeenCalled();
    expect(query('field-error-phone')?.textContent).toContain('s’écrit en chiffres');
    // The API's sentence, with its example, before the browser's own word for an e-mail.
    expect(query('field-error-email')?.textContent).toContain('nom@domaine.extension');
    expect(document.activeElement).toBe(query('field-phone'));
  });

  it("says a refused company's name on the name it was opened with, not on a sheet's owner", async () => {
    const { fixture, query } = await render('/?tab=companies');
    query<HTMLButtonElement>('platform-company-new')!.click();
    await fixture.whenStable();
    refused.set({
      at: 'new-company',
      refusals: [{ field: 'name', reason: 'value_required', params: {} }],
    });
    fixture.detectChanges();

    expect(query('platform-company-name-error')?.textContent?.trim()).toBe(
      'Cette case doit être remplie.',
    );
    expect(query('platform-company-owner-error')).toBeNull();
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

    const whatsapp = query<HTMLInputElement>('field-whatsapp')!;
    whatsapp.value = '+216 20 123 456';
    whatsapp.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(save.disabled).toBe(false);

    save.click();
    await fixture.whenStable();

    expect(facade.saveSupport).toHaveBeenCalledWith({ whatsapp: '+216 20 123 456' });
  });

  // One phone field everywhere (row 252): the support's numbers are the platform's, read by its country whatever company
  // the operator has open, and WhatsApp takes the phone's number in one press, still the operator's to change.
  it("offers WhatsApp the phone's number in one press, under the platform's country whatever company is open", async () => {
    // The platform set to Tunisia, the operator's open company French: the support's numbers follow the platform.
    TestBed.overrideProvider(AuthFacade, {
      useValue: {
        me: () => ({
          user: { id: 'op1' },
          company: { id: 'c9', countryCode: 'FR', currency: 'EUR' },
        }),
        refresh: vi.fn(),
      },
    });
    const { fixture, query } = await render();
    const type = (id: string, value: string) => {
      const input = query<HTMLInputElement>(id)!;
      input.value = value;
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
    };
    expect(query('field-phone-country')!.textContent).toContain('+216');

    type('field-phone', '71 900 868');
    query<HTMLButtonElement>('field-action-whatsapp')!.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(query('field-action-whatsapp')!.textContent).toContain('Même numéro que le téléphone');
    expect(query<HTMLInputElement>('field-whatsapp')!.value).toBe('71 900 868');
    expect(query('field-whatsapp-country')!.textContent).toContain('+216');

    type('field-whatsapp', '20 123 456');
    query<HTMLButtonElement>('platform-support-save')!.click();
    await fixture.whenStable();
    expect(facade.saveSupport).toHaveBeenCalledWith({
      phone: '+216 71 900 868',
      whatsapp: '+216 20 123 456',
    });
  });

  it("reads the support's numbers by the country the platform is set to, never a fixed one", async () => {
    country.set({ ...countryState, value: 'FR' });
    TestBed.overrideProvider(AuthFacade, {
      useValue: {
        me: () => ({
          user: { id: 'op1' },
          company: { id: 'c9', countryCode: 'TN', currency: 'TND' },
        }),
        refresh: vi.fn(),
      },
    });
    const { query } = await render();
    expect(query('field-phone-country')!.textContent).toContain('+33');
    expect(query('field-whatsapp-country')!.textContent).toContain('+33');
  });

  // The developer's ruling of 2026-10-29: the platform's country is set under « Gérer la plateforme », among the
  // countries a fiscal preset is written for, each named in the screen's language.
  it("sets the platform's country among the presets' countries, held while unsaved, and says it was saved", async () => {
    const { fixture, query } = await render();
    const select = query('platform-country-select')!;
    expect(select.textContent).toContain('Tunisie');
    expect(query<HTMLButtonElement>('platform-country-save')!.disabled).toBe(true);

    select.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(document.querySelector('[data-testid="platform-country-select-DZ"]')).toBeNull();
    document.querySelector<HTMLElement>('[data-testid="platform-country-select-FR"]')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(TestBed.inject(UnsavedChanges).count()).toBe(1);

    query<HTMLButtonElement>('platform-country-save')!.click();
    await fixture.whenStable();
    expect(facade.saveCountry).toHaveBeenCalledWith('FR');
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual(
      expect.objectContaining({ key: 'platform.country.saved' }),
    );
  });

  it('counts what is typed in every card and not saved, holding the page until the platform holds it', async () => {
    const { fixture, query } = await render();
    const unsaved = TestBed.inject(UnsavedChanges);
    expect(unsaved.count()).toBe(0);
    const type = (id: string, value: string) => {
      const input = query<HTMLInputElement>(id)!;
      input.value = value;
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
    };

    type('field-whatsapp', '+216 20 123 456');
    type('platform-delay-company.reopen_days', '0');
    type('platform-demo-most', '20');
    type('platform-import-rows', '1200');
    expect(unsaved.count()).toBe(4);
    const leaving = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(leaving);
    expect(leaving.defaultPrevented).toBe(true);

    type('platform-demo-most', '10');
    expect(unsaved.count()).toBe(3);
    support.set({ phone: '', whatsapp: '+216 20 123 456', email: '', status_url: '' });
    delays.set([...delayRows]);
    imports.set({ rows: { ...importsState.rows!, value: 1200 } });
    fixture.detectChanges();
    expect(unsaved.count()).toBe(0);
  });

  it('counts a company being opened or an owner being invited until it goes', async () => {
    const { fixture, query } = await render('/?tab=companies');
    const unsaved = TestBed.inject(UnsavedChanges);
    query<HTMLButtonElement>('platform-company-new')!.click();
    fixture.detectChanges();
    const name = query<HTMLInputElement>('platform-company-name')!;
    name.value = 'Quincaillerie';
    name.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(unsaved.count()).toBe(1);
  });

  it('draws a field per delay the platform declares, labelled and bounded as declared', async () => {
    const { query } = await render();
    const deletion = query<HTMLInputElement>('platform-delay-account.deletion_days')!;

    // A text field with the digits' keyboard: a number field would hand « 1,5 » over as empty. Its bounds are said
    // under it before they are crossed.
    expect(deletion.type).toBe('text');
    expect(deletion.inputMode).toBe('numeric');
    expect(deletion.value).toBe('7');
    expect(deletion.closest('mat-form-field')?.querySelector('mat-hint')?.textContent?.trim()).toBe(
      'De 1 à 90 jours',
    );
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
    const status = query<HTMLInputElement>('field-status_url')!;
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

  /** Opens a row's « ⋮ » and takes one of its entries, as a person does; the menu lives in the overlay. */
  async function fromRowMenu(
    fixture: { whenStable(): Promise<unknown>; detectChanges(): void },
    rowId: string,
    actionId: string,
  ): Promise<void> {
    (document.querySelector(`[data-testid="row-more-${rowId}"]`) as HTMLElement).click();
    fixture.detectChanges();
    await fixture.whenStable();
    const entries = document.querySelectorAll(`[data-testid="row-menu-${actionId}-${rowId}"]`);
    (entries[entries.length - 1] as HTMLElement).click();
    fixture.detectChanges();
    await fixture.whenStable();
  }

  /** Answers the open question; the last dialog, since one just closed can still be in the page for a frame. */
  async function answer(
    fixture: { whenStable(): Promise<unknown>; detectChanges(): void },
    choice: 'run' | 'keep',
  ): Promise<void> {
    const buttons = document.querySelectorAll(`[data-testid="confirm-${choice}"]`);
    (buttons[buttons.length - 1] as HTMLElement).click();
    fixture.detectChanges();
    await fixture.whenStable();
  }

  it('asks before it ends the sessions of an active account, naming the person, as final', async () => {
    const { fixture, query } = await render('/?tab=accounts');

    const line = query('account-nadia@acme.test')!;
    expect(line.textContent).toContain('Nadia');
    expect(line.textContent).toContain('Actif');
    // Nothing that ends access is drawn under the pointer: it waits behind « ⋮ ».
    expect(line.querySelector('[data-testid^="row-action-end-sessions-"]')).toBeNull();
    expect(line.querySelector('[data-testid^="row-action-deactivate-"]')).toBeNull();
    expect(line.querySelector('[data-testid^="row-action-reactivate-"]')).toBeNull();

    await fromRowMenu(fixture, 'u1', 'end-sessions');
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'Nadia est déconnecté',
    );
    expect(document.querySelector('[data-testid="confirm-kind"]')?.getAttribute('data-kind')).toBe(
      'definitif',
    );
    await answer(fixture, 'keep');
    expect(facade.actOnAccount).not.toHaveBeenCalled();

    await fromRowMenu(fixture, 'u1', 'end-sessions');
    await answer(fixture, 'run');
    await vi.waitFor(() => expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'end-sessions'));
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({
          key: 'platform.accounts.done.ended',
          params: { name: 'Nadia' },
          effect: 'definitif',
        }),
      ]),
    );
  });

  it('asks before it deactivates an account, saying it can be reactivated, and says it was', async () => {
    const { fixture } = await render('/?tab=accounts');

    await fromRowMenu(fixture, 'u1', 'deactivate');
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'Nadia ne pourra plus se connecter',
    );
    await answer(fixture, 'run');
    await vi.waitFor(() => expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'deactivate'));
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({
          key: 'platform.accounts.done.deactivated',
          effect: 'corrigeable',
        }),
      ]),
    );
  });

  it('says nothing was done when the platform refuses an account action', async () => {
    facade.actOnAccount.mockResolvedValue(false);
    const { fixture } = await render('/?tab=accounts');

    await fromRowMenu(fixture, 'u1', 'deactivate');
    await answer(fixture, 'run');
    await vi.waitFor(() => expect(facade.actOnAccount).toHaveBeenCalled());
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);
  });

  // The API refuses an operator's deactivating their own account, and ending their own sessions here would sign this
  // tab out too: their row offers the others ended, as « Mon compte » does, and nothing else.
  it("offers one's own row the other sessions ended, never a deactivation or this tab's own end", async () => {
    accounts.set([
      { ...account, id: 'op1', email: 'op@twes.local', displayName: 'Opératrice' },
      account,
    ]);
    const { fixture } = await render('/?tab=accounts');

    // One action left on one's own row: it stands on the row, and no « ⋮ » holds anything else.
    const offered = (rowId: string, id: string) =>
      document.querySelector(
        `[data-testid="row-menu-${id}-${rowId}"], [data-testid="row-action-${id}-${rowId}"]`,
      ) !== null;
    expect(document.querySelector('[data-testid="row-more-op1"]')).toBeNull();
    expect(offered('op1', 'end-other-sessions')).toBe(true);
    expect(offered('op1', 'end-sessions')).toBe(false);
    expect(offered('op1', 'deactivate')).toBe(false);

    (
      document.querySelector('[data-testid="row-action-end-other-sessions-op1"]') as HTMLElement
    ).click();
    await fixture.whenStable();
    expect(endOthers.run).toHaveBeenCalledOnce();
    expect(facade.actOnAccount).not.toHaveBeenCalled();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27 }));

    (document.querySelector('[data-testid="row-more-u1"]') as HTMLElement).click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(offered('u1', 'end-other-sessions')).toBe(false);
    expect(offered('u1', 'end-sessions')).toBe(true);
    expect(offered('u1', 'deactivate')).toBe(true);
  });

  it('only reactivates a deactivated account, which has no session left to end, at once', async () => {
    accounts.set([{ ...account, active: false }]);

    const { fixture, query } = await render('/?tab=accounts');

    expect(query('account-nadia@acme.test')!.textContent).toContain('Désactivé');
    expect(query('row-more-u1')).toBeNull();
    query<HTMLButtonElement>('row-action-reactivate-u1')!.click();
    await fixture.whenStable();
    expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'reactivate');
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({ key: 'platform.accounts.done.reactivated', kind: 'success' }),
      ]),
    );
  });

  it("says until when an owner's password is locked and lifts it from « ⋮ », saying it was", async () => {
    accounts.set([{ ...account, passwordLockedUntil: '2026-10-29T10:15:00+00:00' }]);
    const { fixture, query } = await render('/?tab=accounts');

    expect(query('account-lock-u1')!.textContent).toContain('Mot de passe verrouillé jusqu’au');
    // Occasional: it waits behind « ⋮ » rather than taking a slot on the row.
    expect(query('row-action-unlock-u1')).toBeNull();
    await fromRowMenu(fixture, 'u1', 'unlock');
    await vi.waitFor(() => expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'unlock'));
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({
          key: 'platform.accounts.done.unlocked',
          params: { name: 'Nadia' },
          kind: 'success',
        }),
      ]),
    );
  });

  // A member's lock is their owner's to lift, from the company's members: the platform offers only an owner's.
  it('offers no unlock for an account owning no company, nor for one whose password is not locked', async () => {
    accounts.set([
      {
        ...account,
        passwordLockedUntil: '2026-10-29T10:15:00+00:00',
        companies: [{ id: 'c2', name: 'Globex', role: 'Comptable' }],
      },
      { ...account, id: 'u2', email: 'sami@acme.test', displayName: 'Sami' },
    ]);
    const { fixture, query } = await render('/?tab=accounts');

    expect(query('account-lock-u1')).not.toBeNull();
    expect(query('account-lock-u2')).toBeNull();
    for (const id of ['u1', 'u2']) {
      (document.querySelector(`[data-testid="row-more-${id}"]`) as HTMLElement).click();
      fixture.detectChanges();
      await fixture.whenStable();
      expect(document.querySelector(`[data-testid="row-menu-unlock-${id}"]`)).toBeNull();
      expect(document.querySelector(`[data-testid="row-action-unlock-${id}"]`)).toBeNull();
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27 }));
      fixture.detectChanges();
      await fixture.whenStable();
    }
  });

  // One list of countries, the presets' as the platform's country setting offers them, and no country written in the
  // screen: a new company starts on the country the platform is set to.
  it("starts a new company on the platform's country, offering the presets' countries alone", async () => {
    country.set({ key: 'platform.country', value: 'FR', choices: ['FR', 'MA', 'TN'] });
    const { fixture, query } = await render('/?tab=companies');

    query<HTMLButtonElement>('platform-company-new')!.click();
    await fixture.whenStable();
    fixture.detectChanges();
    const name = query<HTMLInputElement>('platform-company-name')!;
    name.value = 'Globex';
    name.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('platform-company-country')!.click();
    await fixture.whenStable();
    fixture.detectChanges();
    const offered = Array.from(
      document.body.querySelectorAll('[data-testid^="platform-company-country-option-"]'),
    ).map((option) => option.getAttribute('data-testid')!.slice(-2));
    expect(offered).toEqual(['FR', 'MA', 'TN']);
    expect(
      document.body.querySelector('[data-testid="platform-company-country-option-MA"]')
        ?.textContent,
    ).toContain('\u{1F1F2}\u{1F1E6} Maroc');
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27 }));
    fixture.detectChanges();
    await fixture.whenStable();
    const owner = query<HTMLInputElement>('platform-company-owner')!;
    owner.value = 'nadia@example.test';
    owner.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('platform-company-create')!.click();
    await fixture.whenStable();

    expect(facade.openCompany).toHaveBeenCalledWith('Globex', 'FR', 'nadia@example.test');
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

  // A state shown by absence: no subscription is a company licensing does not manage, with full access.
  it('says a company with no subscription is not managed and has full access, on its line and its sheet', async () => {
    const { query } = await render('/?tab=companies&company=c1');

    const cell = query('company-Nouvelle Société')!.querySelector('[data-column="subscription"]');
    expect(cell?.textContent).toContain('Non géré : accès complet');
    expect(query('standing-Nouvelle Société')?.textContent).toContain('Non géré : accès complet');
  });

  // Row 210: the API asks the operator to prove who is there before it reads what was sent, so a wrong value the
  // screen knew was refused only after the step-up.
  it('says a new company with no name and a wrong owner address under their fields, and sends nothing', async () => {
    const { fixture, query } = await render('/?tab=companies');
    query<HTMLButtonElement>('platform-company-new')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    const owner = query<HTMLInputElement>('platform-company-owner')!;
    owner.value = '------';
    owner.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('platform-company-create')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(facade.openCompany).not.toHaveBeenCalled();
    expect(query('platform-company-name-error')?.textContent?.trim()).toBe(
      'Cette case doit être remplie.',
    );
    expect(query('platform-company-owner-error')?.textContent?.trim()).toBe(
      'Une adresse e-mail s’écrit nom@domaine.extension (exemple : compta@exemple.tn).',
    );
    expect(document.activeElement).toBe(query('platform-company-name'));

    // A name past what the API keeps is said with its bound.
    const name = query<HTMLInputElement>('platform-company-name')!;
    name.value = 'a'.repeat(161);
    name.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('platform-company-create')!.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(query('platform-company-name-error')?.textContent?.trim()).toBe(
      'Texte refusé : 160 caractères au plus.',
    );
    expect(facade.openCompany).not.toHaveBeenCalled();
  });

  it("says a wrong owner address on a company's sheet under it, and sends nothing", async () => {
    const { fixture, query } = await render('/?tab=companies&company=c1');
    const address = query<HTMLInputElement>('owner-email-Nouvelle Société')!;
    address.value = 'karim@';
    address.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('invite-owner-Nouvelle Société')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(facade.inviteOwner).not.toHaveBeenCalled();
    expect(query('owner-email-Nouvelle Société-error')?.textContent?.trim()).toBe(
      'Une adresse e-mail s’écrit nom@domaine.extension (exemple : compta@exemple.tn).',
    );
    expect(document.activeElement).toBe(address);
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

  // A company suspended before it was ever opened was refused, which its row and its sheet say; one its owners closed
  // down is named so too, never by a raw key.
  it('names a refused company and a closed one as such, in the list and on the sheet', async () => {
    companies.set([
      { ...row, status: 'suspended', opened: false },
      { ...row, id: 'c2', name: 'Ancienne', status: 'suspended', opened: true },
      { ...row, id: 'c3', name: 'Fermée SA', status: 'closed', opened: true },
    ]);
    const { query } = await render('/?tab=companies&company=c1');
    const status = (name: string) =>
      query(`company-${name}`)?.querySelector('[data-column="status"]')?.textContent?.trim();

    expect(status('Nouvelle Société')).toBe('Refusée');
    expect(status('Ancienne')).toBe('Suspendue');
    expect(status('Fermée SA')).toBe('Fermée');
    expect(query('company-sheet-Nouvelle Société')?.textContent).toContain('Refusée');
    expect(query('company-sheet-Nouvelle Société')?.textContent).not.toContain('Suspendue');
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

    // Asked first, naming the company and the periods it carries forward; kept, nothing is confirmed.
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'Le règlement de Nouvelle Société',
    );
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'périodes : 2',
    );
    expect(document.querySelector('[data-testid="confirm-kind"]')?.getAttribute('data-kind')).toBe(
      'corrigeable',
    );
    await answer(fixture, 'keep');
    expect(payments.confirm).not.toHaveBeenCalled();

    query<HTMLButtonElement>('payment-confirm-p1')!.click();
    await fixture.whenStable();
    await answer(fixture, 'run');

    await vi.waitFor(() => expect(payments.confirm).toHaveBeenCalledWith('p1', 2, null));
    // The companies list is read again, since a confirmation moves the company's covered time.
    await vi.waitFor(() => expect(facade.load).toHaveBeenCalled());
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({ key: 'platform.payments.confirmed', effect: 'corrigeable' }),
      ]),
    );
  });

  // A count the API would refuse was put back to one unseen, and the dialog confirmed one period.
  it('says under the periods why they cannot be confirmed, and confirms nothing until they can', async () => {
    const { fixture, query } = await render('/?tab=payments');
    const periods = query<HTMLInputElement>('payment-periods-p1')!;
    const says = (typed: string): string | undefined => {
      periods.value = typed;
      periods.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      return query('payment-periods-p1-error')?.textContent?.trim();
    };

    // Its bounds are said before they are crossed, under the field, as every bounded number's are.
    expect(periods.closest('mat-form-field')?.querySelector('mat-hint')?.textContent?.trim()).toBe(
      'De 1 à 60 périodes',
    );
    expect(says('0')).toBe('Attendu : un nombre entier de 1 à 60.');
    expect(periods.closest('mat-form-field')?.classList.contains('mat-form-field-invalid')).toBe(
      true,
    );
    expect(says('1.5')).toBe('Attendu : un nombre entier.');
    // Written with a French comma, it is what it is, a number not whole, never an empty field.
    expect(says('1,5')).toBe('Attendu : un nombre entier.');
    expect(says('‎2')).toBeUndefined();
    expect(says('')).toBe('Indiquez un nombre.');
    // Past the most the API covers at once, said here rather than after the proof of who is at the screen.
    expect(says('61')).toBe('Attendu : un nombre entier de 1 à 60.');
    query<HTMLButtonElement>('payment-confirm-p1')!.click();
    await fixture.whenStable();
    expect(document.querySelector('[data-testid="confirm-message"]')).toBeNull();
    expect(document.activeElement).toBe(periods);

    expect(says('3')).toBeUndefined();
  });

  it('asks before it rejects a payment, naming the company, without asking for periods', async () => {
    const { fixture, query } = await render('/?tab=payments');

    query<HTMLButtonElement>('payment-reject-p1')!.click();
    await fixture.whenStable();
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'gardait à Nouvelle Société',
    );
    await answer(fixture, 'run');

    await vi.waitFor(() => expect(payments.reject).toHaveBeenCalledWith('p1', null));
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({ key: 'platform.payments.rejected', effect: 'corrigeable' }),
      ]),
    );
  });

  it('asks before it stops managing a company, naming it and saying its terms are erased for good', async () => {
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
    facade.openSubscription.mockImplementation(async (companyId: string) => {
      openedSubscription.set(companyId);
      subscription.set(held);
    });
    const { fixture, query } = await render('/?tab=companies&company=c1');
    query('subscription-Nouvelle Société')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(query('subscription-stop')?.textContent).toContain(
      'Ne plus gérer et effacer les conditions',
    );
    query('subscription-stop')!.click();
    await fixture.whenStable();
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'Les conditions de Nouvelle Société',
    );
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'rien ne les garde',
    );
    expect(document.querySelector('[data-testid="confirm-kind"]')?.getAttribute('data-kind')).toBe(
      'definitif',
    );
    await answer(fixture, 'keep');
    expect(facade.stopSubscription).not.toHaveBeenCalled();

    query('subscription-stop')!.click();
    await fixture.whenStable();
    await answer(fixture, 'run');

    // Erased on the version the panel read, and said as final, naming the company.
    await vi.waitFor(() => expect(facade.stopSubscription).toHaveBeenCalledWith('c1', 3));
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({
          key: 'platform.subscription.stopped',
          params: { name: 'Nouvelle Société' },
          effect: 'definitif',
        }),
      ]),
    );
  });

  it('says the terms were changed since, and erases nothing, when another operator set them first', async () => {
    facade.openSubscription.mockImplementation(async (companyId: string) => {
      openedSubscription.set(companyId);
      subscription.set({
        companyId: 'c1',
        periodCount: 1,
        periodUnit: 'month',
        trialEndsOn: null,
        paidThrough: null,
        price: null,
        currency: null,
        graceDays: null,
        unpaidMode: null,
        holdDays: null,
        stage: 'trial',
        access: 'full',
        coveredUntil: '2026-12-31T23:59:59+01:00',
        graceEndsAt: '2027-01-03T23:59:59+01:00',
        daysLeft: 70,
        updatedAt: '2026-10-17T10:00:00+00:00',
        version: 2,
      });
    });
    facade.stopSubscription.mockImplementationOnce(async () => {
      error.set('record_changed');
      return false;
    });
    const { fixture, query } = await render('/?tab=companies&company=c1');
    query('subscription-Nouvelle Société')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    query('subscription-stop')!.click();
    await fixture.whenStable();
    await answer(fixture, 'run');

    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        { kind: 'failure', key: 'live.saved_since', params: undefined },
      ]),
    );
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
  // docs/SPEC.md § 7, support access as a bug: an operator in no company finds here every company opened to support.
  describe('« Accès du support »', () => {
    const grant = (
      id: string,
      name: string,
      until: string | null,
      role = 'support',
    ): CompanyOption => ({
      id,
      name,
      status: 'active',
      role,
      pinned: false,
      logoVersion: null,
      supportAccessUntil: until,
      supportAccessReason: null,
    });

    it('lists the companies support may read, and opens one read-only from there', async () => {
      offered.set([
        grant('c9', 'Demo', null, 'owner'),
        grant('c3', 'Atlas', '2026-10-27T10:00:00+01:00'),
      ]);
      const { fixture, query } = await render();
      fixture.detectChanges();

      expect(query('platform-grant-Demo')).toBeNull();
      expect(query('platform-grant-Atlas')?.textContent).toContain('Atlas');
      expect(query('platform-grant-Atlas')?.textContent).toContain('jusqu’au');
      query<HTMLButtonElement>('platform-grant-open-Atlas')!.click();
      await fixture.whenStable();

      expect(companyFacade.switchTo).toHaveBeenCalledWith('c3');
      expect(TestBed.inject(Router).url).toBe('/');
    });

    it('says what the owner wrote is wrong beside the company, and nothing when they wrote nothing', async () => {
      // Workflow hunt SUP-1: the operator opened the company blind and phoned the owner back.
      offered.set([
        {
          ...grant('c3', 'Atlas', '2026-10-27T10:00:00+01:00'),
          supportAccessReason: 'La facture du 3 mars',
        },
        grant('c4', 'Carthage', '2026-10-27T10:00:00+01:00'),
      ]);
      const { fixture, query } = await render();
      fixture.detectChanges();

      expect(query('platform-grant-reason-Atlas')?.textContent).toContain('La facture du 3 mars');
      expect(query('platform-grant-reason-Carthage')).toBeNull();
    });

    it('says when no company opens its access', async () => {
      const { fixture, query } = await render();
      fixture.detectChanges();

      expect(query('platform-grants-none')?.textContent).toContain('Aucune entreprise');
    });

    it.each([
      'support_grant',
      'company',
      'invitation',
      'membership',
      'subscription',
      'payment_declaration',
    ])(
      'reads the page again when %s changes in any company, as the platform hears it',
      async (kind) => {
        // An owner accepting the operator's invitation left « invitée » on /platform until a reload (hunt A1).
        await render();
        facade.refresh.mockClear();
        payments.loadWaiting.mockClear();
        companyFacade.load.mockClear();

        await announceSaved(kind, 'x1', null);

        expect(facade.refresh).toHaveBeenCalledTimes(1);
        expect(payments.loadWaiting).toHaveBeenCalledTimes(1);
        expect(companyFacade.load).toHaveBeenCalledTimes(1);
      },
    );
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

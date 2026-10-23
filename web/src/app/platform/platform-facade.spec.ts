// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { StepUp } from '../shared/step-up/step-up';
import { PlatformApi, PlatformRefused } from './platform-api';
import { PlatformFacade } from './platform-facade';
import type {
  PlatformAccountRow,
  PlatformAccountSearch,
  PlatformCompanyRow,
  PlatformCompanySearch,
} from './platform-types';

const account: PlatformAccountRow = {
  id: 'u1',
  email: 'nadia@acme.test',
  displayName: 'Nadia',
  active: true,
  platformOperator: false,
  createdAt: '2026-09-15T10:00:00+00:00',
  companies: [{ id: 'c1', name: 'Acme', role: 'owner' }],
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

const LIST: PlatformCompanySearch = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  statuses: [],
  countryCodes: [],
  order: null,
};
const ACCOUNTS: PlatformAccountSearch = {
  page: 1,
  itemsPerPage: 25,
  q: 'acme',
  active: null,
  platformOperator: null,
  order: null,
};

// The proof dialog is answered by the test; `retrying` itself is StepUp's own.
const stepUp = Object.assign(Object.create(StepUp.prototype) as StepUp, {
  request: vi.fn<(intro?: string) => Promise<boolean>>(),
});

const SETTINGS = {
  signup: { enabled: false, approvalRequired: true },
  support: { phone: '+216 71 000 000', whatsapp: '', email: '', status_url: '' },
  delays: [
    { key: 'account.deletion_days', labelKey: 'l.a', value: 7, min: 1, max: 90 },
    { key: 'company.reopen_days', labelKey: 'l.c', value: 7, min: 1, max: 90 },
  ],
  demo: {
    enabled: true,
    most: { key: 'demo.sandboxes_max', labelKey: 'l.d', value: 10, min: 3, max: 100 },
  },
  imports: {
    rows: { key: 'import.max_rows', labelKey: 'l.i', value: 5000, min: 1, max: 5000 },
  },
  versions: {
    'support.phone': 4,
    'support.whatsapp': 0,
    'support.email': 2,
    'support.status_url': 0,
    'account.deletion_days': 0,
    'company.reopen_days': 1,
    'demo.enabled': 0,
    'demo.sandboxes_max': 2,
    'import.max_rows': 3,
  },
};

describe('PlatformFacade', () => {
  const api = {
    approve: vi.fn(),
    reject: vi.fn(),
    settings: vi.fn(),
    setSwitch: vi.fn(),
    saveSettings: vi.fn(),
    accounts: vi.fn(),
    actOnAccount: vi.fn(),
    companies: vi.fn(),
    createCompany: vi.fn(),
    inviteOwner: vi.fn(),
    subscription: vi.fn(),
    setSubscription: vi.fn(),
    stopSubscription: vi.fn(),
    moduleDemand: vi.fn(),
    failedMessages: vi.fn(),
  };
  let facade: PlatformFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.settings.mockResolvedValue(SETTINGS);
    api.accounts.mockResolvedValue({ rows: [account], total: 1 });
    api.companies.mockResolvedValue({ rows: [row], total: 1 });
    api.moduleDemand.mockResolvedValue([
      { key: 'quotes', labelKey: 'modules.quotes', planned: 'v1', companies: 2 },
    ]);
    api.failedMessages.mockResolvedValue({ total: 1, kinds: [{ kind: 'SignupAsked', count: 1 }] });
    TestBed.configureTestingModule({
      providers: [
        { provide: PlatformApi, useValue: api },
        { provide: StepUp, useValue: stepUp },
      ],
    });
    facade = TestBed.inject(PlatformFacade);
  });

  it('finds accounts, and shows an account as the platform answers it after an action', async () => {
    await facade.loadAccounts(ACCOUNTS);
    expect(api.accounts).toHaveBeenCalledWith(ACCOUNTS);
    expect(facade.accounts()).toEqual([account]);
    expect(facade.accountsTotal()).toBe(1);

    // The answer to an action carries no companies, which the row keeps.
    api.actOnAccount.mockResolvedValue({ ...account, active: false, companies: [] });
    expect(await facade.actOnAccount('u1', 'deactivate')).toBe(true);

    expect(api.actOnAccount).toHaveBeenCalledWith('u1', 'deactivate');
    expect(facade.accounts()).toEqual([{ ...account, active: false }]);
  });

  it('keeps an account as it was when the platform refuses', async () => {
    await facade.loadAccounts(ACCOUNTS);
    api.actOnAccount.mockRejectedValue(new PlatformRefused('own_account'));

    expect(await facade.actOnAccount('u1', 'deactivate')).toBe(false);

    expect(facade.accounts()).toEqual([account]);
    expect(facade.error()).toBe('own_account');
    expect(facade.busy()).toBe(false);
  });

  it("opens a company with its country's currency, language and time zone, then invites its first owner", async () => {
    await facade.load();
    await facade.loadCompanies(LIST);
    const opened = { ...row, id: 'c9', name: 'Globex' };
    api.createCompany.mockResolvedValue('c9');
    api.inviteOwner.mockResolvedValue(undefined);
    api.companies.mockResolvedValue({ rows: [row, opened], total: 2 });

    expect(await facade.openCompany('Globex', 'FR', 'nadia@example.test')).toBe(true);

    expect(api.createCompany).toHaveBeenCalledWith({
      name: 'Globex',
      countryCode: 'FR',
      currency: 'EUR',
      timezone: 'Europe/Paris',
    });
    expect(api.inviteOwner).toHaveBeenCalledWith('c9', 'nadia@example.test');
    expect(facade.companies()).toEqual([row, opened]);
    expect(facade.companiesTotal()).toBe(2);
    expect(facade.waiting()).toEqual([row, opened]);
    expect(facade.busy()).toBe(false);
  });

  it('invites nobody when the company could not be opened', async () => {
    await facade.load();
    await facade.loadCompanies(LIST);
    api.createCompany.mockRejectedValue(new PlatformRefused('name_taken'));

    expect(await facade.openCompany('Globex', 'TN', 'nadia@example.test')).toBe(false);

    expect(api.inviteOwner).not.toHaveBeenCalled();
    expect(facade.error()).toBe('name_taken');
    expect(facade.companies()).toEqual([row]);
  });

  it('invites an owner into a listed company and reads the page shown again', async () => {
    await facade.load();
    await facade.loadCompanies(LIST);
    api.inviteOwner.mockResolvedValue(undefined);
    api.companies.mockClear();

    expect(await facade.inviteOwner('c1', 'nadia@example.test')).toBe(true);
    expect(api.inviteOwner).toHaveBeenCalledWith('c1', 'nadia@example.test');
    expect(api.companies).toHaveBeenCalledWith(LIST);

    api.inviteOwner.mockRejectedValue(new PlatformRefused('already_member'));
    expect(await facade.inviteOwner('c1', 'nadia@example.test')).toBe(false);
    expect(facade.error()).toBe('already_member');
  });

  it('loads the overview: the waiting companies, the switches, the counts, the demand and what failed', async () => {
    api.companies.mockImplementation(async (search: PlatformCompanySearch) =>
      search.statuses.includes('pending') ? { rows: [row], total: 7 } : { rows: [row], total: 130 },
    );
    api.accounts.mockResolvedValue({ rows: [account], total: 41 });

    await facade.load();

    expect(facade.waiting()).toEqual([row]);
    expect(facade.waitingTotal()).toBe(7);
    expect(facade.companyCount()).toBe(130);
    expect(facade.accountCount()).toBe(41);
    expect(facade.signup()).toEqual({ enabled: false, approvalRequired: true });
    expect(facade.demand()).toEqual([
      { key: 'quotes', labelKey: 'modules.quotes', planned: 'v1', companies: 2 },
    ]);
    expect(facade.failed()).toEqual({ total: 1, kinds: [{ kind: 'SignupAsked', count: 1 }] });
    expect(facade.error()).toBeNull();
  });

  it('approves a company and reads the waiting list again', async () => {
    await facade.load();
    api.approve.mockResolvedValue({ ...row, status: 'active' });
    api.companies.mockResolvedValue({ rows: [], total: 0 });

    expect(await facade.approve('c1')).toBe(true);

    expect(api.approve).toHaveBeenCalledWith('c1');
    expect(facade.waiting()).toEqual([]);
  });

  it('rejects a company and reads the waiting list again', async () => {
    await facade.load();
    api.reject.mockResolvedValue({ ...row, status: 'suspended' });
    api.companies.mockResolvedValue({ rows: [], total: 0 });

    expect(await facade.reject('c1')).toBe(true);

    expect(api.reject).toHaveBeenCalledWith('c1');
    expect(facade.waiting()).toEqual([]);
  });

  it('keeps the list and names the refusal when a decision fails', async () => {
    await facade.load();
    api.approve.mockRejectedValue(new PlatformRefused('not_found'));

    expect(await facade.approve('c1')).toBe(false);

    expect(facade.error()).toBe('not_found');
    expect(facade.waiting()).toEqual([row]);
  });

  it('keeps only the answer to the last company search, whatever order they come back in', async () => {
    const slow: { answer?: (page: { rows: PlatformCompanyRow[]; total: number }) => void } = {};
    api.companies.mockImplementationOnce(() => new Promise((resolve) => (slow.answer = resolve)));
    const first = facade.loadCompanies({ ...LIST, q: 'a' });
    api.companies.mockResolvedValueOnce({ rows: [{ ...row, name: 'Acme' }], total: 1 });
    await facade.loadCompanies({ ...LIST, q: 'ac' });

    slow.answer?.({
      rows: [
        { ...row, name: 'Alpha' },
        { ...row, name: 'Apex' },
      ],
      total: 2,
    });
    await first;

    expect(facade.companies().map((company) => company.name)).toEqual(['Acme']);
    expect(facade.companiesTotal()).toBe(1);
  });

  it('turns a signup switch and shows the value the platform now holds', async () => {
    await facade.load();
    api.setSwitch.mockResolvedValue(undefined);

    await facade.setSwitch('signup.enabled', true);

    expect(api.setSwitch).toHaveBeenCalledWith('signup.enabled', true);
    expect(facade.signup()).toEqual({ enabled: true, approvalRequired: true });
    expect(facade.demo()?.enabled).toBe(true);
  });

  it('turns the demo off and shows it off, its count kept', async () => {
    await facade.load();
    api.setSwitch.mockResolvedValue(undefined);

    await facade.setSwitch('demo.enabled', false);

    expect(api.setSwitch).toHaveBeenCalledWith('demo.enabled', false);
    expect(facade.demo()).toEqual({ ...SETTINGS.demo, enabled: false });
    expect(facade.signup()).toEqual(SETTINGS.signup);
  });

  it('saves how many demos may exist at once with the version read, and shows what the platform then holds', async () => {
    await facade.load();
    api.saveSettings.mockResolvedValue(undefined);
    api.settings.mockResolvedValueOnce({
      ...SETTINGS,
      demo: { ...SETTINGS.demo, most: { ...SETTINGS.demo.most, value: 20 } },
    });

    expect(await facade.saveDemoMost(20)).toBe(true);

    expect(api.saveSettings.mock.calls).toEqual([
      [[{ key: 'demo.sandboxes_max', value: 20, version: 2 }]],
    ]);
    expect(facade.demo()?.most?.value).toBe(20);
  });

  it('saves how many rows an imported file may hold with the version read, and shows what the platform then holds', async () => {
    await facade.load();
    expect(facade.imports()).toEqual(SETTINGS.imports);
    api.saveSettings.mockResolvedValue(undefined);
    api.settings.mockResolvedValueOnce({
      ...SETTINGS,
      imports: { rows: { ...SETTINGS.imports.rows, value: 1200 } },
    });

    expect(await facade.saveImportRows(1200)).toBe(true);

    expect(api.saveSettings.mock.calls).toEqual([
      [[{ key: 'import.max_rows', value: 1200, version: 3 }]],
    ]);
    expect(facade.imports()?.rows?.value).toBe(1200);
  });

  it('saves the support channels changed, and only those, together, then shows what the platform holds', async () => {
    await facade.load();
    expect(facade.support()).toEqual(SETTINGS.support);
    api.saveSettings.mockResolvedValue(undefined);
    const after = { whatsapp: '+216 20 123 456', email: 'help@twes.test' };
    api.settings.mockResolvedValueOnce({ ...SETTINGS, support: { ...SETTINGS.support, ...after } });

    expect(await facade.saveSupport(after)).toBe(true);

    expect(api.saveSettings.mock.calls).toEqual([
      [
        [
          { key: 'support.whatsapp', value: '+216 20 123 456', version: 0 },
          { key: 'support.email', value: 'help@twes.test', version: 2 },
        ],
      ],
    ]);
    expect(facade.support()).toEqual({ ...SETTINGS.support, ...after });
  });

  it('keeps the fields a save was refused on for the cards, and forgets one typed again', async () => {
    await facade.load();
    const whatsapp = { field: 'support.whatsapp', reason: 'invalid_whatsapp', params: {} };
    const email = { field: 'support.email', reason: 'invalid_email', params: {} };
    api.saveSettings.mockRejectedValue(new PlatformRefused('invalid', [whatsapp, email]));

    expect(await facade.saveSupport({ whatsapp: '20 123', email: 'help' })).toBe(false);

    expect(facade.error()).toBe('invalid');
    expect(facade.refused()).toEqual({ at: 'settings', refusals: [whatsapp, email] });
    facade.forgetRefusal('support.whatsapp');
    expect(facade.refused()).toEqual({ at: 'settings', refusals: [email] });

    // A company opened afterwards starts from nothing refused, and is refused where it was written.
    const name = { field: 'name', reason: 'value_required', params: {} };
    api.createCompany.mockRejectedValue(new PlatformRefused('invalid', [name]));
    expect(await facade.openCompany('', 'TN', 'nadia@example.test')).toBe(false);
    expect(facade.refused()).toEqual({ at: 'new-company', refusals: [name] });
  });

  it('saves the delays changed, and shows what the platform then holds', async () => {
    await facade.load();
    api.saveSettings.mockResolvedValue(undefined);
    api.settings.mockResolvedValueOnce({
      ...SETTINGS,
      delays: [SETTINGS.delays[0], { ...SETTINGS.delays[1], value: 14 }],
    });

    expect(await facade.saveDelays({ 'company.reopen_days': 14 })).toBe(true);

    expect(api.saveSettings.mock.calls).toEqual([
      [[{ key: 'company.reopen_days', value: 14, version: 1 }]],
    ]);
    expect(facade.delays()?.map((delay) => [delay.key, delay.value])).toEqual([
      ['account.deletion_days', 7],
      ['company.reopen_days', 14],
    ]);
  });

  it('keeps a delay as it was when the platform refuses it', async () => {
    await facade.load();
    api.saveSettings.mockRejectedValue(new PlatformRefused('refused'));

    expect(await facade.saveDelays({ 'account.deletion_days': 200 })).toBe(false);

    expect(facade.delays()?.[0]?.value).toBe(7);
  });

  it('keeps the support channels as they were when the platform refuses one', async () => {
    await facade.load();
    api.saveSettings.mockRejectedValue(new PlatformRefused('refused'));

    expect(await facade.saveSupport({ whatsapp: '20 123 456' })).toBe(false);

    expect(facade.support()).toEqual(SETTINGS.support);
    expect(facade.error()).toBe('refused');
  });

  it('after a save refused as made since, shows what the platform holds and saves on its versions next', async () => {
    await facade.load();
    api.saveSettings.mockRejectedValueOnce(new PlatformRefused('record_changed'));
    const since = {
      ...SETTINGS,
      support: { ...SETTINGS.support, email: 'ops@twes.test' },
      versions: { ...SETTINGS.versions, 'support.email': 3 },
    };
    api.settings.mockResolvedValue(since);

    expect(await facade.saveSupport({ email: 'help@twes.test' })).toBe(false);

    expect(facade.error()).toBe('record_changed');
    expect(facade.support()).toEqual(since.support);

    api.saveSettings.mockResolvedValue(undefined);
    expect(await facade.saveSupport({ email: 'help@twes.test' })).toBe(true);
    expect(api.saveSettings).toHaveBeenLastCalledWith([
      { key: 'support.email', value: 'help@twes.test', version: 3 },
    ]);
  });

  it('leaves a switch as it was when the platform refuses it', async () => {
    await facade.load();
    api.setSwitch.mockRejectedValue(new PlatformRefused('network'));

    await facade.setSwitch('signup.enabled', true);

    expect(facade.signup()).toEqual({ enabled: false, approvalRequired: true });
    expect(facade.error()).toBe('network');
  });

  it('opens one subscription at a time, saves its terms and reads the companies again', async () => {
    const terms = {
      periodCount: 1,
      periodUnit: 'month' as const,
      trialEndsOn: null,
      paidThrough: '2026-12-31',
      price: null,
      currency: null,
      graceDays: null,
      unpaidMode: null,
      holdDays: null,
    };
    const held = {
      companyId: 'c1',
      ...terms,
      stage: 'paid' as const,
      access: 'full' as const,
      coveredUntil: '2026-12-31T23:59:59+01:00',
      graceEndsAt: '2027-01-07T23:59:59+01:00',
      daysLeft: 105,
      updatedAt: '2026-09-17T10:00:00+00:00',
      version: 1,
    };
    api.subscription.mockResolvedValue(null);
    api.setSubscription.mockResolvedValue(held);
    api.companies.mockResolvedValue({ rows: [row], total: 1 });

    await facade.loadCompanies(LIST);
    api.companies.mockClear();

    await facade.openSubscription('c1');
    expect(facade.openedSubscription()).toBe('c1');
    expect(facade.subscription()).toBeNull();

    expect(await facade.saveSubscription('c1', terms, 0)).toBe(true);
    expect(api.setSubscription).toHaveBeenCalledWith('c1', terms, 0);
    expect(facade.subscription()).toEqual(held);
    expect(api.companies).toHaveBeenCalledWith(LIST);

    // Another operator set terms since: refused, and what they hold is read again for the next save.
    const theirs = { ...held, graceDays: 4, version: 2 };
    api.setSubscription.mockRejectedValue(new PlatformRefused('record_changed'));
    api.subscription.mockResolvedValue(theirs);
    expect(await facade.saveSubscription('c1', terms, 1)).toBe(false);
    expect(facade.error()).toBe('record_changed');
    expect(facade.subscription()).toEqual(theirs);

    facade.closeSubscription();
    expect(facade.openedSubscription()).toBeNull();
  });

  it('stops managing a company, and names a refusal instead of throwing', async () => {
    api.stopSubscription.mockResolvedValue(undefined);
    api.companies.mockResolvedValue({ rows: [], total: 0 });
    expect(await facade.stopSubscription('c1', 2)).toBe(true);
    expect(api.stopSubscription).toHaveBeenCalledWith('c1', 2);
    expect(facade.subscription()).toBeNull();

    api.setSubscription.mockRejectedValue(new PlatformRefused('refused'));
    expect(
      await facade.saveSubscription(
        'c1',
        {
          periodCount: 1,
          periodUnit: 'month',
          trialEndsOn: null,
          paidThrough: null,
          price: null,
          currency: null,
          graceDays: null,
          unpaidMode: null,
          holdDays: null,
        },
        0,
      ),
    ).toBe(false);
    expect(facade.error()).toBe('refused');
    expect(api.subscription).not.toHaveBeenCalled();
  });

  it('reads the terms again when another operator changed them before they were erased', async () => {
    const theirs = {
      companyId: 'c1',
      periodCount: 1,
      periodUnit: 'month' as const,
      trialEndsOn: null,
      paidThrough: '2026-12-31',
      price: '800.000',
      currency: 'TND',
      graceDays: null,
      unpaidMode: null,
      holdDays: null,
      stage: 'paid' as const,
      access: 'full' as const,
      coveredUntil: '2026-12-31T23:59:59+01:00',
      graceEndsAt: '2027-01-07T23:59:59+01:00',
      daysLeft: 105,
      updatedAt: '2026-09-17T10:00:00+00:00',
      version: 3,
    };
    api.stopSubscription.mockRejectedValue(new PlatformRefused('record_changed'));
    api.subscription.mockResolvedValue(theirs);

    expect(await facade.stopSubscription('c1', 2)).toBe(false);

    expect(facade.error()).toBe('record_changed');
    expect(facade.subscription()).toEqual(theirs);
  });

  // docs/SPEC.md § 7, the step-up list: every platform write waits for the operator to prove who is at the screen.
  it('asks the operator for the proof the platform wants, then acts on the account', async () => {
    stepUp.request.mockReset().mockResolvedValue(true);
    await facade.loadAccounts(ACCOUNTS);
    api.actOnAccount
      .mockRejectedValueOnce(new PlatformRefused('step_up_required'))
      .mockResolvedValueOnce({ ...account, active: false, companies: [] });

    expect(await facade.actOnAccount('u1', 'deactivate')).toBe(true);

    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_change');
    expect(api.actOnAccount).toHaveBeenCalledTimes(2);
    expect(facade.accounts()).toEqual([{ ...account, active: false }]);
  });

  it('changes nothing when the operator does not give the proof', async () => {
    stepUp.request.mockReset().mockResolvedValue(false);
    await facade.load();
    api.saveSettings.mockRejectedValue(new PlatformRefused('step_up_required'));

    expect(await facade.saveSupport({ whatsapp: '20 123 456' })).toBe(false);

    expect(api.saveSettings).toHaveBeenCalledTimes(1);
    expect(facade.support()).toEqual(SETTINGS.support);
    expect(facade.error()).toBe('step_up_required');
  });
});

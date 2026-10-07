// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
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
  status: null,
  countryCode: null,
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

describe('PlatformFacade', () => {
  const api = {
    approve: vi.fn(),
    reject: vi.fn(),
    signup: vi.fn(),
    setSignup: vi.fn(),
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
    api.signup.mockResolvedValue({ enabled: false, approvalRequired: true });
    api.accounts.mockResolvedValue({ rows: [account], total: 1 });
    api.companies.mockResolvedValue({ rows: [row], total: 1 });
    api.moduleDemand.mockResolvedValue([
      { key: 'quotes', labelKey: 'modules.quotes', planned: 'v1', companies: 2 },
    ]);
    api.failedMessages.mockResolvedValue({ total: 1, kinds: [{ kind: 'SignupAsked', count: 1 }] });
    TestBed.configureTestingModule({ providers: [{ provide: PlatformApi, useValue: api }] });
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
      locale: 'fr',
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
      search.status === 'pending' ? { rows: [row], total: 7 } : { rows: [row], total: 130 },
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
    api.setSignup.mockResolvedValue(undefined);

    await facade.setSignup('signup.enabled', true);

    expect(api.setSignup).toHaveBeenCalledWith('signup.enabled', true);
    expect(facade.signup()).toEqual({ enabled: true, approvalRequired: true });
  });

  it('leaves a switch as it was when the platform refuses it', async () => {
    await facade.load();
    api.setSignup.mockRejectedValue(new PlatformRefused('network'));

    await facade.setSignup('signup.enabled', true);

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
    };
    api.subscription.mockResolvedValue(null);
    api.setSubscription.mockResolvedValue(held);
    api.companies.mockResolvedValue({ rows: [row], total: 1 });

    await facade.loadCompanies(LIST);
    api.companies.mockClear();

    await facade.openSubscription('c1');
    expect(facade.openedSubscription()).toBe('c1');
    expect(facade.subscription()).toBeNull();

    expect(await facade.saveSubscription('c1', terms)).toBe(true);
    expect(facade.subscription()).toEqual(held);
    expect(api.companies).toHaveBeenCalledWith(LIST);

    facade.closeSubscription();
    expect(facade.openedSubscription()).toBeNull();
  });

  it('stops managing a company, and names a refusal instead of throwing', async () => {
    api.stopSubscription.mockResolvedValue(undefined);
    api.companies.mockResolvedValue({ rows: [], total: 0 });
    expect(await facade.stopSubscription('c1')).toBe(true);
    expect(facade.subscription()).toBeNull();

    api.setSubscription.mockRejectedValue(new PlatformRefused('refused'));
    expect(
      await facade.saveSubscription('c1', {
        periodCount: 1,
        periodUnit: 'month',
        trialEndsOn: null,
        paidThrough: null,
        price: null,
        currency: null,
        graceDays: null,
        unpaidMode: null,
        holdDays: null,
      }),
    ).toBe(false);
    expect(facade.error()).toBe('refused');
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { PlatformApi, PlatformRefused } from './platform-api';
import { PlatformFacade } from './platform-facade';
import type { PlatformAccountRow, PlatformCompanyRow } from './platform-types';

const account: PlatformAccountRow = {
  id: 'u1',
  email: 'nadia@acme.test',
  displayName: 'Nadia',
  active: true,
  platformOperator: false,
  createdAt: '2026-09-15T10:00:00+00:00',
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

describe('PlatformFacade', () => {
  const api = {
    waitingCompanies: vi.fn(),
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
  };
  let facade: PlatformFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.waitingCompanies.mockResolvedValue([row]);
    api.signup.mockResolvedValue({ enabled: false, approvalRequired: true });
    api.accounts.mockResolvedValue([account]);
    api.companies.mockResolvedValue([row]);
    TestBed.configureTestingModule({ providers: [{ provide: PlatformApi, useValue: api }] });
    facade = TestBed.inject(PlatformFacade);
  });

  it('finds accounts, and shows an account as the platform answers it after an action', async () => {
    await facade.findAccounts('acme');
    expect(api.accounts).toHaveBeenCalledWith('acme');
    expect(facade.accounts()).toEqual([account]);

    api.actOnAccount.mockResolvedValue({ ...account, active: false });
    expect(await facade.actOnAccount('u1', 'deactivate')).toBe(true);

    expect(api.actOnAccount).toHaveBeenCalledWith('u1', 'deactivate');
    expect(facade.accounts()).toEqual([{ ...account, active: false }]);
  });

  it('keeps an account as it was when the platform refuses', async () => {
    await facade.findAccounts('acme');
    api.actOnAccount.mockRejectedValue(new PlatformRefused('own_account'));

    expect(await facade.actOnAccount('u1', 'deactivate')).toBe(false);

    expect(facade.accounts()).toEqual([account]);
    expect(facade.error()).toBe('own_account');
    expect(facade.busy()).toBe(false);
  });

  it("opens a company with its country's currency, language and time zone, then invites its first owner", async () => {
    await facade.load();
    const opened = { ...row, id: 'c9', name: 'Globex' };
    api.createCompany.mockResolvedValue('c9');
    api.inviteOwner.mockResolvedValue(undefined);
    api.companies.mockResolvedValue([row, opened]);
    api.waitingCompanies.mockResolvedValue([row, opened]);

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
    expect(facade.waiting()).toEqual([row, opened]);
    expect(facade.busy()).toBe(false);
  });

  it('invites nobody when the company could not be opened', async () => {
    await facade.load();
    api.createCompany.mockRejectedValue(new PlatformRefused('name_taken'));

    expect(await facade.openCompany('Globex', 'TN', 'nadia@example.test')).toBe(false);

    expect(api.inviteOwner).not.toHaveBeenCalled();
    expect(facade.error()).toBe('name_taken');
    expect(facade.companies()).toEqual([row]);
  });

  it('invites an owner into a listed company and reads the companies again', async () => {
    await facade.load();
    api.inviteOwner.mockResolvedValue(undefined);

    expect(await facade.inviteOwner('c1', 'nadia@example.test')).toBe(true);
    expect(api.inviteOwner).toHaveBeenCalledWith('c1', 'nadia@example.test');
    expect(api.companies).toHaveBeenCalledTimes(2);

    api.inviteOwner.mockRejectedValue(new PlatformRefused('already_member'));
    expect(await facade.inviteOwner('c1', 'nadia@example.test')).toBe(false);
    expect(facade.error()).toBe('already_member');
  });

  it('loads the waiting companies and the signup switches together', async () => {
    await facade.load();

    expect(facade.waiting()).toEqual([row]);
    expect(facade.signup()).toEqual({ enabled: false, approvalRequired: true });
    expect(api.accounts).toHaveBeenCalledWith('');
    expect(facade.accounts()).toEqual([account]);
    expect(facade.companies()).toEqual([row]);
    expect(facade.error()).toBeNull();
  });

  it('approves a company and reads the waiting list again', async () => {
    await facade.load();
    api.approve.mockResolvedValue({ ...row, status: 'active' });
    api.waitingCompanies.mockResolvedValue([]);

    expect(await facade.approve('c1')).toBe(true);

    expect(api.approve).toHaveBeenCalledWith('c1');
    expect(facade.waiting()).toEqual([]);
  });

  it('rejects a company and reads the waiting list again', async () => {
    await facade.load();
    api.reject.mockResolvedValue({ ...row, status: 'suspended' });
    api.waitingCompanies.mockResolvedValue([]);

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
    api.companies.mockResolvedValue([row]);

    await facade.openSubscription('c1');
    expect(facade.openedSubscription()).toBe('c1');
    expect(facade.subscription()).toBeNull();

    expect(await facade.saveSubscription('c1', terms)).toBe(true);
    expect(facade.subscription()).toEqual(held);
    expect(api.companies).toHaveBeenCalled();

    facade.closeSubscription();
    expect(facade.openedSubscription()).toBeNull();
  });

  it('stops managing a company, and names a refusal instead of throwing', async () => {
    api.stopSubscription.mockResolvedValue(undefined);
    api.companies.mockResolvedValue([]);
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
      }),
    ).toBe(false);
    expect(facade.error()).toBe('refused');
  });
});

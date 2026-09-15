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
  };
  let facade: PlatformFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.waitingCompanies.mockResolvedValue([row]);
    api.signup.mockResolvedValue({ enabled: false, approvalRequired: true });
    api.accounts.mockResolvedValue([account]);
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

  it('loads the waiting companies and the signup switches together', async () => {
    await facade.load();

    expect(facade.waiting()).toEqual([row]);
    expect(facade.signup()).toEqual({ enabled: false, approvalRequired: true });
    expect(api.accounts).toHaveBeenCalledWith('');
    expect(facade.accounts()).toEqual([account]);
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
});

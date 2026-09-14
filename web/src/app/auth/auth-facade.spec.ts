// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthApi, AuthRefused } from './auth-api';
import { AuthFacade } from './auth-facade';
import type { SignedInState } from './auth-types';

const owner: SignedInState = {
  user: {
    id: '1',
    email: 'owner@example.test',
    displayName: 'Owner',
    locale: 'fr',
    isPlatformOperator: false,
  },
  company: {
    id: 'c1',
    name: 'Demo',
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
    status: 'active',
    role: 'owner',
  },
  permissions: ['*'],
  modules: ['customers'],
};

describe('AuthFacade', () => {
  const api = { me: vi.fn(), login: vi.fn(), logout: vi.fn() };
  let facade: AuthFacade;

  beforeEach(() => {
    api.me.mockReset();
    api.login.mockReset();
    api.logout.mockReset();
    TestBed.configureTestingModule({ providers: [{ provide: AuthApi, useValue: api }] });
    facade = TestBed.inject(AuthFacade);
  });

  it('starts unknown, and load() resolves to authenticated when the API knows the session', async () => {
    expect(facade.status()).toBe('unknown');
    api.me.mockResolvedValue(owner);
    expect(await facade.load()).toEqual(owner);
    expect(facade.status()).toBe('authenticated');
    expect(facade.me()?.company?.name).toBe('Demo');
  });

  it('load() resolves to anonymous when the API refuses', async () => {
    api.me.mockRejectedValue(new AuthRefused('authentication_required'));
    expect(await facade.load()).toBeNull();
    expect(facade.status()).toBe('anonymous');
    expect(facade.isAuthenticated()).toBe(false);
  });

  it('login() keeps the signed-in state, or reports the API error code', async () => {
    api.login.mockResolvedValue(owner);
    expect(await facade.login({ email: 'owner@example.test', password: 'pw' })).toEqual({
      ok: true,
      state: owner,
    });
    expect(facade.isAuthenticated()).toBe(true);

    api.login.mockRejectedValue(new AuthRefused('account_locked'));
    expect(await facade.login({ email: 'owner@example.test', password: 'wrong' })).toEqual({
      ok: false,
      error: 'account_locked',
    });
    expect(facade.status()).toBe('anonymous');
  });

  it('logout() forgets the session even if the API fails', async () => {
    api.me.mockResolvedValue(owner);
    await facade.load();
    api.logout.mockRejectedValue(new Error('500'));
    await facade.logout();
    expect(facade.status()).toBe('anonymous');
    expect(facade.me()).toBeNull();
  });

  it('hasPermission() honours the wildcard and exact strings only', async () => {
    expect(facade.hasPermission('invoice.read')).toBe(false);
    api.me.mockResolvedValue({ ...owner, permissions: ['invoice.read'] });
    await facade.load();
    expect(facade.hasPermission('invoice.read')).toBe(true);
    expect(facade.hasPermission('invoice.issue')).toBe(false);

    api.me.mockResolvedValue(owner);
    await facade.load();
    expect(facade.hasPermission('anything.at_all')).toBe(true);
  });

  it('hasModule() names only the modules the working company has on', async () => {
    expect(facade.hasModule('customers')).toBe(false);
    api.me.mockResolvedValue(owner);
    await facade.load();
    expect(facade.hasModule('customers')).toBe(true);
    expect(facade.hasModule('products')).toBe(false);
  });
});

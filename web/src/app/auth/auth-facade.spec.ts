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
  mfa: { enrolled: true, required: false },
};

describe('AuthFacade', () => {
  const api = {
    me: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    verifySecondFactor: vi.fn(),
    beginTotpEnrolment: vi.fn(),
    confirmTotpEnrolment: vi.fn(),
  };
  let facade: AuthFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
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
      status: 'signed_in',
      state: owner,
    });
    expect(facade.isAuthenticated()).toBe(true);

    api.login.mockRejectedValue(new AuthRefused('account_locked'));
    expect(await facade.login({ email: 'owner@example.test', password: 'wrong' })).toEqual({
      status: 'refused',
      error: 'account_locked',
    });
    expect(facade.status()).toBe('anonymous');
  });

  it('login() reports a second factor owed without signing in, and the code then signs in', async () => {
    api.login.mockResolvedValue('second_factor');
    expect(await facade.login({ email: 'owner@example.test', password: 'pw' })).toEqual({
      status: 'second_factor',
    });
    expect(facade.isAuthenticated()).toBe(false);

    api.verifySecondFactor.mockRejectedValue(new AuthRefused('invalid_code'));
    expect(await facade.verifySecondFactor('000000')).toEqual({
      status: 'refused',
      error: 'invalid_code',
    });
    expect(facade.isAuthenticated()).toBe(false);

    api.verifySecondFactor.mockResolvedValue(owner);
    expect(await facade.verifySecondFactor('123456')).toEqual({
      status: 'signed_in',
      state: owner,
    });
    expect(facade.isAuthenticated()).toBe(true);
  });

  it('needsEnrolment() holds only for an account required to enrol that has not', async () => {
    expect(facade.needsEnrolment()).toBe(false);
    api.me.mockResolvedValue({ ...owner, mfa: { enrolled: false, required: true } });
    await facade.load();
    expect(facade.needsEnrolment()).toBe(true);

    api.me.mockResolvedValue({ ...owner, mfa: { enrolled: false, required: false } });
    await facade.load();
    expect(facade.needsEnrolment()).toBe(false);
  });

  it('confirming an enrolment hands back the recovery codes and marks the account enrolled', async () => {
    api.me.mockResolvedValue({ ...owner, mfa: { enrolled: false, required: true } });
    await facade.load();
    const enrolment = { secret: 'JBSWY3DPEHPK3PXP', provisioningUri: 'otpauth://totp/x' };
    api.beginTotpEnrolment.mockResolvedValue(enrolment);
    expect(await facade.beginTotpEnrolment()).toEqual({ ok: true, enrolment });

    api.confirmTotpEnrolment.mockRejectedValue(new AuthRefused('invalid_code'));
    expect(await facade.confirmTotpEnrolment('000000')).toEqual({
      ok: false,
      error: 'invalid_code',
    });
    expect(facade.needsEnrolment()).toBe(true);

    api.confirmTotpEnrolment.mockResolvedValue(['aaaa-bbbb']);
    expect(await facade.confirmTotpEnrolment('123456')).toEqual({
      ok: true,
      recoveryCodes: ['aaaa-bbbb'],
    });
    expect(facade.me()?.mfa.enrolled).toBe(true);
    expect(facade.needsEnrolment()).toBe(false);
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

// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthApi, AuthRefused } from './auth-api';
import { AuthFacade } from './auth-facade';
import { PasskeyClient } from './passkey-client';
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
  mfa: { enrolled: true, required: false, totp: true, passkeys: 0 },
};

describe('AuthFacade', () => {
  const api = {
    me: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    verifySecondFactor: vi.fn(),
    beginTotpEnrolment: vi.fn(),
    confirmTotpEnrolment: vi.fn(),
    regenerateRecoveryCodes: vi.fn(),
    recoveryCodesPasskeyOptions: vi.fn(),
    regenerateRecoveryCodesWithPasskey: vi.fn(),
    passkeyRegistrationOptions: vi.fn(),
    registerPasskey: vi.fn(),
    listPasskeys: vi.fn(),
    removePasskey: vi.fn(),
    passkeyLoginOptions: vi.fn(),
    finishPasskeyLogin: vi.fn(),
  };
  const client = { supported: vi.fn(), create: vi.fn(), get: vi.fn() };
  let facade: AuthFacade;

  beforeEach(() => {
    [...Object.values(api), ...Object.values(client)].forEach((fn) => fn.mockReset());
    TestBed.configureTestingModule({
      providers: [
        { provide: AuthApi, useValue: api },
        { provide: PasskeyClient, useValue: client },
      ],
    });
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

  it('companyClosed() holds only for a member whose working company is not active', async () => {
    const pending = { ...owner, company: { ...owner.company!, status: 'pending' } };
    const operator = { ...pending, user: { ...owner.user, isPlatformOperator: true } };
    const cases: [SignedInState, boolean][] = [
      [owner, false],
      [pending, true],
      [{ ...owner, company: { ...owner.company!, status: 'suspended' } }, true],
      [operator, false],
      [{ ...owner, company: null }, false],
    ];
    for (const [state, closed] of cases) {
      api.me.mockResolvedValue(state);
      await facade.load();
      expect(facade.companyClosed()).toBe(closed);
    }
  });

  it('needsEnrolment() holds only for an account required to enrol that has not', async () => {
    expect(facade.needsEnrolment()).toBe(false);
    api.me.mockResolvedValue({
      ...owner,
      mfa: { enrolled: false, required: true, totp: false, passkeys: 0 },
    });
    await facade.load();
    expect(facade.needsEnrolment()).toBe(true);

    api.me.mockResolvedValue({
      ...owner,
      mfa: { enrolled: false, required: false, totp: false, passkeys: 0 },
    });
    await facade.load();
    expect(facade.needsEnrolment()).toBe(false);
  });

  it('confirming an enrolment hands back the recovery codes and marks the account enrolled', async () => {
    api.me.mockResolvedValue({
      ...owner,
      mfa: { enrolled: false, required: true, totp: false, passkeys: 0 },
    });
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

  it('regenerating the recovery codes hands the new set back, or the refusal', async () => {
    api.regenerateRecoveryCodes.mockResolvedValue(['eeeee-fffff']);
    expect(await facade.regenerateRecoveryCodes('123456')).toEqual({
      ok: true,
      recoveryCodes: ['eeeee-fffff'],
    });

    api.regenerateRecoveryCodes.mockRejectedValue(new AuthRefused('invalid_code'));
    expect(await facade.regenerateRecoveryCodes('000000')).toEqual({
      ok: false,
      error: 'invalid_code',
    });
  });

  it('replaces the recovery codes against a passkey the browser produces, or says why not', async () => {
    api.recoveryCodesPasskeyOptions.mockResolvedValue({ challenge: 'rst' });
    client.get.mockRejectedValueOnce(new DOMException('Not allowed.', 'NotAllowedError'));
    expect(await facade.regenerateRecoveryCodesWithPasskey()).toEqual({
      ok: false,
      error: 'passkey_cancelled',
    });
    expect(api.regenerateRecoveryCodesWithPasskey).not.toHaveBeenCalled();

    client.get.mockResolvedValue({ id: 'cred' });
    api.regenerateRecoveryCodesWithPasskey.mockResolvedValue(['eeeee-fffff']);
    expect(await facade.regenerateRecoveryCodesWithPasskey()).toEqual({
      ok: true,
      recoveryCodes: ['eeeee-fffff'],
    });
    expect(client.get).toHaveBeenCalledWith({ challenge: 'rst' });
    expect(api.regenerateRecoveryCodesWithPasskey).toHaveBeenCalledWith({ id: 'cred' });
  });

  const laptop = {
    id: 'p1',
    name: 'Work laptop',
    createdAt: '2026-09-15T10:00:00+00:00',
    lastUsedAt: null,
  };

  it('lists the passkeys, or the refusal', async () => {
    api.listPasskeys.mockResolvedValue([laptop]);
    expect(await facade.listPasskeys()).toEqual({ ok: true, passkeys: [laptop] });

    api.listPasskeys.mockRejectedValue(new AuthRefused('authentication_required'));
    expect(await facade.listPasskeys()).toEqual({ ok: false, error: 'authentication_required' });
  });

  it('adds a passkey the browser creates against the API options, and counts it as a factor', async () => {
    api.me.mockResolvedValue({
      ...owner,
      mfa: { enrolled: false, required: true, totp: false, passkeys: 0 },
    });
    await facade.load();
    api.passkeyRegistrationOptions.mockResolvedValue({ challenge: 'abc' });
    client.create.mockResolvedValue({ id: 'cred' });
    api.registerPasskey.mockResolvedValue({ passkey: laptop, recoveryCodes: ['aaaaa-bbbbb'] });

    expect(await facade.addPasskey('Work laptop')).toEqual({
      ok: true,
      passkey: laptop,
      recoveryCodes: ['aaaaa-bbbbb'],
    });
    expect(client.create).toHaveBeenCalledWith({ challenge: 'abc' });
    expect(api.registerPasskey).toHaveBeenCalledWith('Work laptop', { id: 'cred' });
    expect(facade.me()?.mfa).toEqual({ enrolled: true, required: true, totp: false, passkeys: 1 });
    expect(facade.needsEnrolment()).toBe(false);
  });

  it('reports a passkey the browser did not produce as cancelled, and registers nothing', async () => {
    api.passkeyRegistrationOptions.mockResolvedValue({ challenge: 'abc' });
    client.create.mockRejectedValue(new DOMException('Not allowed.', 'NotAllowedError'));

    expect(await facade.addPasskey('Work laptop')).toEqual({
      ok: false,
      error: 'passkey_cancelled',
    });
    expect(api.registerPasskey).not.toHaveBeenCalled();

    client.create.mockRejectedValue(new DOMException('Already registered.', 'InvalidStateError'));
    expect(await facade.addPasskey('Work laptop')).toEqual({
      ok: false,
      error: 'passkey_already_registered',
    });
  });

  it('removes a passkey and stops counting it, or says why it stays', async () => {
    api.me.mockResolvedValue({
      ...owner,
      mfa: { enrolled: true, required: false, totp: false, passkeys: 1 },
    });
    await facade.load();
    api.removePasskey.mockRejectedValueOnce(new AuthRefused('mfa_last_factor'));
    expect(await facade.removePasskey('p1')).toEqual({ ok: false, error: 'mfa_last_factor' });
    expect(facade.me()?.mfa.passkeys).toBe(1);

    api.removePasskey.mockResolvedValue(undefined);
    expect(await facade.removePasskey('p1')).toEqual({ ok: true });
    expect(facade.me()?.mfa).toEqual({
      enrolled: false,
      required: false,
      totp: false,
      passkeys: 0,
    });
  });

  it('finishes a pending login with a passkey, or reports the refusal', async () => {
    api.passkeyLoginOptions.mockResolvedValue({ challenge: 'xyz' });
    client.get.mockResolvedValue({ id: 'cred' });
    api.finishPasskeyLogin.mockRejectedValueOnce(new AuthRefused('invalid_passkey'));
    expect(await facade.signInWithPasskey()).toEqual({
      status: 'refused',
      error: 'invalid_passkey',
    });
    expect(facade.isAuthenticated()).toBe(false);

    api.finishPasskeyLogin.mockResolvedValue(owner);
    expect(await facade.signInWithPasskey()).toEqual({ status: 'signed_in', state: owner });
    expect(client.get).toHaveBeenCalledWith({ challenge: 'xyz' });
    expect(api.finishPasskeyLogin).toHaveBeenCalledWith({ id: 'cred' });
    expect(facade.isAuthenticated()).toBe(true);
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

  it('isPlatformOperator() holds only for a signed-in platform operator', async () => {
    expect(facade.isPlatformOperator()).toBe(false);
    api.me.mockResolvedValue(owner);
    await facade.load();
    expect(facade.isPlatformOperator()).toBe(false);

    api.me.mockResolvedValue({ ...owner, user: { ...owner.user, isPlatformOperator: true } });
    await facade.load();
    expect(facade.isPlatformOperator()).toBe(true);
  });

  it('hasModule() names only the modules the working company has on', async () => {
    expect(facade.hasModule('customers')).toBe(false);
    api.me.mockResolvedValue(owner);
    await facade.load();
    expect(facade.hasModule('customers')).toBe(true);
    expect(facade.hasModule('products')).toBe(false);
  });
});

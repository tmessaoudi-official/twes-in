// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import type { Me } from '../api/types.gen';
import { AuthApi, AuthRefused } from './auth-api';

const owner: Me = {
  user: {
    id: '019...',
    email: 'owner@example.test',
    displayName: 'Owner',
    locale: 'fr',
    isPlatformOperator: false,
  },
  company: {
    id: '019...',
    name: 'Demo',
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
    status: 'active',
    role: 'owner',
    access: 'read_only',
    subscription: {
      stage: 'unpaid',
      coveredUntil: '2026-08-31T23:59:59+01:00',
      graceEndsAt: '2026-09-07T23:59:59+01:00',
      daysLeft: null,
    },
  },
  permissions: ['*'],
  mfa: { enrolled: false, required: false, totp: false, passkeys: 0 },
  modules: ['customers'],
};

describe('AuthApi', () => {
  let api: AuthApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(AuthApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('maps the Me document to the signed-in state', async () => {
    const pending = api.me();
    http.expectOne({ method: 'GET', url: '/api/auth/me' }).flush(owner);
    const state = await pending;
    expect(state.user.displayName).toBe('Owner');
    expect(state.company?.role).toBe('owner');
    expect(state.permissions).toEqual(['*']);
    expect(state.modules).toEqual(['customers']);
  });

  it('posts the credentials as the API expects them', async () => {
    const pending = api.login({ email: 'owner@example.test', password: 'pw' });
    const request = http.expectOne({ method: 'POST', url: '/api/auth/login' });
    expect(request.request.body).toEqual({ email: 'owner@example.test', password: 'pw' });
    request.flush({ ...owner, company: null, permissions: [] });
    expect(await pending).toMatchObject({ company: null });
  });

  it('reads where the working company stands in its subscription, and full access without one', async () => {
    const pending = api.me();
    http
      .expectOne('/api/auth/me')
      .flush({ ...owner, company: { ...owner.company, access: 'full', subscription: null } });

    const state = await pending;
    expect(state?.company?.access).toBe('full');
    expect(state?.company?.subscription).toBeNull();
  });

  it('turns a refusal into the API error code, and no answer at all into network', async () => {
    const refused = api.login({ email: 'owner@example.test', password: 'wrong' });
    http
      .expectOne('/api/auth/login')
      .flush({ error: 'account_locked' }, { status: 401, statusText: 'Unauthorized' });
    await expect(refused).rejects.toEqual(new AuthRefused('account_locked'));

    const unreachable = api.me();
    http.expectOne('/api/auth/me').error(new ProgressEvent('error'));
    await expect(unreachable).rejects.toEqual(new AuthRefused('network'));
  });

  it('carries the second-factor status of the account', async () => {
    const pending = api.me();
    http
      .expectOne('/api/auth/me')
      .flush({ ...owner, mfa: { enrolled: true, required: true, totp: false, passkeys: 2 } });
    expect((await pending).mfa).toEqual({
      enrolled: true,
      required: true,
      totp: false,
      passkeys: 2,
    });
  });

  it('answers second_factor when the password was right but a code is still owed', async () => {
    const pending = api.login({ email: 'owner@example.test', password: 'pw' });
    http.expectOne('/api/auth/login').flush({ mfaRequired: true });
    expect(await pending).toBe('second_factor');
  });

  it('finishes a login with a code, or reports the refusal', async () => {
    const pending = api.verifySecondFactor('123456');
    const request = http.expectOne({ method: 'POST', url: '/api/auth/mfa/verify' });
    expect(request.request.body).toEqual({ code: '123456' });
    request.flush(owner);
    expect((await pending).user.email).toBe('owner@example.test');

    const refused = api.verifySecondFactor('000000');
    http
      .expectOne('/api/auth/mfa/verify')
      .flush({ error: 'invalid_code' }, { status: 401, statusText: 'Unauthorized' });
    await expect(refused).rejects.toEqual(new AuthRefused('invalid_code'));
  });

  it('starts and confirms an authenticator enrolment', async () => {
    const enrolment = {
      secret: 'JBSWY3DPEHPK3PXP',
      provisioningUri: 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP',
    };
    const begun = api.beginTotpEnrolment();
    http.expectOne({ method: 'POST', url: '/api/auth/mfa/enrolment' }).flush(enrolment);
    expect(await begun).toEqual(enrolment);

    const confirmed = api.confirmTotpEnrolment('123456');
    const request = http.expectOne({ method: 'POST', url: '/api/auth/mfa/enrolment/confirm' });
    expect(request.request.body).toEqual({ code: '123456' });
    request.flush({ recoveryCodes: ['aaaa-bbbb', 'cccc-dddd'] });
    expect(await confirmed).toEqual(['aaaa-bbbb', 'cccc-dddd']);

    const wrong = api.confirmTotpEnrolment('000000');
    http
      .expectOne('/api/auth/mfa/enrolment/confirm')
      .flush({ error: 'invalid_code' }, { status: 422, statusText: 'Unprocessable Content' });
    await expect(wrong).rejects.toEqual(new AuthRefused('invalid_code'));
  });

  it('replaces the recovery codes when given a current authenticator code', async () => {
    const pending = api.regenerateRecoveryCodes('123456');
    const request = http.expectOne({ method: 'POST', url: '/api/auth/mfa/recovery-codes' });
    expect(request.request.body).toEqual({ code: '123456' });
    request.flush({ recoveryCodes: ['eeeee-fffff'] });
    expect(await pending).toEqual(['eeeee-fffff']);

    const refused = api.regenerateRecoveryCodes('aaaaa-bbbbb');
    http
      .expectOne('/api/auth/mfa/recovery-codes')
      .flush({ error: 'invalid_code' }, { status: 422, statusText: 'Unprocessable Content' });
    await expect(refused).rejects.toEqual(new AuthRefused('invalid_code'));
  });

  it('replaces the recovery codes against a passkey', async () => {
    const options = api.recoveryCodesPasskeyOptions();
    http
      .expectOne({ method: 'POST', url: '/api/auth/mfa/recovery-codes/passkey/options' })
      .flush({ challenge: 'rst' });
    expect(await options).toEqual({ challenge: 'rst' });

    const replaced = api.regenerateRecoveryCodesWithPasskey({ id: 'cred' });
    const request = http.expectOne({ method: 'POST', url: '/api/auth/mfa/recovery-codes/passkey' });
    expect(request.request.body).toEqual({ credential: { id: 'cred' } });
    request.flush({ recoveryCodes: ['eeeee-fffff'] });
    expect(await replaced).toEqual(['eeeee-fffff']);

    const refused = api.regenerateRecoveryCodesWithPasskey({ id: 'other' });
    http
      .expectOne('/api/auth/mfa/recovery-codes/passkey')
      .flush({ error: 'invalid_passkey' }, { status: 422, statusText: 'Unprocessable Content' });
    await expect(refused).rejects.toEqual(new AuthRefused('invalid_passkey'));
  });

  const passkey = {
    id: '0190e6f5-0000-7000-8000-000000000001',
    name: 'Work laptop',
    createdAt: '2026-09-15T10:00:00+00:00',
    lastUsedAt: null,
  };

  it('fetches creation options, registers the credential and lists the passkeys', async () => {
    const options = api.passkeyRegistrationOptions();
    http
      .expectOne({ method: 'POST', url: '/api/auth/mfa/passkeys/options' })
      .flush({ challenge: 'abc' });
    expect(await options).toEqual({ challenge: 'abc' });

    const registered = api.registerPasskey('Work laptop', { id: 'cred' });
    const request = http.expectOne({ method: 'POST', url: '/api/auth/mfa/passkeys' });
    expect(request.request.body).toEqual({ name: 'Work laptop', credential: { id: 'cred' } });
    request.flush({ passkey, recoveryCodes: ['aaaaa-bbbbb'] });
    expect(await registered).toEqual({ passkey, recoveryCodes: ['aaaaa-bbbbb'] });

    const listed = api.listPasskeys();
    http.expectOne({ method: 'GET', url: '/api/auth/mfa/passkeys' }).flush({ passkeys: [passkey] });
    expect(await listed).toEqual([passkey]);
  });

  it('removes a passkey, or reports why it stays', async () => {
    const removed = api.removePasskey(passkey.id);
    http
      .expectOne({ method: 'DELETE', url: `/api/auth/mfa/passkeys/${passkey.id}` })
      .flush(null, { status: 204, statusText: 'No Content' });
    await expect(removed).resolves.toBeUndefined();

    const kept = api.removePasskey(passkey.id);
    http
      .expectOne(`/api/auth/mfa/passkeys/${passkey.id}`)
      .flush({ error: 'mfa_last_factor' }, { status: 409, statusText: 'Conflict' });
    await expect(kept).rejects.toEqual(new AuthRefused('mfa_last_factor'));
  });

  it('answers a pending login with a passkey', async () => {
    const options = api.passkeyLoginOptions();
    http
      .expectOne({ method: 'POST', url: '/api/auth/mfa/passkey-login/options' })
      .flush({ challenge: 'xyz' });
    expect(await options).toEqual({ challenge: 'xyz' });

    const signedIn = api.finishPasskeyLogin({ id: 'cred' });
    const request = http.expectOne({ method: 'POST', url: '/api/auth/mfa/passkey-login' });
    expect(request.request.body).toEqual({ credential: { id: 'cred' } });
    request.flush(owner);
    expect((await signedIn).user.email).toBe('owner@example.test');

    const refused = api.finishPasskeyLogin({ id: 'other' });
    http
      .expectOne('/api/auth/mfa/passkey-login')
      .flush({ error: 'invalid_passkey' }, { status: 401, statusText: 'Unauthorized' });
    await expect(refused).rejects.toEqual(new AuthRefused('invalid_passkey'));
  });
});

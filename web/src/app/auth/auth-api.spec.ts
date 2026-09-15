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
    http.expectOne('/api/auth/me').flush({ ...owner, mfa: { enrolled: false, required: true } });
    expect((await pending).mfa).toEqual({ enrolled: false, required: true });
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
});

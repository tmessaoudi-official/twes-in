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
  mfa: { enrolled: false, required: false },
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
    expect((await pending).company).toBeNull();
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
});

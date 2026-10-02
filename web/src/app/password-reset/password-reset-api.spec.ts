// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { PasswordResetApi, PasswordResetRefused } from './password-reset-api';

describe('PasswordResetApi', () => {
  let api: PasswordResetApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(PasswordResetApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('asks for a link with the address and the language the page is read in', async () => {
    const asked = api.forgot('someone@example.test', 'fr');
    const request = http.expectOne({ method: 'POST', url: '/api/auth/password/forgot' });
    expect(request.request.body).toEqual({ email: 'someone@example.test', locale: 'fr' });
    request.flush(null, { status: 204, statusText: 'No Content' });
    await asked;
  });

  it('chooses the new password with the link, and says why a refusal came', async () => {
    const done = api.reset('a'.repeat(64), 'a-long-enough-password');
    const request = http.expectOne({ method: 'POST', url: '/api/auth/password/reset' });
    expect(request.request.body).toEqual({
      token: 'a'.repeat(64),
      newPassword: 'a-long-enough-password',
    });
    request.flush(null, { status: 204, statusText: 'No Content' });
    await done;

    const refused = api.reset('b'.repeat(64), 'x');
    http
      .expectOne('/api/auth/password/reset')
      .flush({ error: 'link_not_usable' }, { status: 422, statusText: 'Unprocessable Content' });
    await expect(refused).rejects.toEqual(new PasswordResetRefused('link_not_usable'));
  });

  it('reads an unknown code as a plain refusal and an unreachable server as the network', async () => {
    const odd = api.forgot('a@b.c', 'fr');
    http
      .expectOne('/api/auth/password/forgot')
      .flush({ error: 'something_new' }, { status: 500, statusText: 'Server Error' });
    await expect(odd).rejects.toEqual(new PasswordResetRefused('refused'));

    const down = api.forgot('a@b.c', 'fr');
    http.expectOne('/api/auth/password/forgot').error(new ProgressEvent('error'));
    await expect(down).rejects.toEqual(new PasswordResetRefused('network'));
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { InvitationApi, InvitationRefused } from './invitation-api';

describe('InvitationApi', () => {
  let http: HttpTestingController;
  let api: InvitationApi;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    api = TestBed.inject(InvitationApi);
  });

  afterEach(() => http.verify());

  it('says the link was tried too often, rather than an invalid request, when the API holds it back', async () => {
    const read = api.offer('t'.repeat(64));
    http
      .expectOne(`/api/invitations/${'t'.repeat(64)}`)
      .flush({ title: 'Too many attempts.' }, { status: 429, statusText: 'Too Many Requests' });
    await expect(read).rejects.toEqual(new InvitationRefused('too_many'));

    const accepted = api.accept('t'.repeat(64), 'Nadia', 'password-1234');
    http
      .expectOne(`/api/invitations/${'t'.repeat(64)}/accept`)
      .flush({ title: 'Too many attempts.' }, { status: 429, statusText: 'Too Many Requests' });
    await expect(accepted).rejects.toEqual(new InvitationRefused('too_many'));
  });

  it('keeps a link that is gone, a refused password and anything else apart', async () => {
    for (const [status, code] of [
      [404, 'not_usable'],
      [422, 'password_refused'],
      [400, 'invalid'],
    ] as const) {
      const read = api.offer('t'.repeat(64));
      http
        .expectOne(`/api/invitations/${'t'.repeat(64)}`)
        .flush({}, { status, statusText: String(status) });
      await expect(read, String(status)).rejects.toEqual(new InvitationRefused(code));
    }
  });
});

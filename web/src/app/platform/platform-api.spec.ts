// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { PlatformApi, PlatformRefused } from './platform-api';

describe('PlatformApi', () => {
  let api: PlatformApi;
  let http: HttpTestingController;

  const account = {
    id: 'u1',
    email: 'nadia@acme.test',
    displayName: 'Nadia',
    active: true,
    platformOperator: false,
    createdAt: '2026-09-15T10:00:00+00:00',
  };

  const waiting = {
    id: 'c1',
    name: 'Nouvelle Société',
    countryCode: 'TN',
    status: 'pending',
    createdAt: '2026-09-15T10:00:00+00:00',
    owners: ['nadia@example.test'],
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(PlatformApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('finds accounts by a piece of their address or name', async () => {
    const found = api.accounts('acme & co');

    const request = http.expectOne((candidate) => candidate.url === '/api/platform/accounts');
    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('q')).toBe('acme & co');
    request.flush([account]);

    expect(await found).toEqual([account]);
  });

  it('acts on an account by its identifier and answers it as the platform holds it', async () => {
    const done = api.actOnAccount('u/1', 'deactivate');

    const request = http.expectOne('/api/platform/accounts/u%2F1/deactivate');
    expect(request.request.method).toBe('POST');
    request.flush({ ...account, active: false });

    expect((await done).active).toBe(false);
  });

  it("names the refusal to deactivate one's own account", async () => {
    const done = api.actOnAccount('u1', 'deactivate');

    http
      .expectOne('/api/platform/accounts/u1/deactivate')
      .flush({}, { status: 409, statusText: 'Conflict' });

    await expect(done).rejects.toMatchObject({ code: 'own_account' });
  });

  it('lists the companies waiting for approval', async () => {
    const rows = api.waitingCompanies();

    const request = http.expectOne('/api/platform/companies?status=pending');
    expect(request.request.method).toBe('GET');
    request.flush([waiting]);

    expect(await rows).toEqual([waiting]);
  });

  it('approves and rejects a company by its identifier', async () => {
    const approved = api.approve('c/1');
    const approve = http.expectOne('/api/platform/companies/c%2F1/approve');
    expect(approve.request.method).toBe('POST');
    approve.flush({ ...waiting, status: 'active' });
    expect((await approved).status).toBe('active');

    const rejected = api.reject('c1');
    const reject = http.expectOne('/api/platform/companies/c1/reject');
    expect(reject.request.method).toBe('POST');
    reject.flush({ ...waiting, status: 'suspended' });
    expect((await rejected).status).toBe('suspended');
  });

  it('reads the two signup switches from the platform settings', async () => {
    const signup = api.signup();

    http.expectOne('/api/platform/settings').flush([
      { key: 'signup.enabled', value: true },
      { key: 'signup.approval_required', value: false },
    ]);

    expect(await signup).toEqual({ enabled: true, approvalRequired: false });
  });

  it('sets one signup switch at the platform level', async () => {
    const done = api.setSignup('signup.approval_required', true);

    const request = http.expectOne('/api/platform/settings/signup.approval_required');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ value: true });
    request.flush({ key: 'signup.approval_required', value: true });

    await done;
  });

  it('names a refusal: gone, forbidden or unreachable', async () => {
    const gone = api.approve('c1');
    http
      .expectOne('/api/platform/companies/c1/approve')
      .flush({}, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toEqual(new PlatformRefused('not_found'));

    const forbidden = api.waitingCompanies();
    http
      .expectOne('/api/platform/companies?status=pending')
      .flush({}, { status: 403, statusText: 'Forbidden' });
    await expect(forbidden).rejects.toEqual(new PlatformRefused('refused'));

    const offline = api.signup();
    http.expectOne('/api/platform/settings').error(new ProgressEvent('error'), { status: 0 });
    await expect(offline).rejects.toEqual(new PlatformRefused('network'));
  });
});

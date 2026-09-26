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
    subscription: null,
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(PlatformApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it("reads a company's subscription, its terms with where they leave it, and none as null", async () => {
    const pending = api.subscription('c1');
    http.expectOne('/api/platform/companies/c1/subscription').flush({
      companyId: 'c1',
      periodCount: 6,
      periodUnit: 'month',
      trialEndsOn: null,
      paidThrough: '2026-12-31',
      price: '600.000',
      currency: 'TND',
      graceDays: null,
      unpaidMode: null,
      holdDays: null,
      stage: 'paid',
      access: 'full',
      coveredUntil: '2026-12-31T23:59:59+01:00',
      graceEndsAt: '2027-01-07T23:59:59+01:00',
      daysLeft: 105,
      updatedAt: '2026-09-17T10:00:00+00:00',
    });

    const held = await pending;
    expect(held?.periodCount).toBe(6);
    expect(held?.price).toBe('600.000');
    expect(held?.daysLeft).toBe(105);

    const none = api.subscription('c2');
    http
      .expectOne('/api/platform/companies/c2/subscription')
      .flush(null, { status: 404, statusText: 'Not Found' });
    await expect(none).resolves.toBeNull();
  });

  it('sets the terms and stops managing a company', async () => {
    const terms = {
      periodCount: 1,
      periodUnit: 'year' as const,
      trialEndsOn: '2026-10-01',
      paidThrough: null,
      price: null,
      currency: null,
      graceDays: 10,
      unpaidMode: 'locked' as const,
      holdDays: 3,
    };
    const saving = api.setSubscription('c1', terms);
    const put = http.expectOne('/api/platform/companies/c1/subscription');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual(terms);
    put.flush({
      companyId: 'c1',
      ...terms,
      stage: 'trial',
      access: 'full',
      coveredUntil: '2026-10-01T23:59:59+01:00',
      graceEndsAt: '2026-10-11T23:59:59+01:00',
      daysLeft: 14,
      updatedAt: '2026-09-17T10:00:00+00:00',
    });
    expect((await saving).stage).toBe('trial');

    const stopping = api.stopSubscription('c1');
    const gone = http.expectOne('/api/platform/companies/c1/subscription');
    expect(gone.request.method).toBe('DELETE');
    gone.flush(null, { status: 204, statusText: 'No Content' });
    await expect(stopping).resolves.toBeUndefined();
  });

  // « Me prévenir » (row 150): how many companies wait for each planned module, the most asked for first.
  it('reads the demand for the planned modules as the platform orders it', async () => {
    const pending = api.moduleDemand();
    http.expectOne('/api/platform/module-demand').flush([
      { key: 'quotes', labelKey: 'modules.quotes', planned: 'v1', companies: 2 },
      { key: 'zakat', labelKey: 'modules.zakat', planned: 'later', companies: 0 },
    ]);

    expect(await pending).toEqual([
      { key: 'quotes', labelKey: 'modules.quotes', planned: 'v1', companies: 2 },
      { key: 'zakat', labelKey: 'modules.zakat', planned: 'later', companies: 0 },
    ]);
  });

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

  it('lists every company, whatever its status', async () => {
    const rows = api.companies();

    const request = http.expectOne(
      (candidate) => candidate.url === '/api/platform/companies' && !candidate.params.has('status'),
    );
    expect(request.request.method).toBe('GET');
    request.flush([waiting]);

    expect(await rows).toEqual([waiting]);
  });

  it('opens a company and answers its identifier, or names a taken name', async () => {
    const company = {
      name: 'Globex',
      countryCode: 'TN',
      currency: 'TND',
      locale: 'fr',
      timezone: 'Africa/Tunis',
    };
    const opened = api.createCompany(company);
    const request = http.expectOne('/api/companies');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(company);
    request.flush({ ...company, id: 'c9', status: 'pending' });
    expect(await opened).toBe('c9');

    const taken = api.createCompany(company);
    http.expectOne('/api/companies').flush({}, { status: 409, statusText: 'Conflict' });
    await expect(taken).rejects.toMatchObject({ code: 'name_taken' });
  });

  it('invites the owner of a company by its identifier, or names an address already in it', async () => {
    const invited = api.inviteOwner('c/1', 'nadia@example.test');
    const request = http.expectOne('/api/platform/companies/c%2F1/owners');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ email: 'nadia@example.test' });
    request.flush({ email: 'nadia@example.test', role: 'owner', status: 'invited' });
    await invited;

    const already = api.inviteOwner('c1', 'nadia@example.test');
    http
      .expectOne('/api/platform/companies/c1/owners')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(already).rejects.toMatchObject({ code: 'already_member' });
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

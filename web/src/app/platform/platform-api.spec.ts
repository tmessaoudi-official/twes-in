// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { PlatformApi, PlatformRefused } from './platform-api';
import { RECORD_CHANGED } from '../shared/errors/record-changed';

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
    companies: [{ id: 'c1', name: 'Acme', role: 'owner' }],
  };

  const search = {
    page: 1,
    itemsPerPage: 25,
    q: '',
    statuses: [],
    countryCodes: [],
    order: null,
  } as const;

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
    const saving = api.setSubscription('c1', terms, 4);
    const put = http.expectOne('/api/platform/companies/c1/subscription');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ ...terms, version: 4 });
    put.flush({
      companyId: 'c1',
      ...terms,
      stage: 'trial',
      access: 'full',
      coveredUntil: '2026-10-01T23:59:59+01:00',
      graceEndsAt: '2026-10-11T23:59:59+01:00',
      daysLeft: 14,
      updatedAt: '2026-09-17T10:00:00+00:00',
      version: 5,
    });
    const saved = await saving;
    expect([saved.stage, saved.version]).toEqual(['trial', 5]);

    const refused = api.setSubscription('c1', terms, 4);
    http
      .expectOne('/api/platform/companies/c1/subscription')
      .flush(
        { type: '/problems/record-changed', status: 409 },
        { status: 409, statusText: 'Conflict' },
      );
    await expect(refused).rejects.toEqual(new PlatformRefused('record_changed'));

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

  // Row 56: what the worker gave up on, which only the operator can see and retry.
  it('reads how many messages failed for good, and of which kinds', async () => {
    const pending = api.failedMessages();
    http.expectOne('/api/platform/failed-messages').flush({
      total: 3,
      kinds: [
        { kind: 'PasswordResetAsked', count: 2 },
        { kind: 'InvitationToMail', count: 1 },
      ],
    });

    expect(await pending).toEqual({
      total: 3,
      kinds: [
        { kind: 'PasswordResetAsked', count: 2 },
        { kind: 'InvitationToMail', count: 1 },
      ],
    });
  });

  it('finds a page of accounts by a piece of their address or name, with the companies of each', async () => {
    const found = api.accounts({
      page: 2,
      itemsPerPage: 50,
      q: 'acme & co',
      active: false,
      platformOperator: null,
      order: { key: 'displayName', direction: 'desc' },
    });

    const request = http.expectOne((candidate) => candidate.url === '/api/platform/accounts');
    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('q')).toBe('acme & co');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('itemsPerPage')).toBe('50');
    expect(request.request.params.get('active')).toBe('false');
    expect(request.request.params.has('platformOperator')).toBe(false);
    expect(request.request.params.get('order[displayName]')).toBe('desc');
    request.flush({ member: [account], totalItems: 61 });

    expect(await found).toEqual({ rows: [account], total: 61 });
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

  it('lists a page of companies, whatever their status, with the total the platform counts', async () => {
    const rows = api.companies(search);

    const request = http.expectOne(
      (candidate) => candidate.url === '/api/platform/companies' && !candidate.params.has('status'),
    );
    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('page')).toBe('1');
    expect(request.request.params.get('itemsPerPage')).toBe('25');
    request.flush({ member: [waiting], totalItems: 130 });

    expect(await rows).toEqual({ rows: [waiting], total: 130 });
  });

  it('asks for the status, country and order a list is narrowed and sorted by', async () => {
    const rows = api.companies({
      ...search,
      q: 'nadia@',
      statuses: ['pending', 'suspended'],
      countryCodes: ['TN', 'FR'],
      order: { key: 'createdAt', direction: 'asc' },
    });

    const request = http.expectOne((candidate) => candidate.url === '/api/platform/companies');
    expect(request.request.params.get('q')).toBe('nadia@');
    // Each filter's values OR'd, the two AND'd (row 197).
    expect(request.request.params.getAll('status[]')).toEqual(['pending', 'suspended']);
    expect(request.request.params.getAll('countryCode[]')).toEqual(['TN', 'FR']);
    expect(request.request.params.get('order[createdAt]')).toBe('asc');
    request.flush({ member: [], totalItems: 0 });

    expect(await rows).toEqual({ rows: [], total: 0 });
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

  it("reads the switches, the support channels, the delays and every key's version in one request", async () => {
    const settings = api.settings();

    http.expectOne('/api/platform/settings').flush([
      { key: 'signup.enabled', type: 'bool', value: true, versions: { platform: 2 } },
      { key: 'signup.approval_required', type: 'bool', value: false, versions: { platform: 1 } },
      { key: 'support.phone', type: 'text', value: '+216 71 000 000', versions: { platform: 5 } },
      { key: 'support.email', type: 'text', value: '', versions: {} },
      { key: 'support.status_url', type: 'text', value: 'https://status.twes.test', versions: [] },
      {
        key: 'account.deletion_days',
        type: 'int',
        labelKey: 'settings.platform.account_deletion_days',
        value: 10,
        default: 7,
        min: 1,
        max: 90,
        versions: { platform: 3 },
      },
      {
        key: 'company.reopen_days',
        type: 'int',
        labelKey: 'settings.platform.company_reopen_days',
        value: null,
        default: 7,
        min: 1,
        max: 90,
      },
    ]);

    expect(await settings).toEqual({
      signup: { enabled: true, approvalRequired: false },
      support: {
        phone: '+216 71 000 000',
        whatsapp: '',
        email: '',
        status_url: 'https://status.twes.test',
      },
      delays: [
        {
          key: 'account.deletion_days',
          labelKey: 'settings.platform.account_deletion_days',
          value: 10,
          min: 1,
          max: 90,
        },
        {
          key: 'company.reopen_days',
          labelKey: 'settings.platform.company_reopen_days',
          value: 7,
          min: 1,
          max: 90,
        },
      ],
      // A key holding no row is version 0, however the API writes its empty map.
      versions: {
        'signup.enabled': 2,
        'signup.approval_required': 1,
        'support.phone': 5,
        'support.email': 0,
        'support.status_url': 0,
        'account.deletion_days': 3,
        'company.reopen_days': 0,
      },
    });
  });

  it('sets one signup switch at the platform level', async () => {
    const done = api.setSignup('signup.approval_required', true);

    const request = http.expectOne('/api/platform/settings/signup.approval_required');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ value: true });
    request.flush({ key: 'signup.approval_required', value: true });

    await done;
  });

  it('saves every key a form changed in one request, each with the version read', async () => {
    const done = api.saveSettings([
      { key: 'account.deletion_days', value: 14, version: 3 },
      { key: 'support.whatsapp', value: '+216 20 123 456', version: 0 },
    ]);

    const request = http.expectOne('/api/platform/settings');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      changes: [
        { key: 'account.deletion_days', value: 14, version: 3 },
        { key: 'support.whatsapp', value: '+216 20 123 456', version: 0 },
      ],
    });
    request.flush(null, { status: 204, statusText: 'No Content' });

    await done;
  });

  it('tells a save made over a version saved since from another conflict', async () => {
    const since = api.saveSettings([{ key: 'support.phone', value: '1', version: 1 }]);
    http
      .expectOne('/api/platform/settings')
      .flush({ type: RECORD_CHANGED, status: 409 }, { status: 409, statusText: 'Conflict' });
    await expect(since).rejects.toEqual(new PlatformRefused('record_changed'));
  });

  it('names a refusal: gone, forbidden or unreachable', async () => {
    const gone = api.approve('c1');
    http
      .expectOne('/api/platform/companies/c1/approve')
      .flush({}, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toEqual(new PlatformRefused('not_found'));

    const forbidden = api.companies({ ...search, statuses: ['pending'] });
    http
      .expectOne((candidate) => candidate.url === '/api/platform/companies')
      .flush({}, { status: 403, statusText: 'Forbidden' });
    await expect(forbidden).rejects.toEqual(new PlatformRefused('refused'));

    const offline = api.settings();
    http.expectOne('/api/platform/settings').error(new ProgressEvent('error'), { status: 0 });
    await expect(offline).rejects.toEqual(new PlatformRefused('network'));
  });

  // docs/SPEC.md § 7, the step-up list: the platform's wall answers the code, creating a company the detail.
  it('names the proof every platform write waits for, in either shape', async () => {
    const approving = api.approve('c1');
    http
      .expectOne('/api/platform/companies/c1/approve')
      .flush({ error: 'step_up_required' }, { status: 403, statusText: 'Forbidden' });
    await expect(approving).rejects.toEqual(new PlatformRefused('step_up_required'));

    const creating = api.createCompany({
      name: 'Acme',
      countryCode: 'TN',
      currency: 'TND',
      locale: 'fr',
      timezone: 'Africa/Tunis',
    });
    http
      .expectOne('/api/companies')
      .flush(
        { detail: 'step_up_required: confirm who you are first.' },
        { status: 403, statusText: 'Forbidden' },
      );
    await expect(creating).rejects.toEqual(new PlatformRefused('step_up_required'));
  });
});

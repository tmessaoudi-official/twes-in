// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ModulesApi } from './modules-api';

describe('ModulesApi', () => {
  let api: ModulesApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(ModulesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the modules of a company', async () => {
    const pending = api.list('c 1');
    http.expectOne('/api/companies/c%201/modules').flush([
      {
        key: 'customers',
        labelKey: 'modules.customers',
        dependencies: [],
        permissions: ['customer.read'],
        enabled: true,
      },
      {
        key: 'invoices',
        labelKey: 'modules.invoices',
        dependencies: ['customers'],
        permissions: [],
        enabled: null,
      },
      {
        key: 'zakat',
        labelKey: 'modules.zakat',
        dependencies: [],
        permissions: [],
        enabled: false,
        planned: 'later',
        interested: true,
      },
    ]);

    expect(await pending).toEqual([
      {
        key: 'customers',
        labelKey: 'modules.customers',
        dependencies: [],
        permissions: ['customer.read'],
        enabled: true,
      },
      {
        key: 'invoices',
        labelKey: 'modules.invoices',
        dependencies: ['customers'],
        permissions: [],
        enabled: false,
      },
      {
        key: 'zakat',
        labelKey: 'modules.zakat',
        dependencies: [],
        permissions: [],
        enabled: false,
        planned: 'later',
        interested: true,
      },
    ]);
  });

  it('asks to be told when a planned module arrives, and says why it was refused', async () => {
    const asked = api.setInterest('c1', 'quotes', true);
    const request = http.expectOne('/api/companies/c1/modules/quotes/interest');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ interested: true });
    request.flush({
      key: 'quotes',
      labelKey: 'modules.quotes',
      dependencies: ['customers'],
      permissions: [],
      enabled: false,
      planned: 'v1',
      interested: true,
    });
    expect((await asked).interested).toBe(true);

    const shipped = api.setInterest('c1', 'customers', true);
    http
      .expectOne('/api/companies/c1/modules/customers/interest')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(shipped).rejects.toMatchObject({ code: 'already_available' });
  });

  it('switches a module, and says why a switch was refused', async () => {
    const on = api.switch('c1', 'invoices', true);
    const request = http.expectOne('/api/companies/c1/modules/invoices');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ enabled: true });
    request.flush({
      key: 'invoices',
      labelKey: 'modules.invoices',
      dependencies: ['customers'],
      permissions: [],
      enabled: true,
    });
    expect((await on).enabled).toBe(true);

    const needs = api.switch('c1', 'invoices', true);
    http
      .expectOne('/api/companies/c1/modules/invoices')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(needs).rejects.toMatchObject({ code: 'needs_modules' });

    const needed = api.switch('c1', 'customers', false);
    http
      .expectOne('/api/companies/c1/modules/customers')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(needed).rejects.toMatchObject({ code: 'still_needed' });

    const absent = api.switch('c1', 'vendors', true);
    http
      .expectOne('/api/companies/c1/modules/vendors')
      .flush({}, { status: 404, statusText: 'Not Found' });
    await expect(absent).rejects.toMatchObject({ code: 'not_found' });

    const refused = api.switch('c1', 'vendors', true);
    http
      .expectOne('/api/companies/c1/modules/vendors')
      .flush({}, { status: 422, statusText: 'Unprocessable' });
    await expect(refused).rejects.toMatchObject({ code: 'invalid' });
  });

  it('says the server could not be reached', async () => {
    const pending = api.list('c1');
    http.expectOne('/api/companies/c1/modules').error(new ProgressEvent('error'));

    await expect(pending).rejects.toMatchObject({ code: 'network' });
  });
});

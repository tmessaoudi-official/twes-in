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
      },
    ]);
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

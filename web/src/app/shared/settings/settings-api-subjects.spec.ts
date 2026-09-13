// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SettingsApi } from './settings-api';

describe('SettingsApi for a customer or a customer group', () => {
  let api: SettingsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(SettingsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads a chain as a customer sees it', async () => {
    const pending = api.chain('c1', 'parties', { customerId: 'k1' });
    const request = http.expectOne((candidate) => candidate.url === '/api/companies/c1/settings');
    expect(request.request.params.get('chain')).toBe('parties');
    expect(request.request.params.get('customerId')).toBe('k1');
    expect(request.request.params.has('customerGroupId')).toBe(false);
    request.flush([]);

    expect(await pending).toEqual([]);
  });

  it("stores a group's value naming the group", async () => {
    const pending = api.change(
      'c1',
      'document.payment_terms_days',
      'customer_group',
      45,
      undefined,
      {
        customerGroupId: 'g1',
      },
    );
    const request = http.expectOne('/api/companies/c1/settings/document.payment_terms_days');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      level: 'customer_group',
      value: 45,
      customerGroupId: 'g1',
    });
    request.flush({ key: 'document.payment_terms_days', value: 45, source: 'customer_group' });

    expect((await pending).source).toBe('customer_group');
  });

  it("forgets a customer's value naming the customer", async () => {
    const pending = api.reset('c1', 'document.payment_terms_days', 'customer', undefined, {
      customerId: 'k1',
    });
    const request = http.expectOne(
      (candidate) => candidate.url === '/api/companies/c1/settings/document.payment_terms_days',
    );
    expect(request.request.method).toBe('DELETE');
    expect(request.request.params.get('level')).toBe('customer');
    expect(request.request.params.get('customerId')).toBe('k1');
    request.flush(null, { status: 204, statusText: 'No Content' });

    await expect(pending).resolves.toBeUndefined();
  });
});

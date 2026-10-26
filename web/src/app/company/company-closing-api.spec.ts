// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CompanyClosingApi, CompanyClosingRefused, askedForProof } from './company-closing-api';

describe('CompanyClosingApi', () => {
  let api: CompanyClosingApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyClosingApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('closes the company and answers its terms', async () => {
    const pending = api.closeDown('c1');
    const request = http.expectOne({ method: 'POST', url: '/api/companies/c1/close-down' });
    request.flush({ retainUntil: '2036-12-31', reopenUntil: '2026-11-02T10:00:00+01:00' });
    expect(await pending).toEqual({
      retainUntil: '2036-12-31',
      reopenUntil: '2026-11-02T10:00:00+01:00',
    });
  });

  it('reopens it', async () => {
    const pending = api.reopen('c1');
    http.expectOne({ method: 'POST', url: '/api/companies/c1/reopen' }).flush(null);
    await expect(pending).resolves.toBeUndefined();
  });

  it.each([
    ['step_up_required', 403, 'step_up_required'],
    ['owner_only', 403, 'owner_only'],
    ['reopen_period_over', 409, 'reopen_period_over'],
    ['company_closed', 403, 'refused'],
  ])('reads the refusal %s', async (sent, status, read) => {
    const pending = api.reopen('c1');
    http.expectOne('/api/companies/c1/reopen').flush({ error: sent }, { status, statusText: 'No' });
    const error = await pending.catch((caught: unknown) => caught);
    expect(error).toBeInstanceOf(CompanyClosingRefused);
    expect((error as CompanyClosingRefused).code).toBe(read);
    expect(askedForProof(error)).toBe(read === 'step_up_required');
  });
});

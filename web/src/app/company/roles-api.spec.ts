// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { RolesApi, RolesRefused } from './roles-api';

describe('RolesApi refusals', () => {
  let api: RolesApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(RolesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  async function refusedBy(status: number, detail: string): Promise<RolesRefused> {
    const created = api.create('c1', 'payer', ['invoice.issue']);
    http
      .expectOne({ method: 'POST', url: '/api/companies/c1/roles' })
      .flush({ detail }, { status, statusText: 'refused' });
    return (await created.then(
      () => {
        throw new Error('expected a refusal');
      },
      (error: unknown) => error,
    )) as RolesRefused;
  }

  it('tells a permission the editor does not hold, by its token, from any other refusal', async () => {
    const refused = await refusedBy(
      403,
      'role_permission_not_held: "invoice.issue" cannot be given.',
    );

    expect(refused.code).toBe('not_held');
  });

  it('reads any other 403 as a plain refusal', async () => {
    expect((await refusedBy(403, 'Forbidden')).code).toBe('invalid');
  });
});

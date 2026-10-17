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

  it('tells a change that waits for the step-up proof, by its token', async () => {
    const refused = await refusedBy(
      403,
      'step_up_required: confirm the password or a passkey again before this change.',
    );

    expect(refused.code).toBe('step_up_required');
  });

  it('reads the version of each role it lists', async () => {
    const listed = api.list('c1');
    http.expectOne({ method: 'GET', url: '/api/companies/c1/roles' }).flush([
      {
        id: 'r1',
        name: 'payer',
        builtIn: false,
        wildcard: false,
        permissions: [],
        memberCount: 0,
        version: 4,
      },
    ]);

    expect((await listed)[0]?.version).toBe(4);
  });

  it('sends back the version a revision was opened at', async () => {
    const revised = api.revise('c1', 'r1', 'payer', ['invoice.issue'], 3);
    const request = http.expectOne({ method: 'PUT', url: '/api/companies/c1/roles/r1' });
    expect(request.request.body).toEqual({
      name: 'payer',
      permissions: ['invoice.issue'],
      version: 3,
    });
    request.flush({
      id: 'r1',
      name: 'payer',
      builtIn: false,
      wildcard: false,
      permissions: ['invoice.issue'],
      memberCount: 0,
      version: 4,
    });

    expect((await revised).version).toBe(4);
  });

  it('tells a role saved since from a name already taken, though both answer 409', async () => {
    const refusal = async (body: object): Promise<RolesRefused> => {
      const revised = api.revise('c1', 'r1', 'payer', [], 1);
      http
        .expectOne({ method: 'PUT', url: '/api/companies/c1/roles/r1' })
        .flush(body, { status: 409, statusText: 'Conflict' });
      return (await revised.then(
        () => {
          throw new Error('expected a refusal');
        },
        (error: unknown) => error,
      )) as RolesRefused;
    };

    expect((await refusal({ type: '/problems/record-changed', detail: 'saved since' })).code).toBe(
      'record_changed',
    );
    expect((await refusal({ detail: 'A role is already called "payer".' })).code).toBe(
      'name_taken',
    );
  });
});

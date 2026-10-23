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

  it('deletes a role naming the version it was read at, and tells one revised since apart', async () => {
    const removing = api.remove('c1', 'r1', 3);
    const gone = http.expectOne((request) => request.url === '/api/companies/c1/roles/r1');
    expect(gone.request.method).toBe('DELETE');
    expect(gone.request.params.get('version')).toBe('3');
    gone.flush(null, { status: 204, statusText: 'No Content' });
    await expect(removing).resolves.toBeUndefined();

    const stale = api.remove('c1', 'r1', 2);
    http
      .expectOne((request) => request.url === '/api/companies/c1/roles/r1')
      .flush(
        { type: '/problems/record-changed', status: 409 },
        { status: 409, statusText: 'Conflict' },
      );
    await expect(stale).rejects.toMatchObject({ code: 'record_changed' });
  });

  it('tells a role somebody holds by its reason, and who holds it by the parameters, never by the English detail', async () => {
    const removed = api.remove('c1', 'r1', 2);
    http
      .expectOne((request) => request.url === '/api/companies/c1/roles/r1')
      .flush(
        {
          detail:
            'role: The cashier role is held by sami@twes.local. Move them to another role before deleting it.',
          violations: [
            {
              propertyPath: 'role',
              message: 'x',
              payload: {
                reason: 'role_in_use',
                params: { role: 'cashier', holders: 'sami@twes.local, ali@twes.local', more: 1 },
              },
            },
          ],
        },
        { status: 422, statusText: 'Unprocessable' },
      );
    const refused = (await removed.then(
      () => {
        throw new Error('expected a refusal');
      },
      (error: unknown) => error,
    )) as RolesRefused;

    expect(refused.code).toBe('in_use');
    expect(refused.held).toEqual({
      role: 'cashier',
      holders: 'sami@twes.local, ali@twes.local',
      more: true,
    });
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

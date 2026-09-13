// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CustomFieldsApi, CustomFieldsRefused } from './custom-fields-api';
import type { CustomFieldInput } from './custom-fields-types';

const sector: CustomFieldInput = {
  entity: 'customer',
  key: 'sector',
  label: 'Secteur',
  type: 'choice',
  required: true,
  choices: ['retail', 'wholesale'],
  sortOrder: 0,
  isActive: true,
};

describe('CustomFieldsApi', () => {
  let api: CustomFieldsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CustomFieldsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it("reads a company's fields for one kind of record, filling what the API leaves out", async () => {
    const pending = api.list('c1', 'customer');
    const request = http.expectOne(
      (candidate) => candidate.url === '/api/companies/c1/custom-fields',
    );
    expect(request.request.params.get('entity')).toBe('customer');
    request.flush([
      { id: 'f1', key: 'sector', label: 'Secteur', type: 'choice', choices: ['retail'] },
    ]);

    expect(await pending).toEqual([
      {
        id: 'f1',
        entity: 'customer',
        key: 'sector',
        label: 'Secteur',
        type: 'choice',
        required: false,
        choices: ['retail'],
        sortOrder: 0,
        isActive: true,
      },
    ]);
  });

  it('declares a field and revises one by id', async () => {
    const created = api.create('c1', sector);
    const post = http.expectOne('/api/companies/c1/custom-fields');
    expect([post.request.method, post.request.body]).toEqual(['POST', sector]);
    post.flush({ ...sector, id: 'f1' });
    expect((await created).id).toBe('f1');

    const revised = api.revise('c1', 'f1', { ...sector, label: 'Activité' });
    const put = http.expectOne('/api/companies/c1/custom-fields/f1');
    expect([put.request.method, put.request.body.label]).toEqual(['PUT', 'Activité']);
    put.flush({ ...sector, id: 'f1', label: 'Activité' });
    expect((await revised).label).toBe('Activité');
  });

  it('names why the API refused', async () => {
    const cases: [number, string][] = [
      [409, 'key_taken'],
      [404, 'not_found'],
      [422, 'invalid'],
      [0, 'network'],
    ];
    for (const [status, code] of cases) {
      const pending = api.create('c1', sector);
      http
        .expectOne('/api/companies/c1/custom-fields')
        .flush(null, { status, statusText: String(status) });
      await expect(pending).rejects.toEqual(new CustomFieldsRefused(code as never));
    }
  });
});

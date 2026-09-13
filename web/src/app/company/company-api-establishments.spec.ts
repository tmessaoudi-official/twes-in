// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CompanyApi } from './company-api';
import type { EstablishmentInput } from './company-types';

const sfax: EstablishmentInput = {
  code: '001',
  name: 'Agence de Sfax',
  addressLine1: null,
  addressLine2: null,
  postalCode: null,
  city: 'Sfax',
  phone: null,
  email: null,
  isDefault: false,
};

describe('CompanyApi establishments and numbering', () => {
  let api: CompanyApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads establishments, filling what the API left out', async () => {
    const pending = api.establishments('c1');
    http
      .expectOne('/api/companies/c1/establishments')
      .flush([{ id: 'e1', code: '000', name: 'Acme', isDefault: true, codePattern: '^[0-9]{3}$' }]);

    expect(await pending).toEqual([
      {
        id: 'e1',
        code: '000',
        name: 'Acme',
        addressLine1: null,
        addressLine2: null,
        postalCode: null,
        city: null,
        phone: null,
        email: null,
        isDefault: true,
        codePattern: '^[0-9]{3}$',
      },
    ]);
  });

  it('names a code another establishment already has', async () => {
    const pending = api.createEstablishment('c1', sfax);
    const request = http.expectOne('/api/companies/c1/establishments');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(sfax);
    request.flush({ detail: 'taken' }, { status: 409, statusText: 'Conflict' });

    await expect(pending).rejects.toMatchObject({ code: 'code_taken' });
  });

  it('names an establishment refused for what it says', async () => {
    const pending = api.reviseEstablishment('c1', 'e 1', sfax);
    const request = http.expectOne('/api/companies/c1/establishments/e%201');
    expect(request.request.method).toBe('PUT');
    request.flush({ detail: 'isDefault' }, { status: 422, statusText: 'Unprocessable' });

    await expect(pending).rejects.toMatchObject({ code: 'invalid' });
  });

  it('revises a series with its format, the number it resumes at and when it starts again', async () => {
    const changes = { format: 'F-{EST}-{SEQ:4}', nextNumber: 12, resetPeriod: 'monthly' } as const;
    const pending = api.reviseNumberingSeries('c1', 's1', changes);
    const request = http.expectOne('/api/companies/c1/numbering-series/s1');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(changes);
    request.flush({
      id: 's1',
      establishmentId: 'e1',
      establishmentCode: '000',
      documentType: 'invoice',
      ...changes,
      isDefault: true,
      preview: 'F-000-0012',
    });

    expect((await pending).preview).toBe('F-000-0012');
  });

  it('keeps a reset period it does not know from reaching the page', async () => {
    const pending = api.numberingSeries('c1');
    http
      .expectOne('/api/companies/c1/numbering-series')
      .flush([{ id: 's1', documentType: 'invoice', resetPeriod: 'weekly' }]);

    expect((await pending)[0]?.resetPeriod).toBe('yearly');
  });
});

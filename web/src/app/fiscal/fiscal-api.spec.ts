// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { FiscalApi, FiscalRefused } from './fiscal-api';
import type { TaxComponentInput, UnitInput } from './fiscal-types';

const stamp: TaxComponentInput = {
  code: 'TIMBRE',
  name: 'Timbre fiscal',
  family: 'stamp',
  rate: null,
  amount: '1.000',
  threshold: null,
  entersVatBase: false,
  isDefault: true,
  isActive: true,
  exemptionMention: null,
  sortOrder: 50,
  names: { en: 'Revenue stamp' },
};

const tonne: UnitInput = {
  code: 'TNE',
  name: 'Tonne',
  decimals: 3,
  isActive: true,
  sortOrder: 0,
  names: { en: 'Ton' },
};

describe('FiscalApi', () => {
  let api: FiscalApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(FiscalApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('revises a tax without sending its code or family, which are fixed', async () => {
    const revised = api.reviseTaxComponent('c1', 's1', stamp, 3);
    const request = http.expectOne({
      method: 'PUT',
      url: '/api/companies/c1/tax-components/s1',
    });

    expect(request.request.body).toEqual({
      name: 'Timbre fiscal',
      rate: null,
      amount: '1.000',
      threshold: null,
      entersVatBase: false,
      isDefault: true,
      isActive: true,
      exemptionMention: null,
      sortOrder: 50,
      names: { en: 'Revenue stamp' },
      version: 3,
    });
    request.flush({ ...stamp, id: 's1', kind: 'fixed_document', version: 4 });
    await expect(revised).resolves.toMatchObject({
      id: 's1',
      code: 'TIMBRE',
      family: 'stamp',
      version: 4,
    });
  });

  it('adds a unit without an active flag, and revises one without its code', async () => {
    const created = api.createUnit('c1', tonne);
    const creation = http.expectOne({ method: 'POST', url: '/api/companies/c1/units' });
    expect(creation.request.body).toEqual({
      code: 'TNE',
      name: 'Tonne',
      decimals: 3,
      sortOrder: 0,
      names: { en: 'Ton' },
    });
    creation.flush({ ...tonne, id: 'u9' });
    await created;

    const revised = api.reviseUnit('c1', 'u9', { ...tonne, isActive: false }, 1);
    const revision = http.expectOne({ method: 'PUT', url: '/api/companies/c1/units/u9' });
    expect(revision.request.body).toEqual({
      name: 'Tonne',
      decimals: 3,
      isActive: false,
      sortOrder: 0,
      names: { en: 'Ton' },
      version: 1,
    });
    revision.flush({ ...tonne, id: 'u9', isActive: false, version: 2 });
    await expect(revised).resolves.toMatchObject({ version: 2 });
  });

  it('tells a version saved since from a code taken', async () => {
    const changed = api.reviseUnit('c1', 'u9', tonne, 1);
    http
      .expectOne({ method: 'PUT', url: '/api/companies/c1/units/u9' })
      .flush(
        { type: '/problems/record-changed', status: 409 },
        { status: 409, statusText: 'Conflict' },
      );
    await expect(changed).rejects.toMatchObject({ code: 'record_changed' });

    const stale = api.reviseTaxComponent('c1', 's1', stamp, 1);
    http
      .expectOne({ method: 'PUT', url: '/api/companies/c1/tax-components/s1' })
      .flush(
        { type: '/problems/record-changed', status: 409 },
        { status: 409, statusText: 'Conflict' },
      );
    await expect(stale).rejects.toMatchObject({ code: 'record_changed' });
  });

  it.each([
    [409, 'code_taken'],
    [422, 'invalid'],
    [404, 'not_found'],
  ])('turns a %i into the %s refusal', async (status, code) => {
    const created = api.createTaxComponent('c1', stamp);
    http
      .expectOne({ method: 'POST', url: '/api/companies/c1/tax-components' })
      .flush({ detail: 'refused' }, { status, statusText: 'Refused' });

    await expect(created).rejects.toEqual(new FiscalRefused(code as FiscalRefused['code']));
  });

  it('reports a server that cannot be reached as a network failure', async () => {
    const listed = api.units('c1');
    http
      .expectOne({ method: 'GET', url: '/api/companies/c1/units' })
      .error(new ProgressEvent('error'), { status: 0 });

    await expect(listed).rejects.toMatchObject({ code: 'network' });
  });
});

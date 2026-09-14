// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { CompanyApi, CompanyRefused } from './company-api';
import type { EstablishmentInput, EstablishmentRow, NumberingSeriesRow } from './company-types';
import { EstablishmentsFacade } from './establishments-facade';

const head: EstablishmentRow = {
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
  codeLocked: false,
};
const sfax: EstablishmentInput = { ...head, code: '001', name: 'Agence de Sfax', isDefault: false };
const invoices: NumberingSeriesRow = {
  id: 's1',
  establishmentId: 'e1',
  establishmentCode: '000',
  documentType: 'invoice',
  format: 'FAC-{YYYY}-{SEQ:5}',
  nextNumber: 1,
  resetPeriod: 'yearly',
  isDefault: true,
  numbered: false,
  preview: 'FAC-2026-00001',
};

describe('EstablishmentsFacade', () => {
  const api = {
    establishments: vi.fn(),
    createEstablishment: vi.fn(),
    reviseEstablishment: vi.fn(),
    numberingSeries: vi.fn(),
    reviseNumberingSeries: vi.fn(),
  };
  let facade: EstablishmentsFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    TestBed.configureTestingModule({ providers: [{ provide: CompanyApi, useValue: api }] });
    facade = TestBed.inject(EstablishmentsFacade);
  });

  it('reads the establishments of the company it was given', async () => {
    api.establishments.mockResolvedValue([head]);

    await facade.loadEstablishments('c1');

    expect(api.establishments).toHaveBeenCalledWith('c1');
    expect(facade.establishments()).toEqual([head]);
  });

  it('reads the list again after adding an establishment', async () => {
    api.createEstablishment.mockResolvedValue({ ...sfax, id: 'e2' });
    api.establishments.mockResolvedValue([head, { ...sfax, id: 'e2' }]);

    expect(await facade.createEstablishment('c1', sfax)).toBe(true);

    expect(api.createEstablishment).toHaveBeenCalledWith('c1', sfax);
    expect(facade.establishments().map((row) => row.code)).toEqual(['000', '001']);
  });

  it('names a code another establishment already has', async () => {
    api.createEstablishment.mockRejectedValue(new CompanyRefused('code_taken'));

    expect(await facade.createEstablishment('c1', sfax)).toBe(false);

    expect(facade.error()).toBe('code_taken');
    expect(api.establishments).not.toHaveBeenCalled();
  });

  it('reads the series again after revising one', async () => {
    const revised = { ...invoices, format: 'F-{SEQ}', preview: 'F-1' };
    api.reviseNumberingSeries.mockResolvedValue(revised);
    api.numberingSeries.mockResolvedValue([revised]);
    const changes = { format: 'F-{SEQ}', nextNumber: 1, resetPeriod: 'yearly' } as const;

    expect(await facade.reviseSeries('c1', 's1', changes)).toBe(true);

    expect(api.reviseNumberingSeries).toHaveBeenCalledWith('c1', 's1', changes);
    expect(facade.series()).toEqual([revised]);
    expect(facade.error()).toBeNull();
  });
});

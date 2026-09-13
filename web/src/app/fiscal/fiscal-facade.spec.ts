// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { FiscalApi, FiscalRefused } from './fiscal-api';
import { FiscalFacade } from './fiscal-facade';
import type {
  CustomerTaxRegimeRow,
  TaxComponentInput,
  TaxComponentRow,
  UnitRow,
} from './fiscal-types';

const vat: TaxComponentRow = {
  id: 't1',
  code: 'TVA19',
  name: 'TVA 19 %',
  kind: 'percentage_line',
  family: 'vat',
  rate: '19.000',
  amount: null,
  threshold: null,
  entersVatBase: false,
  isDefault: true,
  isActive: true,
  exemptionMention: null,
  sortOrder: 10,
};

const exportRegime: CustomerTaxRegimeRow = {
  code: 'export',
  label: 'Export',
  excludedFamilies: ['vat'],
  hasMention: true,
  sortOrder: 40,
};

const kilogram: UnitRow = {
  id: 'u1',
  code: 'KGM',
  name: 'Kilogramme',
  decimals: 3,
  isActive: true,
  sortOrder: 40,
};

const input: TaxComponentInput = {
  code: 'TVA12',
  name: 'TVA 12 %',
  family: 'vat',
  rate: '12',
  amount: null,
  threshold: null,
  entersVatBase: false,
  isDefault: false,
  isActive: true,
  exemptionMention: null,
  sortOrder: 35,
};

describe('FiscalFacade', () => {
  const api = {
    taxComponents: vi.fn(),
    createTaxComponent: vi.fn(),
    reviseTaxComponent: vi.fn(),
    units: vi.fn(),
    createUnit: vi.fn(),
    reviseUnit: vi.fn(),
    customerTaxRegimes: vi.fn(),
  };
  let facade: FiscalFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    TestBed.configureTestingModule({ providers: [{ provide: FiscalApi, useValue: api }] });
    facade = TestBed.inject(FiscalFacade);
  });

  it('loads the taxes and the customer regimes of the company it was given', async () => {
    api.taxComponents.mockResolvedValue([vat]);
    api.customerTaxRegimes.mockResolvedValue([exportRegime]);

    await facade.loadTaxes('c1');

    expect(api.taxComponents).toHaveBeenCalledWith('c1');
    expect(api.customerTaxRegimes).toHaveBeenCalledWith('c1');
    expect(facade.taxes()).toEqual([vat]);
    expect(facade.regimes()).toEqual([exportRegime]);
    expect(facade.error()).toBeNull();
  });

  it('loads the units', async () => {
    api.units.mockResolvedValue([kilogram]);

    await facade.loadUnits('c1');

    expect(facade.units()).toEqual([kilogram]);
  });

  it('re-reads the taxes after adding one, so the table shows what the server has', async () => {
    api.createTaxComponent.mockResolvedValue(vat);
    api.taxComponents.mockResolvedValue([vat]);

    const accepted = await facade.createTax('c1', input);

    expect(accepted).toBe(true);
    expect(api.createTaxComponent).toHaveBeenCalledWith('c1', input);
    expect(facade.taxes()).toEqual([vat]);
  });

  it('reports a taken code rather than pretending it worked, and does not re-read', async () => {
    api.createTaxComponent.mockRejectedValue(new FiscalRefused('code_taken'));

    const accepted = await facade.createTax('c1', input);

    expect(accepted).toBe(false);
    expect(facade.error()).toBe('code_taken');
    expect(api.taxComponents).not.toHaveBeenCalled();
  });

  it('revises a tax by its identifier', async () => {
    api.reviseTaxComponent.mockResolvedValue(vat);
    api.taxComponents.mockResolvedValue([vat]);

    expect(await facade.reviseTax('c1', 't1', input)).toBe(true);
    expect(api.reviseTaxComponent).toHaveBeenCalledWith('c1', 't1', input);
  });

  it('reports a unit the API refused', async () => {
    api.reviseUnit.mockRejectedValue(new FiscalRefused('invalid'));

    const accepted = await facade.reviseUnit('c1', 'u1', { ...kilogram, decimals: 7 });

    expect(accepted).toBe(false);
    expect(facade.error()).toBe('invalid');
  });

  it('keeps an unexpected failure as a network error, and is not busy once it settled', async () => {
    api.units.mockRejectedValue(new Error('boom'));

    await facade.loadUnits('c1');

    expect(facade.error()).toBe('network');
    expect(facade.busy()).toBe(false);
  });

  it('forgets an error when asked', async () => {
    api.units.mockRejectedValue(new FiscalRefused('not_found'));
    await facade.loadUnits('c1');

    facade.clearError();

    expect(facade.error()).toBeNull();
  });
});

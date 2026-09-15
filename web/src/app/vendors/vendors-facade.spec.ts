// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { VendorsApi, VendorsRefused } from './vendors-api';
import { VendorsFacade } from './vendors-facade';
import type { VendorInput, VendorOptions, VendorRow } from './vendors-types';

const input: VendorInput = {
  number: 'FRN-0001',
  name: 'Sotumag',
  legalName: null,
  identifiers: {},
  email: null,
  phone: null,
  website: null,
  address: { line1: null, line2: null, postalCode: null, city: null, countryCode: 'TN' },
  iban: null,
  bic: null,
  paymentTermsDays: null,
  notes: null,
  isActive: true,
};
const sotumag: VendorRow = { ...input, id: 'v1' };
const options: VendorOptions = { countryCode: 'TN', identifiers: [] };

describe('VendorsFacade', () => {
  const api = {
    options: vi.fn(),
    vendors: vi.fn(),
    vendor: vi.fn(),
    createVendor: vi.fn(),
    reviseVendor: vi.fn(),
  };
  let facade: VendorsFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    TestBed.configureTestingModule({ providers: [{ provide: VendorsApi, useValue: api }] });
    facade = TestBed.inject(VendorsFacade);
  });

  it("lists the company's vendors", async () => {
    api.vendors.mockResolvedValue([sotumag]);

    await facade.loadList('c1');

    expect(api.vendors).toHaveBeenCalledWith('c1');
    expect(facade.vendors()).toEqual([sotumag]);
    expect(facade.error()).toBeNull();
  });

  it('reads only the options for a new vendor, and the vendor too for an existing one', async () => {
    api.options.mockResolvedValue(options);
    api.vendor.mockResolvedValue(sotumag);

    await facade.loadVendor('c1', null);
    expect(api.vendor).not.toHaveBeenCalled();
    expect([facade.options(), facade.vendor()]).toEqual([options, null]);

    await facade.loadVendor('c1', 'v1');
    expect(api.vendor).toHaveBeenCalledWith('c1', 'v1');
    expect(facade.vendor()).toEqual(sotumag);
  });

  it('keeps what the API refused, and nothing it did not keep', async () => {
    api.createVendor.mockRejectedValue(new VendorsRefused('number_taken'));

    expect(await facade.createVendor('c1', input)).toBeNull();
    expect(facade.error()).toBe('number_taken');
    expect(facade.busy()).toBe(false);

    api.reviseVendor.mockResolvedValue(sotumag);
    expect(await facade.reviseVendor('c1', 'v1', input)).toEqual(sotumag);
    expect([facade.error(), facade.vendor()]).toEqual([null, sotumag]);
  });
});

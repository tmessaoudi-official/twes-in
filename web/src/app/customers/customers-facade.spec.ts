// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { CustomFieldsApi } from '../shared/custom-fields/custom-fields-api';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { CustomersApi, CustomersRefused } from './customers-api';
import { CustomersFacade } from './customers-facade';
import type { ContactInput, CustomerGroupRow, CustomerInput, CustomerRow } from './customers-types';

const input: CustomerInput = {
  number: 'CLI-0001',
  kind: 'individual',
  customerGroupId: null,
  taxRegime: 'standard',
  name: 'Amel',
  legalName: null,
  identifiers: {},
  email: null,
  phone: null,
  website: null,
  billingAddress: { line1: null, line2: null, postalCode: null, city: null, countryCode: 'TN' },
  shippingAddress: null,
  defaultTaxComponentIds: [],
  defaultDiscountRate: null,
  notes: null,
  isActive: true,
  customFields: {},
};
const sector: CustomFieldDefinition = {
  id: 'f1',
  entity: 'customer',
  key: 'sector',
  label: 'Secteur',
  type: 'text',
  required: false,
  choices: [],
  sortOrder: 0,
  isActive: true,
};
const amel: CustomerRow = { ...input, id: 'k1' };
const wholesalers: CustomerGroupRow = {
  id: 'g1',
  name: 'Grossistes',
  description: null,
  customerCount: 0,
};
const leila: ContactInput = {
  firstName: 'Leila',
  lastName: null,
  email: null,
  phone: null,
  role: null,
  isPrimary: true,
};

describe('CustomersFacade', () => {
  const api = {
    options: vi.fn(),
    customers: vi.fn(),
    customer: vi.fn(),
    createCustomer: vi.fn(),
    reviseCustomer: vi.fn(),
    groups: vi.fn(),
    createGroup: vi.fn(),
    reviseGroup: vi.fn(),
    deleteGroup: vi.fn(),
    contacts: vi.fn(),
    addContact: vi.fn(),
    reviseContact: vi.fn(),
    removeContact: vi.fn(),
  };
  const fieldsApi = { list: vi.fn() };
  let facade: CustomersFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    fieldsApi.list.mockReset().mockResolvedValue([sector]);
    TestBed.configureTestingModule({
      providers: [
        { provide: CustomersApi, useValue: api },
        { provide: CustomFieldsApi, useValue: fieldsApi },
      ],
    });
    facade = TestBed.inject(CustomersFacade);
  });

  it('reads the customers and the groups the list names', async () => {
    api.customers.mockResolvedValue([amel]);
    api.groups.mockResolvedValue([wholesalers]);

    await facade.loadList('c1');

    expect(facade.customers()).toEqual([amel]);
    expect(facade.groups()).toEqual([wholesalers]);
    expect(facade.customFields()).toEqual([sector]);
    expect(fieldsApi.list).toHaveBeenCalledWith('c1', 'customer');
    expect(facade.error()).toBeNull();
  });

  it('opens one customer with its contacts, and a new one with none', async () => {
    api.customer.mockResolvedValue(amel);
    api.contacts.mockResolvedValue([{ ...leila, id: 'p1' }]);

    await facade.loadCustomer('c1', 'k1');
    expect(facade.customer()).toEqual(amel);
    expect(facade.contacts().map((contact) => contact.id)).toEqual(['p1']);
    expect(facade.customFields()).toEqual([sector]);

    await facade.loadCustomer('c1', null);
    expect(facade.customer()).toBeNull();
    expect(facade.contacts()).toEqual([]);
  });

  it('answers the created customer, or null naming why it was refused', async () => {
    api.createCustomer.mockResolvedValueOnce(amel);
    expect(await facade.createCustomer('c1', input)).toEqual(amel);
    expect(facade.customer()).toEqual(amel);

    api.createCustomer.mockRejectedValueOnce(new CustomersRefused('number_taken'));
    expect(await facade.createCustomer('c1', input)).toBeNull();
    expect(facade.error()).toBe('number_taken');
  });

  it('reads the groups again after deleting one, and keeps a group still in use', async () => {
    api.deleteGroup.mockResolvedValueOnce(undefined);
    api.groups.mockResolvedValue([]);
    expect(await facade.deleteGroup('c1', 'g1')).toBe(true);
    expect(api.groups).toHaveBeenCalledWith('c1');

    api.deleteGroup.mockRejectedValueOnce(new CustomersRefused('in_use'));
    expect(await facade.deleteGroup('c1', 'g1')).toBe(false);
    expect(facade.error()).toBe('in_use');
  });

  it('reads the contacts again after a change, since a new primary contact touches two of them', async () => {
    api.reviseContact.mockResolvedValue({ ...leila, id: 'p2' });
    api.contacts.mockResolvedValue([
      { ...leila, id: 'p2' },
      { ...leila, id: 'p1', isPrimary: false },
    ]);

    expect(await facade.reviseContact('c1', 'k1', 'p2', leila)).toBe(true);

    expect(api.contacts).toHaveBeenCalledWith('c1', 'k1');
    expect(facade.contacts().map((contact) => [contact.id, contact.isPrimary])).toEqual([
      ['p2', true],
      ['p1', false],
    ]);
  });
});

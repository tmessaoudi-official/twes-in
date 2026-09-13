// SPDX-License-Identifier: AGPL-3.0-or-later

import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import {
  customerForm,
  customerInput,
  customersList,
  customerValues,
  type CustomerListRow,
} from './customer-forms';
import type { CustomerOptions, CustomerRow } from './customers-types';

const options: CustomerOptions = { countryCode: 'TN', identifiers: [], regimes: [], taxes: [] };
const sector: CustomFieldDefinition = {
  id: 'f1',
  entity: 'customer',
  key: 'sector',
  label: 'Secteur',
  type: 'choice',
  required: true,
  choices: ['retail', 'wholesale'],
  sortOrder: 0,
  isActive: true,
};
const row = {
  id: 'k1',
  number: 'CLI-1',
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
  customFields: { sector: 'retail' },
} satisfies CustomerRow;

describe("a company's custom fields on its customers", () => {
  it('adds a section of the active fields to the form, and none when there are none', () => {
    const form = customerForm(options, [], [sector]);

    expect(form.sections.at(-1)).toMatchObject({
      id: 'custom',
      title: 'customers.sections.custom',
      fields: [{ id: 'custom__sector', label: 'Secteur', kind: 'select', required: true }],
    });
    expect(customerForm(options, []).sections.some((each) => each.id === 'custom')).toBe(false);
    expect(
      customerForm(options, [], [{ ...sector, isActive: false }]).sections.some(
        (each) => each.id === 'custom',
      ),
    ).toBe(false);
  });

  it('starts at what the customer holds and sends what was filled in', () => {
    const values = customerValues(row, options, [sector]);
    expect(values['custom__sector']).toBe('retail');

    expect(
      customerInput({ ...values, custom__sector: 'wholesale' }, options, [sector]).customFields,
    ).toEqual({ sector: 'wholesale' });
    expect(customerInput(values, options).customFields).toEqual({});
  });

  it('offers each active field as a column hidden until placed', () => {
    const list = customersList([sector]);
    const column = list.columns.find((each) => each.id === 'custom__sector');

    expect(column?.defaultHidden).toBe(true);
    expect(column?.value({ ...row, groupName: null } satisfies CustomerListRow)).toBe('retail');
    expect(customersList([]).columns.map((each) => each.id)).not.toContain('custom__sector');
  });
});

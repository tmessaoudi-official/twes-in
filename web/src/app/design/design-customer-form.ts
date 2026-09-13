// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormDescriptor } from '../shared/form/form-types';

/** The customer form as configuration: the shape the real form takes at G3a, rendered by DescriptorForm. */
export const DESIGN_CUSTOMER_FORM: FormDescriptor = {
  id: 'design-customer',
  sections: [
    {
      id: 'identity',
      title: 'design.customer_form.identity',
      fields: [
        {
          id: 'name',
          label: 'design.customer_form.name',
          kind: 'text',
          required: true,
          maxLength: 120,
          span: 2,
          autocomplete: 'organization',
        },
        {
          id: 'email',
          label: 'design.customer_form.email',
          kind: 'email',
          maxLength: 180,
          autocomplete: 'email',
        },
        {
          id: 'phone',
          label: 'design.customer_form.phone',
          kind: 'tel',
          pattern: '[+0-9 ().-]{6,20}',
          autocomplete: 'tel',
        },
      ],
    },
    {
      id: 'address',
      title: 'design.customer_form.address',
      fields: [
        {
          id: 'street',
          label: 'design.customer_form.street',
          kind: 'text',
          maxLength: 200,
          span: 2,
          autocomplete: 'street-address',
        },
        {
          id: 'postal_code',
          label: 'design.customer_form.postal_code',
          kind: 'text',
          maxLength: 10,
          autocomplete: 'postal-code',
        },
        {
          id: 'city',
          label: 'design.customer_form.city',
          kind: 'text',
          required: true,
          maxLength: 80,
          autocomplete: 'address-level2',
        },
        {
          id: 'country',
          label: 'design.customer_form.country',
          kind: 'select',
          required: true,
          defaultValue: 'TN',
          options: [
            { value: 'TN', label: 'design.countries.TN' },
            { value: 'FR', label: 'design.countries.FR' },
          ],
        },
      ],
    },
    {
      id: 'billing',
      title: 'design.customer_form.billing',
      fields: [
        {
          id: 'vat',
          label: 'design.customer_form.vat',
          kind: 'text',
          pattern: '[A-Z0-9/ -]{4,30}',
        },
        {
          id: 'payment_terms',
          label: 'design.customer_form.payment_terms',
          kind: 'number',
          min: 0,
          max: 365,
          defaultValue: 30,
        },
        {
          id: 'currency',
          label: 'design.customer_form.currency',
          kind: 'select',
          required: true,
          defaultValue: 'TND',
          options: [
            { value: 'TND', label: 'TND' },
            { value: 'EUR', label: 'EUR' },
          ],
        },
      ],
    },
  ],
};

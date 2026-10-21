// SPDX-License-Identifier: AGPL-3.0-or-later

import { refusalsIn } from './field-refusals';

describe('refusalsIn', () => {
  it('reads each violation as its field, its reason and that text’s parameters', () => {
    expect(
      refusalsIn({
        violations: [
          { propertyPath: 'email', message: 'x', payload: { reason: 'invalid_email' } },
          {
            propertyPath: 'billingCity',
            message: 'x',
            payload: { reason: 'invalid_text', params: { max: 120 } },
          },
        ],
      }),
    ).toEqual([
      { field: 'email', reason: 'invalid_email', params: {} },
      { field: 'billingCity', reason: 'invalid_text', params: { max: 120 } },
    ]);
  });

  it('puts a registration number or a custom field on its own field, however the API wrote its path', () => {
    expect(
      refusalsIn({
        violations: [
          {
            propertyPath: 'identifiers.matricule_fiscal',
            payload: { reason: 'identifier_shape' },
          },
          { propertyPath: 'identifiers[siren]', payload: { reason: 'identifier_shape' } },
          { propertyPath: 'customFields.vip', payload: { reason: 'invalid_value' } },
        ],
      }).map((refusal) => refusal.field),
    ).toEqual(['identifier__matricule_fiscal', 'identifier__siren', 'custom__vip']);
  });

  it('puts a refusal of one entry of a list on the list’s field, which is what the form shows', () => {
    expect(
      refusalsIn({
        violations: [
          { propertyPath: 'vendorIds.1', payload: { reason: 'unknown_choice' } },
          { propertyPath: 'defaultTaxComponentIds[0]', payload: { reason: 'unknown_choice' } },
          { propertyPath: 'barcodes.0.code', payload: { reason: 'invalid_value' } },
        ],
      }).map((refusal) => refusal.field),
    ).toEqual(['vendorIds', 'defaultTaxComponentIds', 'barcodes.0.code']);
  });

  it('keeps a refusal without a reason, for the screen to say generically', () => {
    expect(refusalsIn({ violations: [{ propertyPath: 'kind', code: 'abc' }] })).toEqual([
      { field: 'kind', reason: null, params: {} },
    ]);
  });

  it('finds none in a body that names no field', () => {
    expect(refusalsIn({ detail: 'no violations' })).toEqual([]);
    expect(refusalsIn({ violations: [{ message: 'no path' }] })).toEqual([]);
    expect(refusalsIn(null)).toEqual([]);
  });

  it('drops parameters that are not plain values', () => {
    expect(
      refusalsIn({
        violations: [
          {
            propertyPath: 'name',
            payload: { reason: 'invalid_name', params: { max: 200, x: {} } },
          },
        ],
      })[0]?.params,
    ).toEqual({ max: 200 });
  });
});

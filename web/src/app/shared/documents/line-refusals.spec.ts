// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { lineRefusal } from './line-refusals';

describe('lineRefusal', () => {
  it.each([
    ['lines[2].quantity', { index: 2, field: 'quantity' }],
    ['lines.2.quantity', { index: 2, field: 'quantity' }],
    ['lines[0][description]', { index: 0, field: 'description' }],
    ['lines[1].taxComponentIds[1]', { index: 1, field: 'taxComponentIds' }],
    ['lines[1][taxComponentIds][0]', { index: 1, field: 'taxComponentIds' }],
  ])('reads %s as a line and its field', (path, expected) => {
    expect(lineRefusal(path)).toEqual(expected);
  });

  it.each([
    'lines',
    'lines[0]',
    'customerId',
    'documentTaxComponentIds[0]',
    'lines[x].quantity',
    'lines[0].a.b',
  ])('reads %s as the document’s own', (path) => {
    expect(lineRefusal(path)).toBeNull();
  });
});

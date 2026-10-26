// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormArray, FormControl, FormGroup } from '@angular/forms';
import type { TranslateService } from '@ngx-translate/core';
import { describe, expect, it } from 'vitest';
import type { RefusalFigures } from '../form/refusal-figures';
import { LineRefusals, lineRefusal, refusedOn } from './line-refusals';

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

describe('LineRefusals', () => {
  const translate = {
    instant: (key: string) => (key === 'import.rejections.left_to_deliver' ? 'words' : key),
  } as unknown as TranslateService;
  const figures = {
    written: (params: Record<string, unknown>) => ({
      ...params,
      quantity: `«${String(params['quantity'])}»`,
    }),
  } as unknown as RefusalFigures;

  it('says a line’s figures in the reader’s formats and hands back the document’s own', () => {
    const lines = new FormArray([new FormGroup({ quantity: new FormControl('9') })]);
    const refusals = new LineRefusals(
      () => lines,
      translate,
      figures,
      () => undefined,
    );

    const rest = refusals.show([
      { field: 'lines[0].quantity', reason: 'left_to_deliver', params: { quantity: '2.500' } },
      { field: 'lines', reason: 'lines_required', params: {} },
    ]);

    expect(refusedOn(lines.at(0).controls.quantity)).toEqual({
      key: 'import.rejections.left_to_deliver',
      params: { quantity: '«2.500»' },
    });
    expect(rest).toEqual([{ field: 'lines', reason: 'lines_required', params: {} }]);
  });
});

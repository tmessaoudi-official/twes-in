// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import type { TranslateService } from '@ngx-translate/core';
import { describe, expect, it } from 'vitest';
import type { FieldRefusal } from '../form/field-refusals';
import type { RefusalFigures } from '../form/refusal-figures';
import { RefusalLine } from './refusal-line';

describe('RefusalLine', () => {
  const translate = {
    instant: (key: string) => (key.startsWith('import.rejections.') ? 'words' : key),
  } as unknown as TranslateService;
  const figures = { written: <P>(params: P) => params } as unknown as RefusalFigures;
  const lines: FieldRefusal = { field: 'lines', reason: 'lines_required', params: {} };
  const quantity: FieldRefusal = {
    field: 'lines[0].quantity',
    reason: 'left_to_deliver',
    params: { quantity: '2' },
  };

  function line(refusals: readonly FieldRefusal[]) {
    const kept = signal(refusals);
    return { kept, line: new RefusalLine(kept, 'invoices.errors.', translate, figures) };
  }

  it('says a code with no refusals behind it in the code’s own words', () => {
    expect(line([]).line.for('invalid')).toEqual({ key: 'invoices.errors.invalid', params: {} });
    expect(line([]).line.for('conflict')).toEqual({ key: 'invoices.errors.conflict', params: {} });
    expect(line([lines]).line.for('not_found')).toEqual({
      key: 'invoices.errors.not_found',
      params: {},
    });
  });

  it('says a move the document’s state refuses in its reason’s words, as a refused value', () => {
    const cancelled: FieldRefusal = {
      field: '',
      reason: 'note_not_cancellable',
      params: { reference: 'BL-1' },
    };

    expect(line([cancelled]).line.for('conflict')).toEqual({
      key: 'import.rejections.note_not_cancellable',
      params: { reference: 'BL-1' },
    });
  });

  it('says nothing more once every refusal is said under its field', () => {
    const { kept, line: said } = line([quantity]);

    said.placed(kept(), []);

    expect(said.for('invalid')).toBeNull();
  });

  it('says what no field took in its own words', () => {
    const { kept, line: said } = line([quantity, lines]);

    said.placed(kept(), [lines]);

    expect(said.for('invalid')).toEqual({ key: 'import.rejections.lines_required', params: {} });
  });

  it('says every refusal of a step no field was given, the first in its own words', () => {
    expect(line([lines, quantity]).line.for('invalid')).toEqual({
      key: 'import.rejections.lines_required',
      params: {},
    });
  });

  it('reads a later refusal afresh rather than what an earlier one placed', () => {
    const { kept, line: said } = line([quantity]);
    said.placed(kept(), []);

    kept.set([lines]);

    expect(said.for('invalid')).toEqual({ key: 'import.rejections.lines_required', params: {} });
  });
});

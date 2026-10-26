// SPDX-License-Identifier: AGPL-3.0-or-later

import type { TranslateService } from '@ngx-translate/core';
import { describe, expect, it } from 'vitest';
import { FormStep } from './form-step';
import type { FieldRefusal } from './field-refusals';
import type { RefusalFigures } from './refusal-figures';

describe('FormStep', () => {
  const translate = {
    instant: (key: string) => (key.startsWith('import.rejections.') ? 'words' : key),
  } as unknown as TranslateService;
  const figures = {
    written: <P>(params: P) => ({ ...params, amount: '12,000' }) as P,
  } as unknown as RefusalFigures;
  const amount: FieldRefusal = {
    field: 'amount',
    reason: 'above_due',
    params: { amount: '12.000' },
  };
  const lines: FieldRefusal = { field: 'lines', reason: 'lines_required', params: {} };

  it('answers what was kept, for the dialog to close on', async () => {
    const step = new FormStep(translate, figures);

    expect(
      await step.run(
        () => Promise.resolve({ kept: 'p1' }),
        () => [],
      ),
    ).toEqual({ kept: 'p1' });
    expect(step.unplaced()).toBeNull();
    expect(step.busy()).toBe(false);
  });

  it('answers a failure no field names, for the dialog to close on and the page to say', async () => {
    const step = new FormStep(translate, figures);

    expect(
      await step.run(
        () => Promise.resolve(null),
        () => [],
      ),
    ).toBeNull();
  });

  it('holds the dialog on a refusal, says under their fields what they take and the first left on its own line', async () => {
    const step = new FormStep(translate, figures);
    let placed: readonly FieldRefusal[] = [];

    const answer = await step.run(
      () => Promise.resolve({ refused: [amount, lines] }),
      (refusals) => {
        placed = refusals;
        return refusals.filter((refusal) => refusal.field !== 'amount');
      },
    );

    expect(answer).toBe('refused');
    expect(placed).toEqual([amount, lines]);
    expect(step.unplaced()).toEqual({
      key: 'import.rejections.lines_required',
      params: { amount: '12,000' },
    });
  });

  it('says nothing on its own line once every refusal is under its field, and forgets it on the next try', async () => {
    const step = new FormStep(translate, figures);
    await step.run(
      () => Promise.resolve({ refused: [lines] }),
      (refusals) => refusals,
    );
    expect(step.unplaced()).not.toBeNull();

    let seen: unknown = 'not run';
    await step.run(
      () => {
        seen = step.unplaced();
        expect(step.busy()).toBe(true);
        return Promise.resolve({ refused: [amount] });
      },
      () => [],
    );

    expect(seen).toBeNull();
    expect(step.unplaced()).toBeNull();
    expect(step.busy()).toBe(false);
  });
});

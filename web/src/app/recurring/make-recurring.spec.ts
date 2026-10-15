// SPDX-License-Identifier: AGPL-3.0-or-later

import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { makeRecurringForm, recurringDraft, recurringFailure } from './make-recurring';

describe('making an invoice recurring', () => {
  it('asks when on the shared schedule, from which day (today unless changed) and up to which, monthly on today’s date by default', () => {
    const descriptor = makeRecurringForm('fr', '2026-10-08');
    const group = buildFormGroup(descriptor, {});

    expect(descriptor.sections[0].fields.map((field) => field.id)).toEqual([
      'unit',
      'every',
      'anchor',
      'monthDay',
      'ordinal',
      'weekday',
      'startsOn',
      'endsOn',
    ]);
    expect(recurringDraft('i1', group.getRawValue() as FormValues)).toEqual({
      modelInvoiceId: 'i1',
      schedule: {
        unit: 'month',
        every: 1,
        anchor: 'date',
        monthDay: 8,
        ordinal: null,
        weekday: null,
      },
      startsOn: '2026-10-08',
      endsOn: null,
    });
    expect(group.valid).toBe(true);
  });

  it('sends what the form holds, any interval and a month’s last weekday, an empty last day as none', () => {
    expect(
      recurringDraft('i1', {
        unit: 'month',
        every: 2,
        anchor: 'last_weekday',
        monthDay: 8,
        weekday: '5',
        startsOn: '2026-10-31',
        endsOn: '',
      }),
    ).toEqual({
      modelInvoiceId: 'i1',
      schedule: {
        unit: 'month',
        every: 2,
        anchor: 'last_weekday',
        monthDay: null,
        ordinal: null,
        weekday: 5,
      },
      startsOn: '2026-10-31',
      endsOn: null,
    });
    expect(
      recurringDraft('i1', { unit: 'week', every: 1, startsOn: '2026-10-31', endsOn: '2030-10-31' })
        .endsOn,
    ).toBe('2030-10-31');
  });

  it('says a refusal in the schedule’s words for its fields, the invoice’s own for theirs, the general line otherwise', () => {
    expect(recurringFailure('monthDay')).toBe('schedule.errors.monthDay');
    expect(recurringFailure('modelInvoiceId')).toBe('recurring.errors.modelInvoiceId');
    expect(recurringFailure('startsOn')).toBe('recurring.errors.startsOn');
    expect(recurringFailure('unit')).toBe('recurring.errors.invalid');
    expect(recurringFailure(null)).toBe('recurring.errors.invalid');
  });
});

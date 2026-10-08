// SPDX-License-Identifier: AGPL-3.0-or-later

import { buildFormGroup } from '../shared/form/form-builder';
import { makeRecurringForm, recurringDraft } from './make-recurring';

describe('making an invoice recurring', () => {
  it('asks how often, from which day (today unless changed) and up to which, monthly by default', () => {
    const descriptor = makeRecurringForm('2026-10-08');
    const group = buildFormGroup(descriptor, {});

    expect(descriptor.sections[0].fields.map((field) => field.id)).toEqual([
      'frequency',
      'startsOn',
      'endsOn',
    ]);
    expect(group.getRawValue()).toEqual(
      expect.objectContaining({ frequency: 'monthly', startsOn: '2026-10-08' }),
    );
    expect(group.valid).toBe(true);
  });

  it('sends what the form holds, an empty last day as none', () => {
    expect(
      recurringDraft('i1', { frequency: 'quarterly', startsOn: '2026-10-31', endsOn: '' }),
    ).toEqual({
      modelInvoiceId: 'i1',
      frequency: 'quarterly',
      startsOn: '2026-10-31',
      endsOn: null,
    });
    expect(
      recurringDraft('i1', { frequency: 'yearly', startsOn: '2026-10-31', endsOn: '2030-10-31' })
        .endsOn,
    ).toBe('2030-10-31');
  });
});

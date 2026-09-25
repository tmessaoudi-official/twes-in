// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  DELIVERY_NOTE_STATUS_STAGES,
  DELIVERY_NOTE_STATUS_TONES,
} from './delivery-notes/delivery-notes-types';
import { EXPENSE_STATUS_STAGES, EXPENSE_STATUS_TONES } from './expenses/expenses-types';
import { INVOICE_STATUS_STAGES, INVOICE_STATUS_TONES } from './invoices/invoices-types';
import { LIFECYCLE_TONES, type LifecycleStage, tonesOf } from './shared/theme/lifecycle-tones';

// Design direction § 1.1 (docs/SPEC.md § 7, 2026-09-24 22:51): one map of lifecycle stages, from which every module's
// status tones derive, so the same stage has one tone everywhere.
describe('the lifecycle tones', () => {
  const modules = [
    ['invoice', INVOICE_STATUS_STAGES, INVOICE_STATUS_TONES],
    ['delivery note', DELIVERY_NOTE_STATUS_STAGES, DELIVERY_NOTE_STATUS_TONES],
    ['expense', EXPENSE_STATUS_STAGES, EXPENSE_STATUS_TONES],
  ] as const;

  it('gives a stage one tone in every module', () => {
    for (const [name, stages, tones] of modules) {
      for (const [status, stage] of Object.entries(stages)) {
        expect((tones as Record<string, string>)[status], `${name} ${status}`).toBe(
          LIFECYCLE_TONES[stage],
        );
      }
      expect(tones).toEqual(tonesOf(stages as Readonly<Record<string, LifecycleStage>>));
    }
  });

  it('places each status on the stage the direction’s table names', () => {
    expect(INVOICE_STATUS_STAGES).toEqual({
      draft: 'not-started',
      issued: 'under-way',
      partially_paid: 'needs-action',
      paid: 'done',
      cancelled: 'withdrawn',
      overdue: 'alarm',
    });
    expect(DELIVERY_NOTE_STATUS_STAGES).toEqual({
      draft: 'not-started',
      validated: 'under-way',
      delivered: 'needs-action',
      invoiced: 'done',
      cancelled: 'withdrawn',
    });
    expect(EXPENSE_STATUS_STAGES).toEqual({
      draft: 'not-started',
      recorded: 'under-way',
      paid: 'done',
    });
  });
});

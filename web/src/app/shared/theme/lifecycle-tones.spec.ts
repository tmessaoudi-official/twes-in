// SPDX-License-Identifier: AGPL-3.0-or-later

import { LIFECYCLE_TONES, tonesOf, withdrawn } from './lifecycle-tones';

// Design direction § 1.1: one map of lifecycle stages; each module's statuses are checked against it in
// web/src/app/status-stages.spec.ts, since shared/ imports no feature.
describe('the lifecycle tones', () => {
  it('draws a withdrawn status neutral and struck, and an alarm in danger', () => {
    expect(LIFECYCLE_TONES['withdrawn']).toBe('neutral');
    expect(LIFECYCLE_TONES['alarm']).toBe('danger');
    expect(LIFECYCLE_TONES['linked']).toBe('purple');
    expect(withdrawn('withdrawn')).toBe(true);
    expect(withdrawn('done')).toBe(false);
  });

  it('derives a module’s tones from the stages of its statuses', () => {
    expect(tonesOf({ open: 'under-way', gone: 'withdrawn', late: 'alarm' })).toEqual({
      open: 'info',
      gone: 'neutral',
      late: 'danger',
    });
  });
});

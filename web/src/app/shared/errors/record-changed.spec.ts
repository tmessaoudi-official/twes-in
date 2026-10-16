// SPDX-License-Identifier: AGPL-3.0-or-later

import { isRecordChanged, RECORD_CHANGED } from './record-changed';

describe('isRecordChanged', () => {
  it('reads the problem type', () => {
    expect(isRecordChanged({ type: RECORD_CHANGED, status: 409 })).toBe(true);
  });

  it('leaves the request’s other conflicts to it', () => {
    expect(isRecordChanged({ type: '/errors/409', detail: 'number taken' })).toBe(false);
    expect(isRecordChanged(null)).toBe(false);
    expect(isRecordChanged('Conflict')).toBe(false);
  });
});

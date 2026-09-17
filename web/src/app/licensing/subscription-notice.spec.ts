// SPDX-License-Identifier: AGPL-3.0-or-later

import { NOTICE_FROM_DAYS, noticeFor } from './subscription-notice';

describe('the subscription notice', () => {
  it('counts down the last days of a trial or a paid period, and says nothing earlier', () => {
    expect(noticeFor('trial', NOTICE_FROM_DAYS, 'full')).toEqual({
      key: 'ending',
      days: NOTICE_FROM_DAYS,
      tone: 'warn',
    });
    expect(noticeFor('paid', 1, 'full')).toEqual({ key: 'ending', days: 1, tone: 'warn' });
    expect(noticeFor('paid', NOTICE_FROM_DAYS + 1, 'full')).toBeNull();
    expect(noticeFor(null, null, 'full')).toBeNull();
  });

  it('says a period has ended while grace runs, and what is left once it has not been settled', () => {
    expect(noticeFor('grace', 3, 'full')).toEqual({ key: 'grace', days: 3, tone: 'error' });
    expect(noticeFor('unpaid', null, 'read_only')).toEqual({
      key: 'read_only',
      days: 0,
      tone: 'error',
    });
  });

  it('speaks of what the company may do before what stage it is in', () => {
    // A company read-only for another reason than its own dates would still be told what it may do.
    expect(noticeFor('grace', 2, 'read_only')?.key).toBe('read_only');
  });
});

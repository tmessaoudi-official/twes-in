// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { SCAN_COUNT_MAX, ScanCount } from './scan-count';

function typed(count: ScanCount, keys: string, from = 0, gap = 200): (number | null)[] {
  return [...keys].map((key, at) => count.read(key, from + at * gap));
}

describe('ScanCount', () => {
  it('reads digits then a times sign as the count of the next scan', () => {
    expect(typed(new ScanCount(), '5*').at(-1)).toBe(5);
    expect(typed(new ScanCount(), '12x').at(-1)).toBe(12);
    expect(typed(new ScanCount(), '3X').at(-1)).toBe(3);
    expect(typed(new ScanCount(), '4×').at(-1)).toBe(4);
  });

  it('answers nothing until the times sign, and nothing for a times sign alone', () => {
    expect(typed(new ScanCount(), '12')).toEqual([null, null]);
    expect(typed(new ScanCount(), 'x')).toEqual([null]);
  });

  it('forgets digits interrupted by another key, or left too long', () => {
    expect(typed(new ScanCount(), '5ax').at(-1)).toBeNull();
    expect(typed(new ScanCount(), '5x', 0, 5000).at(-1)).toBeNull();
  });

  it('refuses a count of none or beyond what a till takes', () => {
    expect(typed(new ScanCount(), '0x').at(-1)).toBeNull();
    expect(typed(new ScanCount(), `${SCAN_COUNT_MAX + 1}x`).at(-1)).toBeNull();
    expect(typed(new ScanCount(), `${SCAN_COUNT_MAX}x`).at(-1)).toBe(SCAN_COUNT_MAX);
  });

  it('starts over after a count and after a reset', () => {
    const count = new ScanCount();
    typed(count, '5x');
    expect(count.read('x', 2000)).toBeNull();
    count.read('7', 3000);
    count.reset();
    expect(count.read('x', 3100)).toBeNull();
  });
});

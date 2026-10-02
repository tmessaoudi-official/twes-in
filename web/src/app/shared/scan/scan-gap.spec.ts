// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it } from 'vitest';
import { PageMemoryStorage, SETTINGS_STORAGE } from '../settings/settings-facade';
import { SCAN_GAP_KEY, ScanGap, suggestGap } from './scan-gap';

describe('ScanGap', () => {
  let storage: PageMemoryStorage;

  const create = (): ScanGap => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [{ provide: SETTINGS_STORAGE, useValue: storage }],
    });
    return TestBed.inject(ScanGap);
  };

  beforeEach(() => {
    storage = new PageMemoryStorage();
  });

  it('is thirty milliseconds until this browser says otherwise, and remembers what it says', () => {
    expect(create().gap()).toBe(30);

    const gap = create();
    gap.set(45);
    expect(gap.gap()).toBe(45);
    expect(storage.getItem(SCAN_GAP_KEY)).toBe('45');
    expect(create().gap()).toBe(45);
  });

  it('ignores a stored value outside the range a scanner can be told from a hand within', () => {
    storage.setItem(SCAN_GAP_KEY, '5');
    expect(create().gap()).toBe(30);
    storage.setItem(SCAN_GAP_KEY, '4000');
    expect(create().gap()).toBe(30);
    storage.setItem(SCAN_GAP_KEY, 'quick');
    expect(create().gap()).toBe(30);
  });

  it('refuses to set a value outside that range, and goes back to the default on reset', () => {
    const gap = create();
    gap.set(45);
    gap.set(500);
    expect(gap.gap()).toBe(45);

    gap.reset();
    expect(gap.gap()).toBe(30);
    expect(storage.getItem(SCAN_GAP_KEY)).toBeNull();
  });
});

describe('suggestGap', () => {
  const keys = (...intervals: number[]): number[] =>
    intervals.reduce<number[]>((times, each) => [...times, (times.at(-1) ?? 0) + each], [0]);

  it('allows three times the slowest interval of a burst, to the next five milliseconds', () => {
    expect(suggestGap(keys(8, 7, 9, 8, 8))).toBe(30);
    expect(suggestGap(keys(12, 12, 14, 12))).toBe(45);
  });

  it('never suggests less than twenty or more than sixty milliseconds', () => {
    expect(suggestGap(keys(1, 2, 2, 1, 2))).toBe(20);
    expect(suggestGap(keys(30, 28, 31, 29))).toBe(60);
  });

  it('suggests nothing from too few keys, or from a hand typing', () => {
    expect(suggestGap(keys(2, 2))).toBeNull();
    expect(suggestGap(keys(120, 150, 130, 140, 160))).toBeNull();
  });
});

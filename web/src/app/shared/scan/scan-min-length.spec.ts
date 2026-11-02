// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it } from 'vitest';
import { PageMemoryStorage, SETTINGS_STORAGE } from '../settings/settings-facade';
import { SCAN_MIN_LENGTH } from './scan-wedge';
import { SCAN_MIN_LENGTH_CHOICES, SCAN_MIN_LENGTH_KEY, ScanMinLength } from './scan-min-length';

describe('ScanMinLength', () => {
  let storage: PageMemoryStorage;

  const create = (): ScanMinLength => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [{ provide: SETTINGS_STORAGE, useValue: storage }],
    });
    return TestBed.inject(ScanMinLength);
  };

  beforeEach(() => {
    storage = new PageMemoryStorage();
  });

  it('is four characters, as before, until this browser says otherwise, and remembers what it says', () => {
    expect(SCAN_MIN_LENGTH).toBe(4);
    expect(create().length()).toBe(4);

    const shortest = create();
    shortest.set(2);
    expect(shortest.length()).toBe(2);
    expect(storage.getItem(SCAN_MIN_LENGTH_KEY)).toBe('2');
    expect(create().length()).toBe(2);
  });

  it('offers two to eight characters: one would take a quick key and Enter for a scan, nine would refuse an EAN-8', () => {
    expect(SCAN_MIN_LENGTH_CHOICES).toEqual([2, 3, 4, 5, 6, 7, 8]);
  });

  it('ignores a stored value outside that range', () => {
    for (const stored of ['1', '9', '0', '2.5', 'short']) {
      storage.setItem(SCAN_MIN_LENGTH_KEY, stored);
      expect(create().length()).toBe(4);
    }
  });

  it('refuses to set a value outside that range, and goes back to four on reset', () => {
    const shortest = create();
    shortest.set(6);
    shortest.set(1);
    shortest.set(9);
    expect(shortest.length()).toBe(6);

    shortest.reset();
    expect(shortest.length()).toBe(4);
    expect(storage.getItem(SCAN_MIN_LENGTH_KEY)).toBeNull();
  });
});

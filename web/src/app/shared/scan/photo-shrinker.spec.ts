// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { fittedSize, PHOTO_LONGEST_SIDE } from './photo-shrinker';

describe('fittedSize', () => {
  it('brings a photo down to the longest side a phone sends, keeping its shape', () => {
    expect(PHOTO_LONGEST_SIDE).toBe(2048);
    expect(fittedSize(4032, 3024)).toEqual({ width: 2048, height: 1536 });
    expect(fittedSize(3024, 4032)).toEqual({ width: 1536, height: 2048 });
  });

  it('never makes a photo larger than it was taken', () => {
    expect(fittedSize(1200, 800)).toEqual({ width: 1200, height: 800 });
  });

  it('keeps at least a pixel on each side of a very long strip', () => {
    expect(fittedSize(100000, 10)).toEqual({ width: 2048, height: 1 });
  });
});

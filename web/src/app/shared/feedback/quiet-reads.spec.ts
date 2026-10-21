// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { QuietReads } from './quiet-reads';

describe('QuietReads', () => {
  beforeEach(() => TestBed.resetTestingModule());

  const reads = () => TestBed.inject(QuietReads);

  it('is quiet exactly while a quiet reload runs, however it ends', async () => {
    let finish!: () => void;
    const running = reads().quietly(() => new Promise<void>((resolve) => (finish = resolve)));
    expect(reads().quiet()).toBe(true);
    finish();
    await running;
    expect(reads().quiet()).toBe(false);

    await reads()
      .quietly(() => Promise.reject(new Error('refused')))
      .catch(() => undefined);
    expect(reads().quiet()).toBe(false);
  });
});

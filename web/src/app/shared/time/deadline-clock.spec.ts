// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  createEnvironmentInjector,
  EnvironmentInjector,
  runInInjectionContext,
  signal,
} from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { deadlineClock } from './deadline-clock';

describe('deadlineClock', () => {
  let injector: EnvironmentInjector;

  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-26T10:00:00Z'));
    // A context of its own, destroyed as a page is when it is left.
    injector = createEnvironmentInjector([], TestBed.inject(EnvironmentInjector));
  });

  afterEach(() => {
    // The second case destroys it itself, and an injector is destroyed once.
    if (!(injector as EnvironmentInjector & { destroyed: boolean }).destroyed) injector.destroy();
    vi.useRealTimers();
  });

  function clock(deadlines: () => readonly (string | null)[]) {
    return runInInjectionContext(injector, () => deadlineClock(deadlines));
  }

  it('moves on when each deadline passes, and not before', async () => {
    const now = clock(() => ['2026-10-26T10:05:00Z', null, '2026-10-26T10:01:00Z']);
    TestBed.tick();
    const start = now();

    await vi.advanceTimersByTimeAsync(59_000);
    TestBed.tick();
    expect(now()).toBe(start);

    await vi.advanceTimersByTimeAsync(2_000);
    TestBed.tick();
    expect(now()).toBeGreaterThanOrEqual(Date.parse('2026-10-26T10:01:00Z'));
    const afterFirst = now();

    await vi.advanceTimersByTimeAsync(4 * 60_000);
    TestBed.tick();
    expect(now()).toBeGreaterThan(afterFirst);
    expect(now()).toBeGreaterThanOrEqual(Date.parse('2026-10-26T10:05:00Z'));
  });

  it('follows deadlines that change, and stops when its context is destroyed', async () => {
    const deadlines = signal<readonly (string | null)[]>([]);
    const now = clock(() => deadlines());
    TestBed.tick();
    const start = now();

    deadlines.set(['2026-10-26T10:00:30Z']);
    TestBed.tick();
    await vi.advanceTimersByTimeAsync(31_000);
    TestBed.tick();
    expect(now()).toBeGreaterThan(start);

    const before = now();
    deadlines.set(['2026-10-26T10:10:00Z']);
    TestBed.tick();
    injector.destroy();
    await vi.advanceTimersByTimeAsync(20 * 60_000);
    TestBed.tick();
    expect(now()).toBe(before);
  });

  it('waits for a deadline further off than a timer can, in steps', async () => {
    const now = clock(() => ['2026-12-31T00:00:00Z']);
    TestBed.tick();
    const start = now();

    await vi.advanceTimersByTimeAsync(30 * 24 * 3_600_000);
    TestBed.tick();

    expect(now()).toBeGreaterThan(start);
  });
});

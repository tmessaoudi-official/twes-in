// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, inject } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Feedback } from '../feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../testing/feedback';
import { type Scan, ScanBus, type ScanHandler } from './scan-bus';

@Component({ selector: 'app-scanning-screen', template: '' })
class ScanningScreen {
  static handler: ScanHandler = () => Promise.resolve({ kind: 'unclaimed' });

  constructor() {
    inject(ScanBus).handle((scan) => ScanningScreen.handler(scan));
  }
}

describe('ScanBus', () => {
  let bus: ScanBus;
  let feedback: RecordedFeedback;
  let fallen: Scan[];

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideQuietFeedback()] });
    bus = TestBed.inject(ScanBus);
    feedback = TestBed.inject(Feedback) as RecordedFeedback;
    fallen = [];
    TestBed.runInInjectionContext(() => bus.fallback((scan) => fallen.push(scan)));
  });

  it('hands a scan nobody on screen claims to the fallback, once', async () => {
    const outcome = await bus.receive('3017620422003', 'wedge');

    expect(outcome.kind).toBe('unclaimed');
    expect(fallen).toEqual([{ code: '3017620422003', source: 'wedge', times: 1 }]);
    expect(bus.log()[0]).toMatchObject({ code: '3017620422003', outcome: 'unclaimed' });
  });

  it('gives the screen on view the scan, says what it did, and offers to take it back', async () => {
    const undo = vi.fn();
    ScanningScreen.handler = () =>
      Promise.resolve({ kind: 'done', key: 'scan.added', params: { name: 'Café' }, undo });
    TestBed.createComponent(ScanningScreen);

    await bus.receive('3017620422003', 'wedge');

    expect(fallen).toEqual([]);
    expect(feedback.said).toHaveLength(1);
    expect(feedback.said[0]).toMatchObject({
      kind: 'success',
      key: 'scan.added',
      params: { name: 'Café' },
    });
    feedback.said[0].action?.run();
    expect(undo).toHaveBeenCalledTimes(1);
    expect(bus.log()[0].undone).toBe(true);
    expect(bus.undoLast()).toBe(false);
  });

  it('forgets a screen once it is gone', async () => {
    ScanningScreen.handler = () => Promise.resolve({ kind: 'done', key: 'scan.added' });
    const screen = TestBed.createComponent(ScanningScreen);
    screen.destroy();

    await bus.receive('ABCD', 'wedge');

    expect(fallen).toHaveLength(1);
  });

  it('says why a screen refused, and a screen that failed does not lose the scan silently', async () => {
    ScanningScreen.handler = (scan) =>
      scan.code === 'LOCKED'
        ? Promise.resolve({ kind: 'refused', key: 'scan.not_editable' })
        : Promise.reject(new Error('offline'));
    TestBed.createComponent(ScanningScreen);

    await bus.receive('LOCKED', 'wedge');
    await bus.receive('ABCD', 'wedge');

    expect(feedback.said.map((each) => [each.kind, each.key])).toEqual([
      ['failure', 'scan.not_editable'],
      ['failure', 'scan.failed'],
    ]);
    expect(bus.log().map((each) => each.outcome)).toEqual(['refused', 'refused']);
  });

  it('applies the count typed before a scan to that scan alone', async () => {
    const seen: number[] = [];
    ScanningScreen.handler = (scan) => {
      seen.push(scan.times);
      return Promise.resolve({ kind: 'done', key: 'scan.added' });
    };
    TestBed.createComponent(ScanningScreen);

    bus.multiplier.set(5);
    await bus.receive('ABCD', 'wedge');
    await bus.receive('ABCD', 'wedge');

    expect(seen).toEqual([5, 1]);
    expect(bus.multiplier()).toBeNull();
  });

  it('works through scans one at a time, in the order they came', async () => {
    const order: string[] = [];
    let release: () => void = () => undefined;
    ScanningScreen.handler = async (scan) => {
      order.push(`start ${scan.code}`);
      if (scan.code === 'SLOW') await new Promise<void>((resolve) => (release = resolve));
      order.push(`end ${scan.code}`);
      return { kind: 'done', key: 'scan.added' };
    };
    TestBed.createComponent(ScanningScreen);

    const slow = bus.receive('SLOW', 'wedge');
    const quick = bus.receive('QUICK', 'wedge');
    await Promise.resolve();
    release();
    await Promise.all([slow, quick]);

    expect(order).toEqual(['start SLOW', 'end SLOW', 'start QUICK', 'end QUICK']);
  });

  it('takes back the latest scan still standing, newest first', async () => {
    const undone: string[] = [];
    ScanningScreen.handler = (scan) =>
      Promise.resolve({ kind: 'done', key: 'scan.added', undo: () => undone.push(scan.code) });
    TestBed.createComponent(ScanningScreen);
    await bus.receive('FIRST', 'wedge');
    await bus.receive('SECOND', 'wedge');

    expect(bus.undoLast()).toBe(true);
    expect(bus.undoLast()).toBe(true);
    expect(bus.undoLast()).toBe(false);
    expect(undone).toEqual(['SECOND', 'FIRST']);
    expect(feedback.said.at(-1)).toMatchObject({ kind: 'success', key: 'scan.undone' });
  });

  it('keeps the last twenty scans, newest first', async () => {
    for (let each = 0; each < 25; each++) await bus.receive(`CODE${each}`, 'wedge');

    expect(bus.log()).toHaveLength(20);
    expect(bus.log()[0].code).toBe('CODE24');
  });
});

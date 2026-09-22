// SPDX-License-Identifier: AGPL-3.0-or-later

import { SCAN_GAP_MS, ScanWedge, type WedgeKey } from './scan-wedge';

/** Keys as a wedge scanner sends them: one character every few milliseconds, then Enter. */
function burst(text: string, start = 1000, gap = 2): WedgeKey[] {
  const keys: WedgeKey[] = [...text].map((key, index) => ({
    key,
    at: start + index * gap,
    editable: false,
    modified: false,
  }));
  return [
    ...keys,
    { key: 'Enter', at: start + text.length * gap, editable: false, modified: false },
  ];
}

function feed(wedge: ScanWedge, keys: readonly WedgeKey[]): (string | null)[] {
  return keys.map((key) => wedge.read(key).code);
}

describe('ScanWedge', () => {
  it('reads the code a scanner typed and closed with Enter', () => {
    const results = feed(new ScanWedge(), burst('3017620422003'));

    expect(results.at(-1)).toBe('3017620422003');
    expect(results.slice(0, -1).every((code) => code === null)).toBe(true);
  });

  it('leaves a person typing alone, whatever they type', () => {
    const results = feed(new ScanWedge(), burst('3017620422003', 1000, SCAN_GAP_MS * 4));

    expect(results.every((code) => code === null)).toBe(true);
  });

  it('starts again after a pause, so a key pressed by hand before a scan is not part of it', () => {
    const wedge = new ScanWedge();
    feed(wedge, [{ key: 'x', at: 0, editable: false, modified: false }]);

    expect(feed(wedge, burst('ABC-1234', 5000)).at(-1)).toBe('ABC-1234');
  });

  it('leaves an Enter pressed by hand after a burst to whatever has the focus', () => {
    const keys = burst('3017620422003');
    const enter = keys.pop()!;

    expect(feed(new ScanWedge(), [...keys, { ...enter, at: enter.at + 200 }]).at(-1)).toBeNull();
  });

  it('reads nothing shorter than a code', () => {
    expect(feed(new ScanWedge(), burst('12')).at(-1)).toBeNull();
  });

  it('leaves a field its own scan, and a modified key is never part of one', () => {
    const wedge = new ScanWedge();
    const inField = burst('3017620422003').map((key) => ({ ...key, editable: true }));
    expect(feed(wedge, inField).at(-1)).toBeNull();

    // Ctrl J right before the Enter is a shortcut typed after the digits, not their last character.
    const withCtrl = burst('3017620422003').map((key, index) =>
      index === 12 ? { ...key, modified: true } : key,
    );
    expect(feed(new ScanWedge(), withCtrl).at(-1)).toBeNull();
  });

  it('lets the Shift a scanner sends for a capital pass without breaking the burst', () => {
    const keys = burst('AB-1234');
    keys.splice(1, 0, { key: 'Shift', at: keys[0].at + 1, editable: false, modified: false });

    expect(feed(new ScanWedge(), keys).at(-1)).toBe('AB-1234');
  });

  it('says a key belongs to a burst from the second character on, so a screen shortcut does not fire mid-scan', () => {
    const wedge = new ScanWedge();
    const claimed = burst('s0123').map((key) => wedge.read(key).claimed);

    // The first character cannot be told from a hand's until the second arrives: it is the screen's.
    expect(claimed).toEqual([false, true, true, true, true, true]);
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { PLAN_LABEL_MODES } from '../shared/settings/settings-registry';
import { LABEL_FONT, fitLabel, planLabel } from './stock-map-labels';

/**
 * What the plan writes on a rectangle (docs/SPEC.md § 7, 2026-09-22). A store that has numbered its building reads
 * its own numbers; a store that has named it reads names; one that is learning a new numbering wants both while it
 * learns. The choice is the reader's, not the data's.
 */
describe('planLabel', () => {
  it('offers exactly the three the ruling names, code first', () => {
    expect(PLAN_LABEL_MODES).toEqual(['code', 'name', 'both']);
  });

  it('writes the code, the name, or both', () => {
    expect(planLabel('A-01', 'Rayonnage 1', 'code')).toBe('A-01');
    expect(planLabel('A-01', 'Rayonnage 1', 'name')).toBe('Rayonnage 1');
    expect(planLabel('A-01', 'Rayonnage 1', 'both')).toBe('A-01 · Rayonnage 1');
  });

  /**
   * A location always has a code and may have nothing else worth reading. Asking for the name must not empty the
   * rectangle: a blank label is indistinguishable from a rectangle nobody drew, and the one thing always present
   * is what the reader gets instead.
   */
  it('falls back to the code rather than writing nothing', () => {
    expect(planLabel('A-01', '', 'name')).toBe('A-01');
    expect(planLabel('A-01', '   ', 'name')).toBe('A-01');
    expect(planLabel('A-01', '', 'both')).toBe('A-01');
  });

  /** A piece of the building has no code at all, so its name is the only thing it can ever be labelled by. */
  it('writes the name alone where there is no code', () => {
    expect(planLabel('', 'Porte du quai 2', 'code')).toBe('Porte du quai 2');
    expect(planLabel('', 'Porte du quai 2', 'both')).toBe('Porte du quai 2');
    expect(planLabel('', '', 'both')).toBe('');
  });

  /** Both halves are trimmed, so a name saved with a stray space does not push the separator off the rectangle. */
  it('trims what it writes', () => {
    expect(planLabel('  A-01 ', ' Rayonnage 1 ', 'both')).toBe('A-01 · Rayonnage 1');
  });
});

/**
 * A label is drawn in METRES on a scale plan, so a long one runs off its own rectangle and across the floor —
 * where it reads as belonging to whatever it lands on, or to nothing. Found by looking at the rendered plan and
 * not by any assertion: `LBL77640633 · Rayonnage LBL77640633` ran 6,1 m across a 3,9 m rack (2026-09-22).
 */
describe('fitLabel', () => {
  it('leaves a label that fits exactly as it is', () => {
    expect(fitLabel('A-01', 4, LABEL_FONT)).toBe('A-01');
  });

  it('cuts a label that would run past its own rectangle', () => {
    const long = 'LBL77640633 · Rayonnage LBL77640633';
    const fitted = fitLabel(long, 3.9, LABEL_FONT);

    expect(fitted).not.toBe(long);
    expect(fitted.endsWith('…')).toBe(true);
    expect(long.startsWith(fitted.slice(0, -1))).toBe(true);
    // What is drawn must be no wider than what it is drawn on.
    expect(fitted.length * LABEL_FONT * 0.55).toBeLessThanOrEqual(3.9);
  });

  /** A rectangle too small for even one readable character is better bare than carrying a lone ellipsis. */
  it('writes nothing where nothing can be read', () => {
    expect(fitLabel('A-01', 0.2, LABEL_FONT)).toBe('');
    expect(fitLabel('', 4, LABEL_FONT)).toBe('');
  });

  /** The budget follows the font: shrinking the type must fit MORE, not the same. */
  it('measures against the font it is drawn in', () => {
    expect(fitLabel('Rayonnage 1', 2, LABEL_FONT / 2).length).toBeGreaterThan(
      fitLabel('Rayonnage 1', 2, LABEL_FONT).length,
    );
  });
});

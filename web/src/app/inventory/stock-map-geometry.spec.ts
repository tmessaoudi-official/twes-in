// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import {
  cornersOf,
  planBounds,
  planViewBox,
  SNAP_DEGREES,
  SNAP_METRES,
  snapAngle,
  snapMetres,
  type PlanRectangle,
} from './stock-map-geometry';

const rect = (over: Partial<PlanRectangle> = {}): PlanRectangle => ({
  x: 0,
  y: 0,
  width: 4,
  depth: 1,
  rotation: 0,
  height: 2,
  ...over,
});

describe('snapMetres', () => {
  it('snaps to the quarter-metre the plan is drawn on', () => {
    expect(snapMetres(3.9)).toBe(4);
    expect(snapMetres(3.8)).toBe(3.75);
    expect(snapMetres(0.13)).toBe(0.25);
    expect(snapMetres(0.12)).toBe(0);
  });

  it('snaps a negative the same way, away from zero at the half-step', () => {
    expect(snapMetres(-0.3)).toBe(-0.25);
    expect(snapMetres(-1.13)).toBe(-1.25);
    // Symmetrical about zero, so dragging left and right behave alike at the midpoint.
    expect(snapMetres(0.125)).toBe(0.25);
    expect(snapMetres(-0.125)).toBe(-0.25);
    // And a negative that snaps to nothing is zero, never the negative zero a strict comparison rejects.
    expect(snapMetres(-0.1)).toBe(0);
  });

  it('leaves a value already on the grid exactly where it is', () => {
    for (const on of [0, 0.25, 2.5, 12.75, -3.5]) expect(snapMetres(on)).toBe(on);
  });

  /** A plan holds metres, and a metre is not a float the screen may round its own way. */
  it('answers a clean quarter rather than a floating-point neighbour', () => {
    expect(snapMetres(0.3)).toBe(0.25);
    expect(String(snapMetres(1.1))).toBe('1');
    expect(String(snapMetres(2.3))).toBe('2.25');
  });

  it('is the step the module publishes', () => {
    expect(SNAP_METRES).toBe(0.25);
  });
});

describe('snapAngle', () => {
  it('snaps to fifteen degrees', () => {
    expect(snapAngle(7)).toBe(0);
    expect(snapAngle(8)).toBe(15);
    expect(snapAngle(44)).toBe(45);
    expect(SNAP_DEGREES).toBe(15);
  });

  it('brings any turn back into nought to three-five-nine', () => {
    expect(snapAngle(360)).toBe(0);
    expect(snapAngle(375)).toBe(15);
    expect(snapAngle(-15)).toBe(345);
    // -370 snaps to -375 before it is brought round, and -375 is 345: every answer is itself a multiple of fifteen.
    expect(snapAngle(-370)).toBe(345);
    expect(snapAngle(-350)).toBe(15);
  });
});

describe('cornersOf', () => {
  it('gives an unturned rectangle its own four corners', () => {
    expect(cornersOf(rect({ x: 1, y: 2, width: 4, depth: 1 }))).toEqual([
      { x: 1, y: 2 },
      { x: 5, y: 2 },
      { x: 5, y: 3 },
      { x: 1, y: 3 },
    ]);
  });

  /**
   * A rack turned a quarter of a circle keeps its footprint and swaps its sides — which is the whole reason the plan
   * stores a rotation rather than a bounding box.
   */
  it('turns about the centre, so a quarter turn swaps width and depth around the same middle', () => {
    const turned = cornersOf(rect({ x: 0, y: 0, width: 4, depth: 1, rotation: 90 }));
    const xs = turned.map((corner) => Math.round(corner.x * 1000) / 1000);
    const ys = turned.map((corner) => Math.round(corner.y * 1000) / 1000);

    expect(Math.min(...xs)).toBe(1.5);
    expect(Math.max(...xs)).toBe(2.5);
    expect(Math.min(...ys)).toBe(-1.5);
    expect(Math.max(...ys)).toBe(2.5);
  });

  it('keeps the centre still whatever the angle', () => {
    for (const rotation of [0, 15, 90, 210, 345]) {
      const turned = cornersOf(rect({ x: 2, y: 3, width: 4, depth: 1, rotation }));
      const middleX = turned.reduce((sum, corner) => sum + corner.x, 0) / 4;
      const middleY = turned.reduce((sum, corner) => sum + corner.y, 0) / 4;
      expect(middleX).toBeCloseTo(4, 6);
      expect(middleY).toBeCloseTo(3.5, 6);
    }
  });
});

describe('planBounds', () => {
  it('is the floor itself when nothing is drawn, so an empty plan still has a size', () => {
    expect(planBounds([])).toEqual({ minX: 0, minY: 0, maxX: 0, maxY: 0 });
  });

  it('covers every rectangle', () => {
    expect(
      planBounds([
        rect({ x: 1, y: 1, width: 2, depth: 2 }),
        rect({ x: 6, y: 4, width: 3, depth: 1 }),
      ]),
    ).toEqual({ minX: 1, minY: 1, maxX: 9, maxY: 5 });
  });

  /** The reason cornersOf exists: a turned rack sticks out past the box its own x, y, width and depth describe. */
  it('covers what a turned rectangle reaches, not the box it was typed as', () => {
    const bounds = planBounds([rect({ x: 0, y: 0, width: 4, depth: 1, rotation: 90 })]);
    expect(bounds.minY).toBeCloseTo(-1.5, 6);
    expect(bounds.maxY).toBeCloseTo(2.5, 6);
  });
});

describe('planViewBox', () => {
  it('frames what is drawn with a margin around it', () => {
    expect(planViewBox([rect({ x: 2, y: 2, width: 4, depth: 2 })], 1)).toBe('1 1 6 4');
  });

  /**
   * A floor with nothing on it yet is still a floor to draw on: it answers a workable square rather than a box of no
   * width, which renders as nothing at all and reads as a broken screen.
   */
  it('answers a workable square for a floor with nothing on it', () => {
    expect(planViewBox([], 1)).toBe('-1 -1 12 12');
  });
});

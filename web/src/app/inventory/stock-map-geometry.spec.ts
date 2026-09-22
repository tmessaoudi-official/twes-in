// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import {
  cornersOf,
  handleAt,
  movedTo,
  PLAN_HANDLES,
  handlesThatFit,
  planBounds,
  planFrame,
  planViewBox,
  pointerMetres,
  fitView,
  pannedBy,
  shownFrame,
  zoomedAt,
  PLAN_ZOOM_MAX,
  type PlanFrame,
  centredIn,
  repeatedFrom,
  resizedTo,
  tracedTo,
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

describe('planFrame', () => {
  it('is the same box planViewBox writes out, as numbers the pointer maths can use', () => {
    expect(planFrame([rect({ x: 2, y: 2, width: 4, depth: 2 })], 1)).toEqual({
      x: 1,
      y: 1,
      width: 6,
      height: 4,
    });
  });
});

describe('pointerMetres', () => {
  const frame = { x: 0, y: 0, width: 10, height: 10 };

  it('reads a pointer in the middle of a square viewport as the middle of the floor', () => {
    const at = pointerMetres({ x: 100, y: 100 }, frame, {
      left: 0,
      top: 0,
      width: 200,
      height: 200,
    });

    expect(at).toEqual({ x: 5, y: 5 });
  });

  /**
   * An SVG without `preserveAspectRatio` meets its box — it scales uniformly and CENTRES what is left over. A reader
   * that divided by each side separately would be wrong by half the letterbox, which is what makes a rectangle drift
   * away from the pointer on a wide window rather than follow it.
   */
  it('accounts for the bands a wide viewport leaves either side of a square floor', () => {
    const wide = { left: 0, top: 0, width: 400, height: 200 };

    // The floor is drawn 200 × 200 in the middle of a 400-wide box, so it starts 100 px in.
    expect(pointerMetres({ x: 100, y: 0 }, frame, wide)).toEqual({ x: 0, y: 0 });
    expect(pointerMetres({ x: 300, y: 200 }, frame, wide)).toEqual({ x: 10, y: 10 });
  });

  it('is measured from the viewport’s own corner, not the window’s', () => {
    expect(
      pointerMetres({ x: 150, y: 120 }, frame, { left: 50, top: 20, width: 200, height: 200 }),
    ).toEqual({ x: 5, y: 5 });
  });

  /** A box of no size has no answer, and a division by it would put a rectangle at infinity. */
  it('answers the floor’s own corner when the viewport has not been laid out yet', () => {
    expect(
      pointerMetres({ x: 10, y: 10 }, frame, { left: 0, top: 0, width: 0, height: 0 }),
    ).toEqual({ x: 0, y: 0 });
  });
});

describe('movedTo', () => {
  /** The magnet of decision 3: while something is DRAGGED, where it lands is taken to the quarter-metre. */
  it('takes where it lands to the quarter-metre and leaves its size alone', () => {
    const moved = movedTo(
      rect({ x: 1, y: 1, width: 3.9, depth: 0.6 }),
      { x: 2, y: 2 },
      {
        x: 3.16,
        y: 4.4,
      },
    );

    expect(moved.x).toBe(2.25);
    expect(moved.y).toBe(3.5);
    expect(moved.width).toBe(3.9);
    expect(moved.depth).toBe(0.6);
  });

  it('carries the turn and the height through untouched', () => {
    const moved = movedTo(rect({ rotation: 45, height: 2.1 }), { x: 0, y: 0 }, { x: 1, y: 1 });

    expect(moved.rotation).toBe(45);
    expect(moved.height).toBe(2.1);
  });

  /**
   * A floor starts at its own top-left corner and `PlanRect` refuses a negative distance, so a rectangle pushed off
   * the edge stops there rather than being sent and refused.
   */
  it('stops at the floor’s edge rather than being dragged off it', () => {
    const moved = movedTo(rect({ x: 1, y: 1 }), { x: 2, y: 2 }, { x: -6, y: -6 });

    expect(moved).toMatchObject({ x: 0, y: 0 });
  });
});

/**
 * A handle is drawn at a fixed size on the screen, so on a large floor a thin rack's edge handles covered its whole
 * middle and it could no longer be taken hold of to move it (found by the e2e on 2026-09-22, once the board framed
 * the floor's own size). A side too short to leave its middle free loses its middle handles; the corners stay.
 */
describe('handlesThatFit', () => {
  it('keeps all eight handles where the rectangle leaves its middle free', () => {
    expect(handlesThatFit(rect({ width: 4, depth: 2 }), 0.2)).toHaveLength(8);
  });

  it('drops the middle handles of the long sides of a thin rack, keeping the corners and the ends', () => {
    const kept = handlesThatFit(rect({ width: 3.9, depth: 0.6 }), 0.29);

    expect(kept).toHaveLength(6);
    expect(kept.some((handle) => handle.hx === 0.5)).toBe(false);
    expect(kept.filter((handle) => handle.hx !== 0.5 && handle.hy !== 0.5)).toHaveLength(4);
  });

  it('keeps only the corners on a rectangle short both ways', () => {
    expect(handlesThatFit(rect({ width: 0.4, depth: 0.4 }), 0.29)).toHaveLength(4);
  });
});

describe('resizedTo', () => {
  const wide = rect({ x: 2, y: 2, width: 4, depth: 2 });

  it('pulls the corner it was given and leaves the opposite one where it was', () => {
    const resized = resizedTo(wide, { hx: 1, hy: 1 }, { x: 7.1, y: 5.4 });

    expect(resized).toMatchObject({ x: 2, y: 2, width: 5, depth: 3.5 });
  });

  it('moves the near edge and keeps the far one, when the corner pulled is the near one', () => {
    const resized = resizedTo(wide, { hx: 0, hy: 0 }, { x: 3.1, y: 2.6 });

    // The far corner stays at x 6, y 4; the near one lands on the quarter at 3, 2.5.
    expect(resized).toMatchObject({ x: 3, y: 2.5, width: 3, depth: 1.5 });
  });

  /** An edge handle moves one side only: a rack keeps its length while its depth is corrected. */
  it('changes one side only when the handle is on an edge', () => {
    const resized = resizedTo(wide, { hx: 0.5, hy: 1 }, { x: 9, y: 5 });

    expect(resized).toMatchObject({ x: 2, y: 2, width: 4, depth: 3 });
  });

  /** A rectangle of no size is not a place: `PlanRect` refuses it, so the handle stops one grid step short. */
  it('never pulls a rectangle through itself', () => {
    const resized = resizedTo(wide, { hx: 1, hy: 1 }, { x: -5, y: -5 });

    expect(resized.width).toBe(SNAP_METRES);
    expect(resized.depth).toBe(SNAP_METRES);
  });

  /**
   * A turned rack is resized along ITS OWN sides — pulling the end of a rack standing at 90° makes it longer, not
   * wider — and the corner held stays exactly where it was on the floor.
   */
  it('resizes a turned rectangle along its own sides, holding the opposite corner on the floor', () => {
    const turned = rect({ x: 2, y: 2, width: 4, depth: 2, rotation: 90 });
    const before = cornersOf(turned)[0]!;
    const resized = resizedTo(turned, { hx: 1, hy: 1 }, { x: 4, y: 9 });
    const after = cornersOf(resized)[0]!;

    // Six metres below the centre, and the rack stands at 90°, so that is six metres along its own length: the end
    // held sits at 2 in the rack's own frame and the end pulled lands at 10.
    expect(resized.width).toBe(8);
    expect(resized.depth).toBe(1);
    expect(resized.rotation).toBe(90);
    expect(after.x).toBeCloseTo(before.x, 6);
    expect(after.y).toBeCloseTo(before.y, 6);
  });
});

describe('handleAt', () => {
  /**
   * The handles are drawn INSIDE the group that turns the rectangle, so they are given the rectangle's own
   * unturned corners and the browser turns them with it.
   */
  it('puts a handle on each corner and each edge of the unturned rectangle', () => {
    const at = PLAN_HANDLES.map((handle) =>
      handleAt(rect({ x: 2, y: 2, width: 4, depth: 2 }), handle),
    );

    expect(PLAN_HANDLES).toHaveLength(8);
    expect(at).toContainEqual({ x: 2, y: 2 });
    expect(at).toContainEqual({ x: 6, y: 4 });
    expect(at).toContainEqual({ x: 4, y: 2 });
    expect(at).not.toContainEqual({ x: 4, y: 3 });
  });
});

describe('tracedTo', () => {
  /** The gesture of decision 1: a box drawn on bare floor by dragging one corner to the other. */
  it('makes a rectangle between the two corners of the gesture, on the grid', () => {
    const traced = tracedTo({ x: 2.1, y: 3.9 }, { x: 6.4, y: 5.1 });

    expect(traced).toEqual({ x: 2, y: 4, width: 4.5, depth: 1, rotation: 0, height: 0 });
  });

  /** A box is drawn from whichever corner the hand started at: up and to the left is the same box. */
  it('reads the same box whichever way the corners were dragged', () => {
    const down = tracedTo({ x: 2, y: 4 }, { x: 6.5, y: 5 });
    const up = tracedTo({ x: 6.5, y: 5 }, { x: 2, y: 4 });

    expect(up).toEqual(down);
  });

  /** A press that barely moved is still a box: `PlanRect` refuses a side of no length. */
  it('never traces a rectangle of no size', () => {
    const traced = tracedTo({ x: 3, y: 3 }, { x: 3.02, y: 3.02 });

    expect(traced.width).toBe(SNAP_METRES);
    expect(traced.depth).toBe(SNAP_METRES);
  });

  /** A floor starts at its own corner, so a gesture that ran off the top-left stops there rather than being refused. */
  it('keeps a traced box on the floor', () => {
    const traced = tracedTo({ x: -4, y: -4 }, { x: 2, y: 1 });

    expect(traced).toMatchObject({ x: 0, y: 0, width: 2, depth: 1 });
  });
});

describe('centredIn', () => {
  const frame = { x: -2.5, y: -1, width: 13.9, height: 10.6 };

  /** What the palette does without a pointer: the shape is posed in the middle of what is being looked at. */
  it('puts a shape in the middle of the floor being shown, on the grid', () => {
    expect(centredIn(frame, 2.4, 0.6)).toEqual({
      x: 3.25,
      y: 4,
      width: 2.4,
      depth: 0.6,
      rotation: 0,
      height: 0,
    });
  });

  /** A floor has no negative corner, so a shape wider than what is shown starts at the floor's own edge. */
  it('keeps a shape too big for the view on the floor', () => {
    expect(centredIn(frame, 40, 40)).toMatchObject({ x: 0, y: 0, width: 40, depth: 40 });
  });

  /** It carries the size through exactly: a company's 3,90 m rack is posed at 3,90, never re-measured. */
  it('never snaps the size it was given', () => {
    expect(centredIn(frame, 3.9, 0.6)).toMatchObject({ width: 3.9, depth: 0.6 });
  });
});

describe('repeatedFrom', () => {
  const rack: PlanRectangle = { x: 1, y: 4, width: 3.9, depth: 0.6, rotation: 0, height: 2.1 };

  /**
   * The screen's mirror of what the API will create. It exists so the dotted preview shows what is about to be
   * made rather than an approximation of it: if these two ever disagreed, the person would accept one plan and get
   * another. The spacing is the FREE FLOOR between two rectangles, so the pitch is the rack's own depth plus it.
   */
  it('steps down the floor by the rectangle’s depth plus the free floor between', () => {
    expect(repeatedFrom(rack, 3, 0.6, 'down').map((one) => one.y)).toEqual([5.2, 6.4, 7.6]);
    expect(repeatedFrom(rack, 3, 0.6, 'down').map((one) => one.x)).toEqual([1, 1, 1]);
  });

  it('steps sideways by the width instead, and backwards for up and left', () => {
    expect(repeatedFrom(rack, 1, 0.6, 'right')[0]?.x).toBe(5.5);
    expect(repeatedFrom(rack, 1, 0.6, 'left')[0]?.x).toBe(-3.5);
    expect(repeatedFrom(rack, 1, 0.6, 'up')[0]?.y).toBe(2.8);
  });

  /** A quarter turn swaps which side the rectangle covers along each axis, exactly as the API's own step does. */
  it('steps a turned rectangle by the side it actually covers', () => {
    const turned = { ...rack, rotation: 90 };

    expect(repeatedFrom(turned, 1, 0.6, 'down')[0]?.y).toBe(8.5);
    expect(repeatedFrom(turned, 1, 0.6, 'right')[0]?.x).toBe(2.2);
    expect(repeatedFrom(turned, 1, 0.6, 'down')[0]?.rotation).toBe(90);
  });

  /**
   * Only the four right angles. The API refuses anything else because their sines and cosines are whole numbers and
   * bcmath and JavaScript then agree exactly; here that refusal shows as no preview at all, which is what the
   * screen needs to disable the button rather than offer a repeat that would be refused.
   */
  it('gives nothing back for an angle that is not a quarter turn', () => {
    expect(repeatedFrom({ ...rack, rotation: 30 }, 3, 0.6, 'down')).toEqual([]);
    expect(repeatedFrom(rack, 0, 0.6, 'down')).toEqual([]);
    expect(repeatedFrom(rack, -2, 0.6, 'down')).toEqual([]);
  });

  /** Typed and not dragged, so no magnet: a spacing of 0,1 m is taken as 0,1 and never rounded to the quarter. */
  it('takes the spacing exactly as it was typed', () => {
    expect(repeatedFrom(rack, 2, 0.1, 'down').map((one) => one.y)).toEqual([4.7, 5.4]);
  });

  /** Every copy is the size of what it was copied from: a repeat arranges racks, it does not re-measure them. */
  it('carries the size and the height through unchanged', () => {
    expect(repeatedFrom(rack, 1, 0.6, 'down')[0]).toEqual({
      x: 1,
      y: 5.2,
      width: 3.9,
      depth: 0.6,
      rotation: 0,
      height: 2.1,
    });
  });
});

/**
 * Looking closer, and moving what is looked at. Until now the plan always showed the whole floor at once: a
 * 24-metre depot in a fixed window, with no way to come near enough to aim at a rack nor to step back and find
 * one's bearings (docs/SPEC.md § 7, 2026-09-22).
 */
describe('zoom and pan', () => {
  const floor: PlanFrame = { x: 0, y: 0, width: 24, height: 14 };

  it('shows the whole floor at scale 1, whatever centre it is given', () => {
    expect(shownFrame(floor, { scale: 1, cx: 3, cy: 2 })).toEqual(floor);
  });

  it('shows a quarter of the floor at scale 2, around the centre asked for', () => {
    expect(shownFrame(floor, { scale: 2, cx: 12, cy: 7 })).toEqual({
      x: 6,
      y: 3.5,
      width: 12,
      height: 7,
    });
  });

  /** Panning may not leave the floor: there is nothing out there to look at, and finding it again is work. */
  it('keeps what is shown inside the floor, however far the centre is pushed', () => {
    expect(shownFrame(floor, { scale: 2, cx: -100, cy: 900 })).toEqual({
      x: 0,
      y: 7,
      width: 12,
      height: 7,
    });
  });

  it('refuses to go nearer or further than it is worth', () => {
    expect(shownFrame(floor, { scale: 99, cx: 12, cy: 7 }).width).toBe(24 / PLAN_ZOOM_MAX);
    expect(shownFrame(floor, { scale: 0.01, cx: 12, cy: 7 })).toEqual(floor);
  });

  /**
   * The point under the pointer stays under the pointer. Zooming towards the middle of the window instead would
   * walk whatever one was aiming at off the screen, which is the thing that makes a plan feel broken.
   */
  it('leaves the point it is aimed at where it was', () => {
    const view = zoomedAt(floor, { scale: 1, cx: 12, cy: 7 }, 2, { x: 6, y: 3.5 });
    const shown = shownFrame(floor, view);

    expect((6 - shown.x) / shown.width).toBeCloseTo(0.25, 6);
    expect((3.5 - shown.y) / shown.height).toBeCloseTo(0.25, 6);
  });

  it('moves what is shown the other way from the hand, so the floor follows the finger', () => {
    expect(pannedBy({ scale: 2, cx: 12, cy: 7 }, 3, -1)).toEqual({ scale: 2, cx: 9, cy: 8 });
  });

  it('fits the whole floor again from its own frame', () => {
    expect(fitView(floor)).toEqual({ scale: 1, cx: 12, cy: 7 });
  });
});

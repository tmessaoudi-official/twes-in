// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The arithmetic the drawn stock map is made of (docs/SPEC.md row 83; § 7, 2026-09-21, decisions 3 and 5). Pure
 * functions over metres: nothing here knows about the API, the DOM or a viewport.
 *
 * A plan holds METRES and never pixels, because a floor photograph is rescanned and recropped over a building's life
 * while the building itself does not move. Pixels appear once, in the SVG's viewBox, and the browser does the scaling.
 */

/** The grid a plan is drawn on: a quarter of a metre. */
export const SNAP_METRES = 0.25;

/** A rack set against a wall at an angle turns in fifteen-degree steps. */
export const SNAP_DEGREES = 15;

/** What a floor with nothing drawn on it yet offers to draw in, in metres. */
const EMPTY_FLOOR_METRES = 10;

/** One rectangle on a floor, in metres from the floor's top-left corner. */
export interface PlanRectangle {
  x: number;
  y: number;
  width: number;
  /** How deep it stands on the floor: the second side of the footprint, not a height. */
  depth: number;
  /** Whole degrees clockwise about the rectangle's own centre. */
  rotation: number;
  /** How tall it stands, for the 3D view; zero for a bay marked out on the floor. */
  height: number;
}

export interface PlanPoint {
  x: number;
  y: number;
}

export interface PlanBounds {
  minX: number;
  minY: number;
  maxX: number;
  maxY: number;
}

/** The part of a floor a plan is showing, in metres: an SVG `viewBox` before it is written out. */
export interface PlanFrame {
  x: number;
  y: number;
  width: number;
  height: number;
}

/** Where a plan is on the screen and how big it is drawn, in pixels — a `DOMRect`, narrowed to what is read. */
export interface PlanBox {
  left: number;
  top: number;
  width: number;
  height: number;
}

/** Which end of a side a handle takes hold of: the near one, its middle, or the far one. */
export type PlanSide = 0 | 0.5 | 1;

/** One of the handles around a selected rectangle, named by the corner or edge it sits on. */
export interface PlanHandle {
  hx: PlanSide;
  hy: PlanSide;
}

/**
 * The eight handles the approved Edit board draws around a selected rectangle: its four corners, which change both
 * sides at once, and the middle of each edge, which changes one — a rack whose depth was measured wrong keeps its
 * length while it is corrected. The centre is not a handle: the rectangle itself is what is dragged to move it.
 */
export const PLAN_HANDLES: readonly PlanHandle[] = ([0, 0.5, 1] as const).flatMap<PlanHandle>(
  (hx) =>
    ([0, 0.5, 1] as const)
      .filter((hy) => !(hx === 0.5 && hy === 0.5))
      .map((hy) => ({ hx, hy }) satisfies PlanHandle),
);

/**
 * A measurement on the grid. Quarters are exact in binary, so this answers a clean 2.25 rather than a floating-point
 * neighbour a person would then see written out in a field.
 */
export function snapMetres(value: number): number {
  const snapped = Math.sign(value) * Math.round(Math.abs(value) / SNAP_METRES) * SNAP_METRES;

  // Math.sign carries a negative zero through the multiplication, and -0 is not 0 to a strict comparison.
  return snapped === 0 ? 0 : snapped;
}

/** An angle on the grid, brought back into 0–359 so a rectangle turned full circle reads as unturned. */
export function snapAngle(degrees: number): number {
  const snapped = Math.round(degrees / SNAP_DEGREES) * SNAP_DEGREES;

  return ((snapped % 360) + 360) % 360;
}

/**
 * The four corners a rectangle actually occupies, turned about its own centre — clockwise, as the screen reads it.
 * A turned rack keeps its footprint and reaches past the box its x, y, width and depth describe, which is why the
 * plan stores a rotation rather than a bounding box.
 */
export function cornersOf(rect: PlanRectangle): PlanPoint[] {
  const centreX = rect.x + rect.width / 2;
  const centreY = rect.y + rect.depth / 2;
  const radians = (rect.rotation * Math.PI) / 180;
  const cos = Math.cos(radians);
  const sin = Math.sin(radians);

  return [
    { x: rect.x, y: rect.y },
    { x: rect.x + rect.width, y: rect.y },
    { x: rect.x + rect.width, y: rect.y + rect.depth },
    { x: rect.x, y: rect.y + rect.depth },
  ].map((corner) => {
    const dx = corner.x - centreX;
    const dy = corner.y - centreY;

    return { x: centreX + dx * cos - dy * sin, y: centreY + dx * sin + dy * cos };
  });
}

/** What every rectangle on a floor reaches between, turns included. */
export function planBounds(rects: readonly PlanRectangle[]): PlanBounds {
  const corners = rects.flatMap(cornersOf);
  if (corners.length === 0) return { minX: 0, minY: 0, maxX: 0, maxY: 0 };

  const xs = corners.map((corner) => corner.x);
  const ys = corners.map((corner) => corner.y);

  return {
    minX: Math.min(...xs),
    minY: Math.min(...ys),
    maxX: Math.max(...xs),
    maxY: Math.max(...ys),
  };
}

/**
 * The box that frames a floor, in metres, with a margin around what is drawn. A floor with nothing on it yet is
 * still a floor to draw on, so it answers a workable square — a box of no width renders as nothing at all, which
 * reads as a broken screen rather than as an empty plan.
 */
export function planFrame(rects: readonly PlanRectangle[], padding: number): PlanFrame {
  const bounds = planBounds(rects);
  const empty = rects.length === 0;
  const width = empty ? EMPTY_FLOOR_METRES : bounds.maxX - bounds.minX;
  const height = empty ? EMPTY_FLOOR_METRES : bounds.maxY - bounds.minY;

  return {
    x: tidy(bounds.minX - padding),
    y: tidy(bounds.minY - padding),
    width: tidy(width + padding * 2),
    height: tidy(height + padding * 2),
  };
}

/** The same box as an SVG `viewBox`. */
export function planViewBox(rects: readonly PlanRectangle[], padding: number): string {
  const frame = planFrame(rects, padding);

  return [frame.x, frame.y, frame.width, frame.height].join(' ');
}

/**
 * Where a pointer is, in metres on the floor.
 *
 * An SVG with no `preserveAspectRatio` of its own MEETS its box: it scales both sides by the same factor — the
 * smaller of the two — and centres what is drawn inside whatever is left over. Dividing by each side separately
 * would be wrong by half of that leftover band, which is what makes a dragged rectangle drift away from the pointer
 * on a window that is not the floor's own shape instead of staying under it.
 */
export function pointerMetres(client: PlanPoint, frame: PlanFrame, box: PlanBox): PlanPoint {
  const scale = Math.min(box.width / frame.width, box.height / frame.height);

  // A viewport that has not been laid out yet has no scale, and dividing by it would put a rectangle at infinity.
  if (!Number.isFinite(scale) || scale <= 0) return { x: frame.x, y: frame.y };

  const left = box.left + (box.width - frame.width * scale) / 2;
  const top = box.top + (box.height - frame.height * scale) / 2;

  return { x: frame.x + (client.x - left) / scale, y: frame.y + (client.y - top) / scale };
}

/**
 * A rectangle dragged from where it was grabbed to where the pointer is now — **the magnet of decision 3**. Where a
 * rectangle LANDS is taken to the quarter-metre, exactly as the approved canvas's scale reads it (*aimanté sur
 * 0,25 m*); its size is carried through untouched, because a drag moves a rack and does not remeasure it
 * (docs/SPEC.md § 7, 2026-09-21 20:40).
 *
 * A floor starts at its own top-left corner and `PlanRect` refuses a negative distance, so a rectangle pushed past
 * the edge stops there rather than being sent and refused.
 */
export function movedTo(
  origin: PlanRectangle,
  grabbedAt: PlanPoint,
  pointer: PlanPoint,
): PlanRectangle {
  return {
    ...origin,
    x: onFloor(snapMetres(origin.x + pointer.x - grabbedAt.x)),
    y: onFloor(snapMetres(origin.y + pointer.y - grabbedAt.y)),
  };
}

/**
 * A rectangle resized by one of its handles, the opposite corner or edge held where it was on the floor.
 *
 * The work is done in the rectangle's OWN frame — the pointer is turned back about the centre by the rectangle's
 * rotation — so a rack standing at 90° grows longer when its end is pulled rather than wider. Only then is the new
 * box turned forward again, which is what keeps the held corner on the spot a person is looking at.
 */
export function resizedTo(
  origin: PlanRectangle,
  handle: PlanHandle,
  pointer: PlanPoint,
): PlanRectangle {
  const centre = { x: origin.x + origin.width / 2, y: origin.y + origin.depth / 2 };
  const local = turn(pointer, centre, -origin.rotation);
  const [left, width] = pulled(origin.x, origin.width, handle.hx, snapMetres(local.x));
  const [top, depth] = pulled(origin.y, origin.depth, handle.hy, snapMetres(local.y));

  // The box changed size in its own frame, so its centre moved with it; turning that centre forward again puts the
  // rectangle back under the corner that was held.
  const moved = turn({ x: left + width / 2, y: top + depth / 2 }, centre, origin.rotation);

  return {
    ...origin,
    x: onFloor(moved.x - width / 2),
    y: onFloor(moved.y - depth / 2),
    width,
    depth,
  };
}

/** Where each handle sits on the rectangle's own unturned corners, which the group around it then turns. */
export function handleAt(rect: PlanRectangle, handle: PlanHandle): PlanPoint {
  return { x: rect.x + rect.width * handle.hx, y: rect.y + rect.depth * handle.hy };
}

/** One side of a resize: the edge the handle names moves, the one opposite it stays. */
function pulled(start: number, length: number, side: PlanSide, to: number): [number, number] {
  if (side === 0.5) return [start, length];
  const far = side === 1 ? start : start + length;
  const near = side === 1 ? Math.max(to, far + SNAP_METRES) : Math.min(to, far - SNAP_METRES);

  return [Math.min(near, far), Math.abs(near - far)];
}

/** A point turned clockwise about another, in degrees. */
function turn(point: PlanPoint, about: PlanPoint, degrees: number): PlanPoint {
  const radians = (degrees * Math.PI) / 180;
  const cos = Math.cos(radians);
  const sin = Math.sin(radians);
  const dx = point.x - about.x;
  const dy = point.y - about.y;

  return { x: about.x + dx * cos - dy * sin, y: about.y + dx * sin + dy * cos };
}

/** A floor has no negative side of it: `PlanRect` refuses a distance below zero. */
function onFloor(value: number): number {
  return tidy(Math.max(0, value));
}

/** Enough places for a quarter-metre, without a floating-point tail in the markup. */
function tidy(value: number): number {
  return Math.round(value * 1000) / 1000;
}

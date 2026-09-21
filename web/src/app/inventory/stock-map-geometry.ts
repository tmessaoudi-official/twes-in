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
 * The SVG viewBox that frames a floor, in metres, with a margin around what is drawn. A floor with nothing on it yet
 * is still a floor to draw on, so it answers a workable square — a box of no width renders as nothing at all, which
 * reads as a broken screen rather than as an empty plan.
 */
export function planViewBox(rects: readonly PlanRectangle[], padding: number): string {
  const bounds = planBounds(rects);
  const empty = rects.length === 0;
  const width = empty ? EMPTY_FLOOR_METRES : bounds.maxX - bounds.minX;
  const height = empty ? EMPTY_FLOOR_METRES : bounds.maxY - bounds.minY;

  return [bounds.minX - padding, bounds.minY - padding, width + padding * 2, height + padding * 2]
    .map(tidy)
    .join(' ');
}

/** Enough places for a quarter-metre, without a floating-point tail in the markup. */
function tidy(value: number): number {
  return Math.round(value * 1000) / 1000;
}

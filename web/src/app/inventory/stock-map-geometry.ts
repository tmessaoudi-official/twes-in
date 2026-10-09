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
 * How wide a handle's grip is on the screen, in pixels: WCAG 2.5.8's minimum target under a mouse, 2.5.5's under a
 * finger, which cannot see what it covers. The dot drawn stays small; the grip around it is what takes hold.
 */
export const GRIP_PIXELS = { fine: 24, coarse: 44 } as const;

/** A grip's radius in METRES at the scale shown, or `fallback` while the plan has not been measured. */
export function gripRadius(
  pixelsPerMetre: number | null,
  coarse: boolean,
  fallback: number,
): number {
  if (pixelsPerMetre === null || pixelsPerMetre <= 0) return fallback;

  return (coarse ? GRIP_PIXELS.coarse : GRIP_PIXELS.fine) / 2 / pixelsPerMetre;
}

/**
 * The handles a rectangle can carry without its middle being covered by them. A handle keeps one size on the screen,
 * so a side of four radii or less loses its middle handle: a 0,6 m rack on a 30 m floor could otherwise not be taken
 * hold of at all. The corners always stay, and the form keeps every measurement reachable (docs/SPEC.md § 7,
 * 2026-09-22).
 */
export function handlesThatFit(rect: PlanRectangle, radius: number): PlanHandle[] {
  const roomy = (length: number): boolean => length > radius * 4;

  return PLAN_HANDLES.filter(
    (handle) =>
      (handle.hx !== 0.5 || roomy(rect.depth)) && (handle.hy !== 0.5 || roomy(rect.width)),
  );
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

/**
 * Whether the selection box between two corners touches a rectangle as it stands. A turned rack is judged by its
 * body, not by the box around it, which would take a rack the selection only passed near: the two shapes are apart
 * exactly when, along one of their four edge directions, their shadows do not overlap.
 */
export function touchesBox(from: PlanPoint, to: PlanPoint, rect: PlanRectangle): boolean {
  const box = [
    { x: Math.min(from.x, to.x), y: Math.min(from.y, to.y) },
    { x: Math.max(from.x, to.x), y: Math.min(from.y, to.y) },
    { x: Math.max(from.x, to.x), y: Math.max(from.y, to.y) },
    { x: Math.min(from.x, to.x), y: Math.max(from.y, to.y) },
  ];
  const body = cornersOf(rect);
  const radians = (rect.rotation * Math.PI) / 180;
  const axes = [
    { x: 1, y: 0 },
    { x: 0, y: 1 },
    { x: Math.cos(radians), y: Math.sin(radians) },
    { x: -Math.sin(radians), y: Math.cos(radians) },
  ];
  const shadow = (points: PlanPoint[], axis: PlanPoint): [number, number] => {
    const along = points.map((point) => point.x * axis.x + point.y * axis.y);

    return [Math.min(...along), Math.max(...along)];
  };

  return axes.every((axis) => {
    const [boxLow, boxHigh] = shadow(box, axis);
    const [bodyLow, bodyHigh] = shadow(body, axis);

    return boxLow <= bodyHigh && bodyLow <= boxHigh;
  });
}

/** A position as the API keeps it: three decimals, so what the screen holds is what a save will answer. */
const millimetre = (value: number): number => Math.round(value * 1000) / 1000 || 0;

/**
 * A group moved together: the same step for every rectangle. A rectangle past the floor's near corner is one the API
 * refuses, so the step is held back there for the whole group, which keeps its shape rather than squashing at the edge.
 */
export function shiftedGroup<K>(
  rects: ReadonlyMap<K, PlanRectangle>,
  dx: number,
  dy: number,
): Map<K, PlanRectangle> {
  const all = [...rects.values()];
  const across = Math.max(dx, -Math.min(...all.map((rect) => rect.x)));
  const down = Math.max(dy, -Math.min(...all.map((rect) => rect.y)));

  return new Map(
    [...rects].map(([key, rect]) => [
      key,
      { ...rect, x: millimetre(rect.x + across), y: millimetre(rect.y + down) },
    ]),
  );
}

/**
 * A group turned clockwise about its own centre, as one rectangle turns about its own: every centre goes round and
 * every rectangle turns by the same step. The centre is the mean of the rectangles' centres, which a turn leaves
 * where it was — the middle of what they reach would wander as they turn, and R then Shift+R would not come back.
 */
export function turnedGroup<K>(
  rects: ReadonlyMap<K, PlanRectangle>,
  degrees: number,
): Map<K, PlanRectangle> {
  const all = [...rects.values()];
  if (all.length === 0) return new Map();
  const centres = all.map((rect) => ({ x: rect.x + rect.width / 2, y: rect.y + rect.depth / 2 }));
  const pivot = {
    x: centres.reduce((sum, centre) => sum + centre.x, 0) / centres.length,
    y: centres.reduce((sum, centre) => sum + centre.y, 0) / centres.length,
  };
  const radians = (degrees * Math.PI) / 180;
  const [cos, sin] = [Math.cos(radians), Math.sin(radians)];

  const turned = new Map(
    [...rects].map(([key, rect]) => {
      const dx = rect.x + rect.width / 2 - pivot.x;
      const dy = rect.y + rect.depth / 2 - pivot.y;

      return [
        key,
        {
          ...rect,
          x: millimetre(pivot.x + dx * cos - dy * sin - rect.width / 2),
          y: millimetre(pivot.y + dx * sin + dy * cos - rect.depth / 2),
          rotation: (((rect.rotation + degrees) % 360) + 360) % 360,
        },
      ];
    }),
  );

  return shiftedGroup(turned, 0, 0);
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
 * What part of the floor is being looked at: `scale` 1 is the whole of it, above that the window closes in on the
 * point `cx`,`cy` names. Until 2026-09-22 the plan had none of this — a 24-metre depot sat in a fixed window, so
 * one could neither come near enough to aim at a rack nor step back to find one's bearings (docs/SPEC.md § 7).
 */
export interface PlanView {
  scale: number;
  cx: number;
  cy: number;
}

/** Nearer than the whole floor only: there is nothing outside it worth showing. */
export const PLAN_ZOOM_MIN = 1;
export const PLAN_ZOOM_MAX = 8;
/** One press of a zoom button, chosen so four presses roughly quadruple. */
export const PLAN_ZOOM_STEP = 1.4;

/** The view that shows the whole of a floor, which is where the plan starts and what "fit" goes back to. */
export function fitView(fit: PlanFrame): PlanView {
  return { scale: 1, cx: fit.x + fit.width / 2, cy: fit.y + fit.height / 2 };
}

/**
 * The box to draw, from the floor's own frame and what is being looked at.
 *
 * Panning is held inside the floor: past its edge there is nothing to see and finding the way back is work, so the
 * centre may only travel by half of what the window does not cover.
 */
export function shownFrame(fit: PlanFrame, view: PlanView): PlanFrame {
  const scale = held(view.scale, PLAN_ZOOM_MIN, PLAN_ZOOM_MAX);
  const width = fit.width / scale;
  const height = fit.height / scale;
  const midX = fit.x + fit.width / 2;
  const midY = fit.y + fit.height / 2;
  const roomX = Math.max(0, (fit.width - width) / 2);
  const roomY = Math.max(0, (fit.height - height) / 2);

  return {
    x: tidy(held(view.cx, midX - roomX, midX + roomX) - width / 2),
    y: tidy(held(view.cy, midY - roomY, midY + roomY) - height / 2),
    width: tidy(width),
    height: tidy(height),
  };
}

/**
 * Closing in on a point while leaving that point where it is. Zooming towards the middle of the window instead
 * walks whatever one was aiming at off the screen, which is what makes a plan feel broken under the hand.
 */
export function zoomedAt(fit: PlanFrame, view: PlanView, scale: number, at: PlanPoint): PlanView {
  const shown = shownFrame(fit, view);
  const next = held(scale, PLAN_ZOOM_MIN, PLAN_ZOOM_MAX);
  const width = fit.width / next;
  const height = fit.height / next;
  const acrossX = shown.width === 0 ? 0.5 : (at.x - shown.x) / shown.width;
  const acrossY = shown.height === 0 ? 0.5 : (at.y - shown.y) / shown.height;

  return {
    scale: next,
    cx: at.x - acrossX * width + width / 2,
    cy: at.y - acrossY * height + height / 2,
  };
}

/** Dragging the floor: it follows the hand, so what is shown travels the other way. */
export function pannedBy(view: PlanView, dx: number, dy: number): PlanView {
  return { scale: view.scale, cx: view.cx - dx, cy: view.cy - dy };
}

/**
 * A pan button or an arrow key: what is shown moves the way it points, and its centre is written back where the
 * floor's edge held it, so a press past the edge is not banked for the way back.
 */
export function steppedBy(fit: PlanFrame, view: PlanView, dx: number, dy: number): PlanView {
  const shown = shownFrame(fit, { scale: view.scale, cx: view.cx + dx, cy: view.cy + dy });

  return {
    scale: view.scale,
    cx: tidy(shown.x + shown.width / 2),
    cy: tidy(shown.y + shown.height / 2),
  };
}

/** The lengths a scale bar may say: the ones a person reads at a glance and can count on the grid. */
const SCALE_BAR_LENGTHS = [0.5, 1, 2, 5, 10, 20, 50, 100] as const;

/** The scale bar's length for what is shown: the longest round one that takes no more than a fifth of it. */
export function scaleBarMetres(shownWidth: number): number {
  const most = shownWidth / 5;

  return SCALE_BAR_LENGTHS.filter((length) => length <= most).at(-1) ?? SCALE_BAR_LENGTHS[0];
}

function held(value: number, least: number, most: number): number {
  return Math.min(most, Math.max(least, value));
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

/**
 * A rectangle traced on bare floor, from the corner a gesture started at to the corner it is at now — **decision 1
 * of the approved canvas** (docs/SPEC.md § 7, 2026-09-21): a box is drawn where there is nothing yet, rather than
 * added at a default size and then moved to where it belongs.
 *
 * It is a gesture, so both corners take the quarter-metre magnet. It reads the same box whichever corner the hand
 * started at, because a person drawing up and to the left is drawing the same rack as one drawing down and to the
 * right. It is born unturned and flat: an angle and a height are measurements, and decision 3 says a measurement is
 * typed rather than dragged.
 */
export function tracedTo(from: PlanPoint, to: PlanPoint): PlanRectangle {
  const [x, width] = spanned(from.x, to.x);
  const [y, depth] = spanned(from.y, to.y);

  return { x, y, width, depth, rotation: 0, height: 0 };
}

/** One side of a traced box: where it starts and how long it is, never shorter than one step of the grid. */
function spanned(from: number, to: number): [number, number] {
  const start = onFloor(snapMetres(from));
  const end = onFloor(snapMetres(to));

  // A press that barely moved is still a box: `PlanRect` refuses a side of no length, so it opens to one grid step.
  return [Math.min(start, end), tidy(Math.max(Math.abs(end - start), SNAP_METRES))];
}

/**
 * A ready-made shape posed in the middle of the floor being shown — the palette's keyboard path, where there is no
 * pointer to say where it goes ("l'activer pose le rectangle au centre du plan", the approved canvas).
 *
 * Where it LANDS takes the magnet, like every other placement; its SIZE is carried through exactly, because that
 * size is what the company said a rack of theirs measures and posing it is not the moment to re-measure it.
 */
export function centredIn(frame: PlanFrame, width: number, depth: number): PlanRectangle {
  return {
    x: onFloor(snapMetres(frame.x + frame.width / 2 - width / 2)),
    y: onFloor(snapMetres(frame.y + frame.height / 2 - depth / 2)),
    width,
    depth,
    rotation: 0,
    height: 0,
  };
}

/** Which way a rectangle is repeated across the FLOOR — never across the rectangle, whatever angle it is set at. */
export type PlanWay = 'up' | 'down' | 'left' | 'right';

export const PLAN_WAYS: readonly PlanWay[] = ['up', 'down', 'left', 'right'];

/**
 * Where each copy of a repeat lands — the screen's mirror of `DrawStockMap::repeat()`, so the dotted preview shows
 * exactly what the API will create rather than an approximation of it. Two plans, one accepted and another made,
 * would be worse than no preview at all.
 *
 * The step runs along the floor's own axis and its size is the side the rectangle actually covers along that axis:
 * a rack turned a quarter turn is as wide across the floor's y as it is deep across its x. Only the four right
 * angles are allowed — their sines and cosines are whole numbers, so this arithmetic and the API's bcmath agree
 * exactly — and any other angle gives nothing back, which is how the screen knows to refuse the gesture rather than
 * offer one the API would reject.
 *
 * The spacing is TYPED and not dragged, so the magnet does not touch it (docs/SPEC.md § 7, 2026-09-21, 20:40): a
 * spacing of 0,1 m is taken as 0,1. Millimetres are counted as whole numbers throughout so a run of copies cannot
 * drift into 5.800000000000001.
 */
export function repeatedFrom(
  rect: PlanRectangle,
  count: number,
  spacing: number,
  way: PlanWay,
): PlanRectangle[] {
  if (count < 1 || rect.rotation % 90 !== 0) return [];
  const vertical = way === 'up' || way === 'down';
  const turned = (rect.rotation / 90) % 2 !== 0;
  const pitch = Math.round(((vertical === turned ? rect.width : rect.depth) + spacing) * 1000);
  const back = way === 'up' || way === 'left' ? -1 : 1;
  const from = Math.round((vertical ? rect.y : rect.x) * 1000);

  return Array.from({ length: count }, (_, made) => {
    const moved = (from + back * pitch * (made + 1)) / 1000;

    return vertical ? { ...rect, y: moved } : { ...rect, x: moved };
  });
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

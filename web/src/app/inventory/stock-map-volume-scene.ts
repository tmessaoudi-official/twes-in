// SPDX-License-Identifier: AGPL-3.0-or-later

import type {
  StockDrawingRow,
  StockLocationKind,
  StockStructureRow,
  StructureKind,
} from './inventory-types';
import { planRectangles, structureRectangles } from './stock-map-forms';
import type { PlanRectangle } from './stock-map-geometry';
import type { ColourTokens, StatusTokens } from '../shared/theme/accent-theme';

/**
 * What the volume view is made of, worked out here without a line of three.js: which box stands where, how tall,
 * lit or dimmed, and where the camera looks from. The component only turns these into meshes, so everything a
 * person could find wrong in the 3D is tested without a graphics card.
 */

/** How thick something marked out on the floor is drawn: enough to be seen above the ground, never a wall. */
export const FLAT_METRES = 0.05;

export type VolumePart = 'rack' | 'zone' | 'quarantine' | StructureKind | 'ground';

/** One box of the scene, in the plan's own axes: x to the right, y down the page, metres. */
export interface VolumeBox {
  key: string;
  part: VolumePart;
  /** The stock location it is drawn for; null for the building and the ground. */
  locationId: string | null;
  /** The centre of its footprint. */
  x: number;
  y: number;
  width: number;
  depth: number;
  height: number;
  /** Where its underside sits; the ground lies just below zero so nothing standing on it flickers into it. */
  base: number;
  /** About the upward axis, in radians. */
  turn: number;
  lit: boolean;
  dimmed: boolean;
  /** Chosen on the plan, in the list or here: marked, never dimmed, the rest left as it is. */
  chosen: boolean;
}

/** What the person chose to see: the building, the ground, and the racks at their height or flat. */
export interface VolumeToggles {
  building: boolean;
  ground: boolean;
  heights: boolean;
}

export interface VolumeExtent {
  x: number;
  y: number;
  width: number;
  depth: number;
}

export type CameraPresetName = 'overview' | 'top';

/** Where the camera stands and what it looks at, in three.js's axes: x as the plan's, y up, z as the plan's y. */
export interface CameraView {
  position: [number, number, number];
  target: [number, number, number];
}

export interface VolumeInput {
  /** The floor's measured size, or null when it never was. */
  size: { width: number; depth: number } | null;
  drawings: readonly StockDrawingRow[];
  structures: readonly StockStructureRow[];
  /** The stock locations holding what is looked for; empty when nothing is. */
  lit: ReadonlySet<string>;
  /** The drawings chosen; none when it is left out. */
  chosen?: ReadonlySet<string>;
  toggles: VolumeToggles;
}

/** Doors and docks are openings and edges: marked on the floor, or they would wall off what they open onto. */
const STANDING_STRUCTURES: ReadonlySet<StructureKind> = new Set(['wall', 'post']);

export function volumeBoxes(input: VolumeInput): VolumeBox[] {
  const boxes: VolumeBox[] = [];
  const searching = input.lit.size > 0;
  const chosen = input.chosen ?? new Set<string>();

  if (input.toggles.ground) {
    const ground = input.size ?? null;
    if (ground !== null) {
      boxes.push({
        key: 'ground',
        part: 'ground',
        locationId: null,
        x: ground.width / 2,
        y: ground.depth / 2,
        width: ground.width,
        depth: ground.depth,
        height: FLAT_METRES,
        base: -FLAT_METRES,
        turn: 0,
        lit: false,
        dimmed: false,
        chosen: false,
      });
    }
  }

  const rectangles = planRectangles(input.drawings);
  input.drawings.forEach((drawn, index) => {
    const rect = rectangles[index];
    if (rect === undefined) return;
    const lit = input.lit.has(drawn.locationId);
    const isChosen = chosen.has(drawn.id);
    boxes.push({
      ...footprint(rect),
      key: `drawing-${drawn.id}`,
      part: partOf(drawn.locationKind),
      locationId: drawn.locationId,
      height: input.toggles.heights && rect.height > FLAT_METRES ? rect.height : FLAT_METRES,
      base: 0,
      lit,
      dimmed: searching && !lit && !isChosen,
      chosen: isChosen,
    });
  });

  if (input.toggles.building) {
    const pieces = structureRectangles(input.structures);
    input.structures.forEach((piece, index) => {
      const rect = pieces[index];
      if (rect === undefined) return;
      boxes.push({
        ...footprint(rect),
        key: `structure-${piece.id}`,
        part: piece.kind,
        locationId: null,
        height:
          STANDING_STRUCTURES.has(piece.kind) && rect.height > FLAT_METRES
            ? rect.height
            : FLAT_METRES,
        base: 0,
        lit: false,
        dimmed: false,
        chosen: false,
      });
    });
  }

  return boxes;
}

/**
 * What the camera frames: the measured floor, or, on a floor never measured, everything drawn on it with each shape's
 * turned corners counted, so a rack turned a quarter is not framed by the rectangle it would be unturned.
 */
export function volumeExtent(
  size: { width: number; depth: number } | null,
  drawings: readonly StockDrawingRow[],
  structures: readonly StockStructureRow[],
): VolumeExtent {
  if (size !== null) return { x: 0, y: 0, width: size.width, depth: size.depth };
  const corners = [...planRectangles(drawings), ...structureRectangles(structures)].flatMap(
    cornersOf,
  );
  if (corners.length === 0) return { x: 0, y: 0, width: 10, depth: 10 };
  const xs = corners.map(([x]) => x);
  const ys = corners.map(([, y]) => y);
  const x = Math.min(...xs);
  const y = Math.min(...ys);

  return { x, y, width: Math.max(...xs) - x, depth: Math.max(...ys) - y };
}

/** The camera's vertical field of view, in degrees, which the presets fit the floor into. */
export const CAMERA_FOV = 45;

/**
 * The two places to look from. « Vue d'ensemble » stands off the south-west corner, raised, as a person would look
 * over the floor from its doorway; « Vue de dessus » looks straight down, which reads as the plan does. Both stand
 * back far enough for the whole floor, its tallest piece included, to fit the picture however wide or tall it is.
 */
export function cameraPreset(
  name: CameraPresetName,
  extent: VolumeExtent,
  aspect = 1,
  tallest = 0,
): CameraView {
  const centre: [number, number, number] = [
    extent.x + extent.width / 2,
    tallest / 2,
    extent.y + extent.depth / 2,
  ];
  const radius = Math.max(Math.hypot(extent.width, extent.depth, tallest) / 2, 1);
  const vertical = (CAMERA_FOV * Math.PI) / 360;
  const horizontal = Math.atan(Math.tan(vertical) * aspect);
  // A sphere round the floor fits a cone of this half-angle at this distance; a little more keeps the edges off the frame.
  const distance = (radius / Math.sin(Math.min(vertical, horizontal))) * 1.05;
  // A hair off the vertical for the top view: straight down, the camera's own up is undefined and the view would spin.
  const [dx, dy, dz] = name === 'top' ? [0, 1, 0.001] : [-0.5, 0.65, 0.75];
  const length = Math.hypot(dx, dy, dz);

  return {
    position: [
      centre[0] + (dx / length) * distance,
      centre[1] + (dy / length) * distance,
      centre[2] + (dz / length) * distance,
    ],
    target: centre,
  };
}

/** The same view walked round its target by an angle, at the same height and distance. */
export function turned(view: CameraView, angle: number): CameraView {
  const [x, y, z] = view.position;
  const [tx, , tz] = view.target;
  const dx = x - tx;
  const dz = z - tz;
  const cos = Math.cos(angle);
  const sin = Math.sin(angle);

  return { position: [tx + dx * cos + dz * sin, y, tz - dx * sin + dz * cos], target: view.target };
}

/** How far the camera comes in to a chosen place, in lengths of it: the place and what stands beside it, not a wall. */
export const LOOK_LENGTHS = 3;

/**
 * The same view turned to a place: it looks at the middle of the box from the side it stood on, so the angle a person
 * chose is kept and the chosen place comes to the centre, near enough to be seen. Nearer than that, it stays put.
 */
export function lookingAt(
  view: CameraView,
  box: Pick<VolumeBox, 'x' | 'y' | 'base' | 'width' | 'depth' | 'height'>,
): CameraView {
  const target: [number, number, number] = [box.x, box.base + box.height / 2, box.y];
  const offset = view.position.map((value, axis) => value - (view.target[axis] ?? 0));
  const far = Math.hypot(...offset) || 1;
  const near = Math.min(far, LOOK_LENGTHS * Math.max(box.width, box.depth, box.height));
  const [dx = 0, dy = 0, dz = 0] = offset.map((value) => (value / far) * near);

  return { position: [target[0] + dx, target[1] + dy, target[2] + dz], target };
}

/** The same view brought nearer its target, or taken further, by a factor of the distance. */
export function moved(view: CameraView, factor: number): CameraView {
  const [x, y, z] = view.position;
  const [tx, ty, tz] = view.target;

  return {
    position: [tx + (x - tx) * factor, ty + (y - ty) * factor, tz + (z - tz) * factor],
    target: view.target,
  };
}

/**
 * The theme token a part is painted with, the one its edges are drawn in, and how much of it shows: the plan's own
 * legend, standing up. Walls are seen through, or from most places they would hide the floor they close.
 */
export interface VolumeTones {
  fill: VolumeToken;
  edge: VolumeToken;
  opacity: number;
}

export type VolumeToken = keyof ColourTokens | keyof StatusTokens;

/** How much of a see-through wall, or of a place dimmed by a search, still shows. */
export const SEE_THROUGH = 0.35;

export const PART_TONES: Readonly<Record<VolumePart, VolumeTones>> = {
  rack: { fill: '--mat-sys-primary-container', edge: '--mat-sys-primary', opacity: 1 },
  zone: { fill: '--mat-sys-surface-container-high', edge: '--mat-sys-outline', opacity: 1 },
  quarantine: { fill: '--mat-sys-error-container', edge: '--mat-sys-error', opacity: 1 },
  wall: { fill: '--mat-sys-outline-variant', edge: '--mat-sys-outline', opacity: SEE_THROUGH },
  post: { fill: '--mat-sys-outline', edge: '--mat-sys-on-surface-variant', opacity: 1 },
  door: { fill: '--mat-sys-secondary-container', edge: '--mat-sys-secondary', opacity: 1 },
  dock: { fill: '--mat-sys-surface-container-highest', edge: '--mat-sys-outline', opacity: 1 },
  ground: {
    fill: '--mat-sys-surface-container-low',
    edge: '--mat-sys-outline-variant',
    opacity: 1,
  },
};

/**
 * What is looked for stands out in the search's warning tone at its strongest, the one its dot wears: the pale
 * ground the plan's highlight uses reads as the faintest box on the floor once it stands among coloured racks.
 */
export const LIT_TONES: VolumeTones = {
  fill: '--twes-status-warning-dot',
  edge: '--twes-status-warning-fg',
  opacity: 1,
};

/** Everything else steps back to one quiet tone, see-through, so the light is never colour alone. */
export const DIMMED_TONES: VolumeTones = {
  fill: '--mat-sys-outline-variant',
  edge: '--mat-sys-outline-variant',
  opacity: SEE_THROUGH,
};

/**
 * What is chosen, as the plan marks it: a lighter fill and a dark edge, the same in both schemes, so it reads against
 * the racks beside it without a colour of its own (docs/SPEC.md § 7, 2026-10-09 23:19).
 */
export const CHOSEN_TONES: VolumeTones = {
  fill: '--mat-sys-primary-fixed-dim',
  edge: '--mat-sys-on-surface',
  opacity: 1,
};

/** The see-through ring round what is chosen, a little wider and taller than it, in metres. */
export const CHOSEN_HALO = { margin: 0.35, tone: '--mat-sys-primary', opacity: 0.3 } as const;

export function haloOf(box: VolumeBox): VolumeBox {
  return {
    ...box,
    key: `${box.key}-halo`,
    width: box.width + 2 * CHOSEN_HALO.margin,
    depth: box.depth + 2 * CHOSEN_HALO.margin,
    height: box.height + CHOSEN_HALO.margin,
  };
}

export function tonesOf(
  box: Pick<VolumeBox, 'part' | 'lit' | 'dimmed'> & { chosen?: boolean },
): VolumeTones {
  // A chosen place a search lit keeps the search's light: only its edge says it is the one chosen.
  if (box.lit) return box.chosen === true ? { ...LIT_TONES, edge: CHOSEN_TONES.edge } : LIT_TONES;
  if (box.chosen === true) return CHOSEN_TONES;
  return box.dimmed ? DIMMED_TONES : PART_TONES[box.part];
}

/** How many of each the floor shows, which the view says in words for whoever cannot see it. */
export function volumeCounts(boxes: readonly VolumeBox[]): {
  racks: number;
  zones: number;
  lit: number;
} {
  return {
    racks: boxes.filter((box) => box.part === 'rack').length,
    zones: boxes.filter((box) => box.part === 'zone' || box.part === 'quarantine').length,
    lit: boxes.filter((box) => box.lit).length,
  };
}

function partOf(kind: StockLocationKind): VolumePart {
  if (kind === 'rack') return 'rack';
  if (kind === 'quarantine') return 'quarantine';
  return 'zone';
}

function footprint(rect: PlanRectangle): Pick<VolumeBox, 'x' | 'y' | 'width' | 'depth' | 'turn'> {
  return {
    x: rect.x + rect.width / 2,
    y: rect.y + rect.depth / 2,
    width: rect.width,
    depth: rect.depth,
    // Clockwise on the plan, which is seen from above with y down the page, is the negative way about the up axis.
    turn: (-rect.rotation * Math.PI) / 180,
  };
}

function cornersOf(rect: PlanRectangle): [number, number][] {
  const cx = rect.x + rect.width / 2;
  const cy = rect.y + rect.depth / 2;
  const angle = (rect.rotation * Math.PI) / 180;
  const cos = Math.cos(angle);
  const sin = Math.sin(angle);

  return [
    [-rect.width / 2, -rect.depth / 2],
    [rect.width / 2, -rect.depth / 2],
    [rect.width / 2, rect.depth / 2],
    [-rect.width / 2, rect.depth / 2],
  ].map(([dx = 0, dy = 0]) => [cx + dx * cos - dy * sin, cy + dx * sin + dy * cos]);
}

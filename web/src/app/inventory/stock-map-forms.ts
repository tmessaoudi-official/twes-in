// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { snapAngle, snapMetres, type PlanRectangle } from './stock-map-geometry';
import {
  DRAWABLE_STOCK_LOCATION_KINDS,
  type StockDrawingInput,
  type StockDrawingRow,
  type StockFloorInput,
  type StockFloorRow,
  type StockLocationRow,
  type StockOptions,
  STRUCTURE_KINDS,
  type StockStructureInput,
  type StockStructureRow,
  type StockStructureShape,
  type StructureKind,
} from './inventory-types';

const FLOOR_FIELDS = 'inventory.plan.floor_fields';
const DRAWING_FIELDS = 'inventory.plan.drawing_fields';
const STRUCTURE_FIELDS = 'inventory.plan.structure_fields';

/** The length the API's column holds, so the box stops where the server would refuse rather than after it. */
const STRUCTURE_NAME_MAX = 120;

/** How many decimals a measurement crosses the wire with, as the API's columns hold them. */
const METRE_DECIMALS = 3;

/** A rectangle a person has just added: big enough to see and to take hold of, on the grid. */
const NEW_WIDTH = 2;
const NEW_DEPTH = 1;
const NEW_HEIGHT = 2;

/** Whole degrees, one circle, in the fifteen-degree steps decision 3 draws in. */
const MAX_ROTATION = 345;

/** A floor of an establishment: which place it is in, what it is called, and which storey it is. */
export function floorForm(options: StockOptions, editing: StockFloorRow | null): FormDescriptor {
  return {
    id: 'stock-floor',
    sections: [
      {
        id: 'floor',
        title: 'inventory.plan.floor_section',
        fields: [
          {
            id: 'establishmentId',
            label: `${FLOOR_FIELDS}.establishmentId`,
            kind: 'select',
            required: true,
            // A floor never moves to another establishment: that is another floor, in another place.
            readOnly: editing !== null,
            options: options.establishments.map((establishment) => ({
              value: establishment.id,
              label: `${establishment.code} — ${establishment.name}`,
            })),
          },
          {
            id: 'name',
            label: `${FLOOR_FIELDS}.name`,
            kind: 'text',
            required: true,
            maxLength: 120,
          },
          {
            id: 'level',
            label: `${FLOOR_FIELDS}.level`,
            kind: 'number',
            required: true,
            hint: 'inventory.plan.level_hint',
          },
        ],
      },
    ],
  };
}

export function floorValues(row: StockFloorRow | null, options: StockOptions): FormValues {
  const only = options.establishments.length === 1 ? (options.establishments[0]?.id ?? '') : '';

  return {
    establishmentId: row?.establishmentId ?? only,
    name: row?.name ?? '',
    level: row?.level ?? 0,
  };
}

/**
 * What the floor form sends. The plan image behind the floor is not on this form, so what the floor already has is
 * carried through rather than cleared: one screen's save must not undo another screen's.
 */
export function floorInput(values: FormValues, editing: StockFloorRow | null): StockFloorInput {
  return {
    establishmentId: text(values['establishmentId']),
    name: text(values['name']),
    level: Math.trunc(Number(values['level'] ?? 0)) || 0,
    imageFileId: editing?.imageFileId ?? null,
    imageMetresWide: editing?.imageMetresWide ?? null,
    imageOpacity: editing?.imageOpacity ?? 35,
  };
}

/**
 * One rectangle: which location it is drawn for, and where it sits. A location is drawn in one place, so the ones
 * already drawn are not offered again — except the one being edited, which must stay offered or its own form could
 * not be saved back. A bin is never offered: it is placed in its rack's front view (decision 2).
 */
export function drawingForm(
  locations: readonly StockLocationRow[],
  drawings: readonly StockDrawingRow[],
  editing: StockDrawingRow | null,
): FormDescriptor {
  const drawnElsewhere = new Set(
    drawings.filter((drawn) => drawn.id !== editing?.id).map((drawn) => drawn.locationId),
  );
  const offered = locations.filter(
    (location) =>
      DRAWABLE_STOCK_LOCATION_KINDS.includes(location.kind) && !drawnElsewhere.has(location.id),
  );

  return {
    id: 'stock-drawing',
    sections: [
      {
        id: 'drawn-for',
        title: 'inventory.plan.drawing_section',
        fields: [
          {
            id: 'locationId',
            label: `${DRAWING_FIELDS}.locationId`,
            kind: 'select',
            required: true,
            span: 2,
            hint: 'inventory.plan.location_hint',
            options: offered.map((location) => ({
              value: location.id,
              label: `${location.code} — ${location.name}`,
            })),
          },
        ],
      },
      {
        id: 'footprint',
        title: 'inventory.plan.footprint_section',
        description: 'inventory.plan.footprint_hint',
        fields: [
          { id: 'x', label: `${DRAWING_FIELDS}.x`, kind: 'decimal', required: true },
          { id: 'y', label: `${DRAWING_FIELDS}.y`, kind: 'decimal', required: true },
          { id: 'width', label: `${DRAWING_FIELDS}.width`, kind: 'decimal', required: true },
          { id: 'depth', label: `${DRAWING_FIELDS}.depth`, kind: 'decimal', required: true },
          { id: 'height', label: `${DRAWING_FIELDS}.height`, kind: 'decimal', required: true },
          {
            id: 'rotation',
            label: `${DRAWING_FIELDS}.rotation`,
            kind: 'number',
            required: true,
            min: 0,
            max: MAX_ROTATION,
            hint: 'inventory.plan.rotation_hint',
          },
        ],
      },
    ],
  };
}

export function drawingValues(row: StockDrawingRow | null): FormValues {
  return {
    locationId: row?.locationId ?? '',
    x: row?.x ?? metres(0),
    y: row?.y ?? metres(0),
    width: row?.width ?? metres(NEW_WIDTH),
    depth: row?.depth ?? metres(NEW_DEPTH),
    rotation: row?.rotation ?? 0,
    height: row?.height ?? metres(NEW_HEIGHT),
  };
}

/**
 * What the rectangle form sends. **The grid holds a rectangle's PLACE, never its SIZE** — the approved canvas draws
 * a rack of `3,90 × 0,60 m` sitting at `x 2,50 y 4,00`, and its scale reads *aimanté sur 0,25 m*, a magnet, which is
 * what a grid is while something is dragged.
 *
 * So where a rack stands is a decision about the plan and is taken to the quarter-metre; how wide and how deep it is
 * is a fact about the rack, and rounding a real 3,90 m rack up to 4,00 m would be losing a measurement somebody took.
 * The height is left alone for the same reason, and it is not on the floor's grid at all. The rotation IS a step: a
 * rack stands square to a wall or at an angle off it, and decision 3 draws those in fifteens.
 */
export function drawingInput(values: FormValues): StockDrawingInput {
  return {
    locationId: text(values['locationId']),
    x: onGrid(values['x']),
    y: onGrid(values['y']),
    width: metres(Number(values['width'] ?? 0) || 0),
    depth: metres(Number(values['depth'] ?? 0) || 0),
    rotation: snapAngle(Number(values['rotation'] ?? 0) || 0),
    height: metres(Number(values['height'] ?? 0) || 0),
  };
}

/**
 * What a GESTURE writes into the open form — the one road from the plan back into the fields. It is shaped exactly
 * like what the API answered, because the unsaved-change count compares the two: a `1300` written against a saved
 * `1300.000` would read as a change and highlight a field nobody touched.
 *
 * The location is deliberately absent. Dragging a rectangle moves a rack; it never decides which rack it is.
 */
export function rectValues(rect: PlanRectangle): FormValues {
  return { ...footprintValues(rect), rotation: rect.rotation, height: metres(rect.height) };
}

/**
 * Where a rectangle sits and how much floor it takes — and nothing else. It is what a box traced on bare floor
 * decides: an angle and a height are MEASUREMENTS, which decision 3 says are typed rather than dragged, so a trace
 * leaves both at whatever the form offers instead of flattening a new rack to nothing.
 */
export function footprintValues(rect: PlanRectangle): FormValues {
  return {
    x: metres(rect.x),
    y: metres(rect.y),
    width: metres(rect.width),
    depth: metres(rect.depth),
  };
}

/** The rectangles of a floor as the drawing works in them: metres as numbers, never the API's strings. */
export function planRectangles(drawings: readonly StockDrawingRow[]): PlanRectangle[] {
  return drawings.map((drawn) => ({
    x: Number(drawn.x),
    y: Number(drawn.y),
    width: Number(drawn.width),
    depth: Number(drawn.depth),
    rotation: drawn.rotation,
    height: Number(drawn.height),
  }));
}

function onGrid(value: unknown): string {
  return metres(snapMetres(Number(value ?? 0) || 0));
}

function metres(value: number): string {
  return value.toFixed(METRE_DECIMALS);
}

function text(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

/**
 * The codes a repeat will create: the first as it was typed, then its number counted on, keeping the width it was
 * written with so `R01` is followed by `R02` and not by `R2`. It mirrors `DrawStockMap::codes()` so the panel can
 * list what will be made BEFORE it is made — the approved canvas's "Codes à créer" and "Ce qui sera créé".
 *
 * A code with no number to count on from gives nothing back, which is how the panel knows to refuse the gesture
 * rather than offer one the API would answer 422 to.
 */
export function nextCodes(firstCode: string, count: number): string[] {
  const found = /^(.*?)(\d+)$/.exec(firstCode.trim());
  if (found === null || count < 1) return [];
  const [, stem, number] = found;

  return Array.from(
    { length: count },
    (_, made) =>
      // Padded back to the width it was typed with, and never truncated: R99 is followed by R100.
      `${stem}${String(Number(number) + made).padStart(number.length, '0')}`,
  );
}

/**
 * A piece of the building: which of the four tools it is, and its footprint. There is deliberately NO location
 * field and never will be — nothing on this layer holds goods, which is the whole reason it is a layer apart.
 *
 * The kind sits in its own section and comes first, because it is the one field that changes what the rectangle
 * MEANS rather than where it is, and because correcting it is the common repair: a doorway traced with the wall
 * tool is right in every measurement and wrong in exactly this one.
 */
export function structureForm(): FormDescriptor {
  return {
    id: 'stock-structure',
    sections: [
      {
        id: 'built-as',
        title: 'inventory.plan.structure_section',
        description: 'inventory.plan.structure_hint',
        fields: [
          {
            id: 'kind',
            label: `${STRUCTURE_FIELDS}.kind`,
            kind: 'select',
            required: true,
            span: 2,
            options: STRUCTURE_KINDS.map((kind) => ({
              value: kind,
              label: `inventory.plan.structure_kinds.${kind}`,
            })),
          },
          // Never required: a store arrives having numbered its own building, and most walls are still just walls.
          {
            id: 'name',
            label: `${STRUCTURE_FIELDS}.name`,
            kind: 'text',
            span: 2,
            maxLength: STRUCTURE_NAME_MAX,
            hint: 'inventory.plan.structure_name_hint',
          },
        ],
      },
      {
        id: 'footprint',
        title: 'inventory.plan.footprint_section',
        description: 'inventory.plan.footprint_hint',
        fields: [
          { id: 'x', label: `${DRAWING_FIELDS}.x`, kind: 'decimal', required: true },
          { id: 'y', label: `${DRAWING_FIELDS}.y`, kind: 'decimal', required: true },
          { id: 'width', label: `${STRUCTURE_FIELDS}.width`, kind: 'decimal', required: true },
          { id: 'depth', label: `${STRUCTURE_FIELDS}.depth`, kind: 'decimal', required: true },
          { id: 'height', label: `${DRAWING_FIELDS}.height`, kind: 'decimal', required: true },
          {
            id: 'rotation',
            label: `${DRAWING_FIELDS}.rotation`,
            kind: 'number',
            required: true,
            min: 0,
            max: MAX_ROTATION,
            hint: 'inventory.plan.rotation_hint',
          },
        ],
      },
    ],
  };
}

/**
 * A piece as it was saved, or a new one at the size THIS company builds at. Nothing here invents a measurement:
 * a tool the company's settings say nothing about opens at zero rather than at a constant written in this file,
 * so a palette that could not be read is visibly empty instead of quietly wrong.
 */
export function structureValues(
  row: StockStructureRow | null,
  tools: readonly StockStructureShape[],
  kind: StructureKind = 'wall',
): FormValues {
  if (row !== null) {
    return {
      kind: row.kind,
      name: row.name,
      x: row.x,
      y: row.y,
      width: row.width,
      depth: row.depth,
      rotation: row.rotation,
      height: row.height,
    };
  }
  const tool = tools.find((one) => one.kind === kind);

  return {
    kind,
    name: '',
    x: metres(0),
    y: metres(0),
    width: metres(tool?.width ?? 0),
    depth: metres(tool?.depth ?? 0),
    rotation: 0,
    height: metres(tool?.height ?? 0),
  };
}

/**
 * What the structure form sends. The same rule as a rectangle of stock, for the same reason: the grid holds a
 * PLACE and never a measurement somebody went and took, so x and y are taken to the quarter-metre while a wall's
 * length and thickness are kept exactly as typed — the canvas's own partition is 0,20 m thick and a grid would
 * make it 0,25.
 *
 * An unknown kind becomes a wall rather than travelling on. The select is the only way in, so this is a guard
 * against a future caller rather than against a person: it fails to the one kind that is always drawable.
 */
export function structureInput(values: FormValues): StockStructureInput {
  const asked = text(values['kind']);

  return {
    kind: STRUCTURE_KINDS.find((kind) => kind === asked) ?? 'wall',
    name: text(values['name']),
    x: onGrid(values['x']),
    y: onGrid(values['y']),
    width: metres(Number(values['width'] ?? 0) || 0),
    depth: metres(Number(values['depth'] ?? 0) || 0),
    rotation: snapAngle(Number(values['rotation'] ?? 0) || 0),
    height: metres(Number(values['height'] ?? 0) || 0),
  };
}

/** The building of a floor as the drawing works in it: metres as numbers, never the API's strings. */
export function structureRectangles(structures: readonly StockStructureRow[]): PlanRectangle[] {
  return structures.map((piece) => ({
    x: Number(piece.x),
    y: Number(piece.y),
    width: Number(piece.width),
    depth: Number(piece.depth),
    rotation: piece.rotation,
    height: Number(piece.height),
  }));
}

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
} from './inventory-types';

const FLOOR_FIELDS = 'inventory.plan.floor_fields';
const DRAWING_FIELDS = 'inventory.plan.drawing_fields';

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
  return {
    x: metres(rect.x),
    y: metres(rect.y),
    width: metres(rect.width),
    depth: metres(rect.depth),
    rotation: rect.rotation,
    height: metres(rect.height),
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

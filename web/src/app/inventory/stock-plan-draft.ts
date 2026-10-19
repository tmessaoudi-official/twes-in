// SPDX-License-Identifier: AGPL-3.0-or-later

import type {
  StockDrawingRow,
  StockFloorRow,
  StockLocationKind,
  StockLocationRow,
  StockStructureRow,
  StructureKind,
} from './inventory-types';
import type { PlanRectangle } from './stock-map-geometry';

/**
 * A floor's plan as it is being arranged and not saved yet. Every gesture and every typed measurement lands here, on
 * the shape it was made on, so the board, the counter, the 3D and « Enregistrer » all read one thing. Before it, a
 * drag wrote into whichever form was open: dragging a second rack moved the first, and work in one form was lost
 * when another shape was taken hold of.
 *
 * It holds only what differs from what was saved, so a shape put back where it stood is no change at all, and it is
 * plain data, so it can be kept in the browser and offered again after the page is left.
 */
export interface PlanDraft {
  readonly floorId: string;
  /** Saved rectangles of stock changed: where each now stands, what it is drawn for, and the version read. */
  readonly drawings: Readonly<Record<string, DraftDrawing>>;
  /** Saved rectangles of stock to undraw, by id, with the version read. Their places stay. */
  readonly erasedDrawings: Readonly<Record<string, number>>;
  readonly newDrawings: readonly NewDrawing[];
  /** Saved pieces of the building changed, by id. */
  readonly structures: Readonly<Record<string, DraftStructure>>;
  readonly erasedStructures: Readonly<Record<string, number>>;
  readonly newStructures: readonly NewStructure[];
  /** The floor's own size, widened or lengthened by its edge, with the version read; null while it is as saved. */
  readonly floor: DraftFloor | null;
  /** The number the next new shape's key is made from, so a key is never given twice in one draft. */
  readonly next: number;
}

export interface DraftFloor {
  readonly width: number;
  readonly depth: number;
  readonly version: number;
}

/** What the draft needs of the floor as saved: its measured size, when it has one, and its version. */
export type SavedFloor = Pick<StockFloorRow, 'widthMetres' | 'depthMetres' | 'version'>;

/** A floor's size in metres, as the board frames it. */
export interface FloorSize {
  readonly width: number;
  readonly depth: number;
}

export interface DraftDrawing {
  readonly rect: PlanRectangle;
  readonly locationId: string;
  readonly version: number;
}

/** What a new rectangle is drawn for: a place that exists and is not drawn, or one created with it. */
export type DraftPlace =
  | { readonly locationId: string }
  | { readonly kind: StockLocationKind; readonly code: string; readonly name: string };

export interface NewDrawing {
  /** Its id on the board until it is saved: `new-<n>`. */
  readonly key: string;
  readonly rect: PlanRectangle;
  readonly place: DraftPlace;
}

export interface DraftStructure {
  readonly rect: PlanRectangle;
  readonly kind: StructureKind;
  readonly name: string;
  readonly version: number;
}

export interface NewStructure {
  readonly key: string;
  readonly rect: PlanRectangle;
  readonly kind: StructureKind;
  readonly name: string;
}

/** What a piece of the building is, besides where it stands. */
export interface StructureFacts {
  readonly kind: StructureKind;
  readonly name: string;
}

/** A new shape's id on the board, which no saved one can carry (theirs are uuids). */
export const NEW_KEY_PREFIX = 'new-';

export function isNewKey(id: string): boolean {
  return id.startsWith(NEW_KEY_PREFIX);
}

export function emptyDraft(floorId: string): PlanDraft {
  return {
    floorId,
    drawings: {},
    erasedDrawings: {},
    newDrawings: [],
    structures: {},
    erasedStructures: {},
    newStructures: [],
    floor: null,
    next: 1,
  };
}

/** How many shapes the draft changes: each moved, drawn, built or erased shape counts once. */
export function changeCount(draft: PlanDraft): number {
  return (
    Object.keys(draft.drawings).length +
    Object.keys(draft.erasedDrawings).length +
    draft.newDrawings.length +
    Object.keys(draft.structures).length +
    Object.keys(draft.erasedStructures).length +
    draft.newStructures.length +
    (draft.floor === null ? 0 : 1)
  );
}

/** The floor now this wide and this long; back at its saved size it is no change. */
export function sizeFloor(draft: PlanDraft, size: FloorSize, saved: SavedFloor): PlanDraft {
  const kept = (value: number): string => value.toFixed(3);
  const unchanged =
    saved.widthMetres !== null &&
    saved.depthMetres !== null &&
    kept(size.width) === kept(Number(saved.widthMetres)) &&
    kept(size.depth) === kept(Number(saved.depthMetres));

  return {
    ...draft,
    floor: unchanged
      ? null
      : { width: size.width, depth: size.depth, version: draft.floor?.version ?? saved.version },
  };
}

/** The floor's size as the board shows it: the draft's, else the saved one, else none (a floor never measured). */
export function shownFloorSize(draft: PlanDraft, saved: SavedFloor | null): FloorSize | null {
  if (draft.floor !== null) return { width: draft.floor.width, depth: draft.floor.depth };
  const width = Number(saved?.widthMetres ?? Number.NaN);
  const depth = Number(saved?.depthMetres ?? Number.NaN);

  return width > 0 && depth > 0 ? { width, depth } : null;
}

/** A rectangle as the API answered it, in metres as numbers. */
export function rectOf(
  row: Pick<StockDrawingRow, 'x' | 'y' | 'width' | 'depth' | 'rotation' | 'height'>,
): PlanRectangle {
  return {
    x: Number(row.x),
    y: Number(row.y),
    width: Number(row.width),
    depth: Number(row.depth),
    rotation: row.rotation,
    height: Number(row.height),
  };
}

/** Equal at the three decimals the API keeps: a drag back to where a rack stood is no change. */
export function sameRect(one: PlanRectangle, other: PlanRectangle): boolean {
  const kept = (value: number): string => value.toFixed(3);

  return (
    kept(one.x) === kept(other.x) &&
    kept(one.y) === kept(other.y) &&
    kept(one.width) === kept(other.width) &&
    kept(one.depth) === kept(other.depth) &&
    one.rotation === other.rotation &&
    kept(one.height) === kept(other.height)
  );
}

/**
 * A rectangle of stock now standing at `rect` (and drawn for `locationId`, when given). `saved` is the row as the API
 * answered it; a new one is found by its key. The version kept is the one read when the shape was FIRST changed, so a
 * reload in between does not quietly move the draft onto someone else's save.
 */
export function placeDrawing(
  draft: PlanDraft,
  id: string,
  rect: PlanRectangle,
  saved: StockDrawingRow | null,
  locationId?: string,
): PlanDraft {
  if (isNewKey(id)) {
    return {
      ...draft,
      newDrawings: draft.newDrawings.map((one) =>
        one.key === id
          ? { ...one, rect, place: locationId === undefined ? one.place : { locationId } }
          : one,
      ),
    };
  }
  if (saved === null || draft.erasedDrawings[id] !== undefined) return draft;
  const held = draft.drawings[id];
  const boundTo = locationId ?? held?.locationId ?? saved.locationId;
  const others = without(draft.drawings, id);
  if (sameRect(rect, rectOf(saved)) && boundTo === saved.locationId) {
    return { ...draft, drawings: others };
  }

  return {
    ...draft,
    drawings: {
      ...others,
      [id]: { rect, locationId: boundTo, version: held?.version ?? saved.version },
    },
  };
}

/** A piece of the building now standing at `rect`, with what it is when given. */
export function placeStructure(
  draft: PlanDraft,
  id: string,
  rect: PlanRectangle,
  saved: StockStructureRow | null,
  facts?: StructureFacts,
): PlanDraft {
  if (isNewKey(id)) {
    return {
      ...draft,
      newStructures: draft.newStructures.map((one) =>
        one.key === id ? { ...one, rect, ...(facts ?? {}) } : one,
      ),
    };
  }
  if (saved === null || draft.erasedStructures[id] !== undefined) return draft;
  const held = draft.structures[id];
  const kind = facts?.kind ?? held?.kind ?? saved.kind;
  const name = facts?.name ?? held?.name ?? saved.name;
  const others = without(draft.structures, id);
  if (sameRect(rect, rectOf(saved)) && kind === saved.kind && name === saved.name) {
    return { ...draft, structures: others };
  }

  return {
    ...draft,
    structures: {
      ...others,
      [id]: { rect, kind, name, version: held?.version ?? saved.version },
    },
  };
}

/** A new rectangle of stock, and the key it is known by until it is saved. */
export function addDrawing(
  draft: PlanDraft,
  rect: PlanRectangle,
  place: DraftPlace,
): { draft: PlanDraft; key: string } {
  const key = `${NEW_KEY_PREFIX}${draft.next}`;

  return {
    key,
    draft: {
      ...draft,
      next: draft.next + 1,
      newDrawings: [...draft.newDrawings, { key, rect, place }],
    },
  };
}

export function addStructure(
  draft: PlanDraft,
  rect: PlanRectangle,
  facts: StructureFacts,
): { draft: PlanDraft; key: string } {
  const key = `${NEW_KEY_PREFIX}${draft.next}`;

  return {
    key,
    draft: {
      ...draft,
      next: draft.next + 1,
      newStructures: [...draft.newStructures, { key, rect, ...facts }],
    },
  };
}

/** What a new rectangle is drawn for, changed in its form. */
export function placeFor(draft: PlanDraft, key: string, place: DraftPlace): PlanDraft {
  return {
    ...draft,
    newDrawings: draft.newDrawings.map((one) => (one.key === key ? { ...one, place } : one)),
  };
}

/**
 * Shapes taken off the plan: a new one is simply forgotten, a saved one is undrawn when the draft is saved (a rack's
 * place stays, with its stock: only its rectangle goes). Whatever was changed on a saved one is dropped with it.
 */
export function erase(
  draft: PlanDraft,
  ids: Iterable<string>,
  savedDrawings: readonly StockDrawingRow[],
  savedStructures: readonly StockStructureRow[],
): PlanDraft {
  let next = draft;
  for (const id of ids) {
    if (isNewKey(id)) {
      next = {
        ...next,
        newDrawings: next.newDrawings.filter((one) => one.key !== id),
        newStructures: next.newStructures.filter((one) => one.key !== id),
      };
      continue;
    }
    const drawing = savedDrawings.find((one) => one.id === id);
    if (drawing !== undefined) {
      const { [id]: held, ...others } = next.drawings;
      next = {
        ...next,
        drawings: others,
        erasedDrawings: { ...next.erasedDrawings, [id]: held?.version ?? drawing.version },
      };
      continue;
    }
    const piece = savedStructures.find((one) => one.id === id);
    if (piece !== undefined) {
      const { [id]: held, ...others } = next.structures;
      next = {
        ...next,
        structures: others,
        erasedStructures: { ...next.erasedStructures, [id]: held?.version ?? piece.version },
      };
    }
  }

  return next;
}

/**
 * The rectangles of stock as the board shows them: the saved ones where the draft put them, the erased ones gone,
 * the new ones added. A new rectangle reads its place's code and name from `locations`, or from what was typed for a
 * place created with it.
 */
export function shownDrawings(
  draft: PlanDraft,
  saved: readonly StockDrawingRow[],
  locations: readonly StockLocationRow[],
): StockDrawingRow[] {
  const located = (
    locationId: string,
  ): Pick<StockDrawingRow, 'locationCode' | 'locationName' | 'locationKind'> | null => {
    const location = locations.find((one) => one.id === locationId);

    return location === undefined
      ? null
      : { locationCode: location.code, locationName: location.name, locationKind: location.kind };
  };
  const kept = saved
    .filter((row) => draft.erasedDrawings[row.id] === undefined)
    .map((row) => {
      const held = draft.drawings[row.id];
      if (held === undefined) return row;
      const relabelled = held.locationId === row.locationId ? null : located(held.locationId);

      return { ...row, ...textOf(held.rect), locationId: held.locationId, ...(relabelled ?? {}) };
    });
  const added = draft.newDrawings.map((one): StockDrawingRow => {
    const place =
      'locationId' in one.place
        ? (located(one.place.locationId) ?? {
            locationCode: '',
            locationName: '',
            locationKind: 'rack' as const,
          })
        : {
            locationCode: one.place.code,
            locationName: one.place.name,
            locationKind: one.place.kind,
          };

    return {
      id: one.key,
      floorId: draft.floorId,
      locationId: 'locationId' in one.place ? one.place.locationId : '',
      ...place,
      ...textOf(one.rect),
      version: 0,
    };
  });

  return [...kept, ...added];
}

export function shownStructures(
  draft: PlanDraft,
  saved: readonly StockStructureRow[],
): StockStructureRow[] {
  const kept = saved
    .filter((row) => draft.erasedStructures[row.id] === undefined)
    .map((row) => {
      const held = draft.structures[row.id];

      return held === undefined
        ? row
        : { ...row, ...textOf(held.rect), kind: held.kind, name: held.name };
    });
  const added = draft.newStructures.map((one): StockStructureRow => ({
    id: one.key,
    floorId: draft.floorId,
    kind: one.kind,
    name: one.name,
    ...textOf(one.rect),
    version: 0,
  }));

  return [...kept, ...added];
}

/** A rectangle as the API takes it, at the three decimals it keeps; the turn is a whole number of degrees. */
export function textOf(rect: PlanRectangle): {
  x: string;
  y: string;
  width: string;
  depth: string;
  rotation: number;
  height: string;
} {
  return {
    x: rect.x.toFixed(3),
    y: rect.y.toFixed(3),
    width: rect.width.toFixed(3),
    depth: rect.depth.toFixed(3),
    rotation: Math.round(rect.rotation),
    height: rect.height.toFixed(3),
  };
}

/** One step of a floor, as `POST …/stock-floors/{floorId}/drawing-changes` takes it: all of it or none of it. */
export interface PlanStep {
  draws: (ReturnType<typeof textOf> & { locationId: string })[];
  moves: (ReturnType<typeof textOf> & {
    drawingId: string;
    version: number;
    locationId?: string;
  })[];
  erasures: { drawingId: string; version: number }[];
  newPlaces: (ReturnType<typeof textOf> & {
    kind: StockLocationKind;
    code: string;
    name: string;
  })[];
  structureBuilds: (ReturnType<typeof textOf> & StructureFacts)[];
  structureMoves: (ReturnType<typeof textOf> &
    StructureFacts & { structureId: string; version: number })[];
  structureErasures: { structureId: string; version: number }[];
  floor?: { widthMetres: string; depthMetres: string; version: number };
}

/** Where each entry of a step came from on the board, so a refusal naming `newPlaces[1].code` finds its shape. */
export interface PlanStepSources {
  draws: string[];
  moves: string[];
  erasures: string[];
  newPlaces: string[];
  structureBuilds: string[];
  structureMoves: string[];
  structureErasures: string[];
}

/** The draft as one step. `saved` tells a move whether its place changed, which only then travels. */
export function stepOf(
  draft: PlanDraft,
  saved: readonly StockDrawingRow[],
): { step: PlanStep; sources: PlanStepSources } {
  const step: PlanStep = {
    draws: [],
    moves: [],
    erasures: [],
    newPlaces: [],
    structureBuilds: [],
    structureMoves: [],
    structureErasures: [],
  };
  const sources: PlanStepSources = {
    draws: [],
    moves: [],
    erasures: [],
    newPlaces: [],
    structureBuilds: [],
    structureMoves: [],
    structureErasures: [],
  };
  for (const [drawingId, version] of Object.entries(draft.erasedDrawings)) {
    step.erasures.push({ drawingId, version });
    sources.erasures.push(drawingId);
  }
  for (const [drawingId, held] of Object.entries(draft.drawings)) {
    const row = saved.find((one) => one.id === drawingId);
    const relabelled = row !== undefined && row.locationId !== held.locationId;
    step.moves.push({
      drawingId,
      ...textOf(held.rect),
      version: held.version,
      ...(relabelled ? { locationId: held.locationId } : {}),
    });
    sources.moves.push(drawingId);
  }
  for (const one of draft.newDrawings) {
    if ('locationId' in one.place) {
      step.draws.push({ locationId: one.place.locationId, ...textOf(one.rect) });
      sources.draws.push(one.key);
    } else {
      step.newPlaces.push({
        kind: one.place.kind,
        code: one.place.code.trim(),
        name: one.place.name.trim(),
        ...textOf(one.rect),
      });
      sources.newPlaces.push(one.key);
    }
  }
  for (const [structureId, version] of Object.entries(draft.erasedStructures)) {
    step.structureErasures.push({ structureId, version });
    sources.structureErasures.push(structureId);
  }
  for (const [structureId, held] of Object.entries(draft.structures)) {
    step.structureMoves.push({
      structureId,
      kind: held.kind,
      name: held.name,
      ...textOf(held.rect),
      version: held.version,
    });
    sources.structureMoves.push(structureId);
  }
  for (const one of draft.newStructures) {
    step.structureBuilds.push({ kind: one.kind, name: one.name, ...textOf(one.rect) });
    sources.structureBuilds.push(one.key);
  }
  if (draft.floor !== null) {
    step.floor = {
      widthMetres: draft.floor.width.toFixed(3),
      depthMetres: draft.floor.depth.toFixed(3),
      version: draft.floor.version,
    };
  }

  return { step, sources };
}

/** The shape a refusal names (`moves[2].x` → that rectangle's id or key), or null when it names none of them. */
export function sourceOf(sources: PlanStepSources, refusal: string): string | null {
  const found = /^([A-Za-z]+)\[(\d+)\]/.exec(refusal.trim());
  if (found === null) return null;
  const list = sources[found[1] as keyof PlanStepSources] as string[] | undefined;

  return list?.[Number(found[2])] ?? null;
}

/** What a saved step created, in the order its lists were sent, as the API answers it. */
export interface PlanStepCreated {
  draws: readonly string[];
  newPlaces: readonly { drawingId: string; locationId: string }[];
  structureBuilds: readonly string[];
}

/**
 * The step that takes a saved one back, built from what the floor was before (`savedDrawings`, `savedStructures`) and
 * the versions the step left (`after…`). Places created by the step are listed apart: the inverse undraws their
 * rectangles, and the screen then deletes the places themselves, which held nothing yet.
 */
export function inverseOf(
  step: PlanStep,
  created: PlanStepCreated,
  savedDrawings: readonly StockDrawingRow[],
  savedStructures: readonly StockStructureRow[],
  afterDrawings: readonly StockDrawingRow[],
  afterStructures: readonly StockStructureRow[],
  floor: { before: SavedFloor; version: number } | null = null,
): { step: PlanStep; createdPlaces: string[] } {
  const versionOf = (rows: readonly { id: string; version: number }[], id: string): number =>
    rows.find((one) => one.id === id)?.version ?? 0;
  const before = (id: string): StockDrawingRow | undefined =>
    savedDrawings.find((one) => one.id === id);
  const pieceBefore = (id: string): StockStructureRow | undefined =>
    savedStructures.find((one) => one.id === id);

  const inverse: PlanStep = {
    draws: step.erasures.flatMap(({ drawingId }) => {
      const row = before(drawingId);

      return row === undefined ? [] : [{ locationId: row.locationId, ...textOf(rectOf(row)) }];
    }),
    moves: step.moves.flatMap(({ drawingId, locationId }) => {
      const row = before(drawingId);

      return row === undefined
        ? []
        : [
            {
              drawingId,
              ...textOf(rectOf(row)),
              version: versionOf(afterDrawings, drawingId),
              ...(locationId !== undefined ? { locationId: row.locationId } : {}),
            },
          ];
    }),
    erasures: [...created.draws, ...created.newPlaces.map((one) => one.drawingId)].map(
      (drawingId) => ({
        drawingId,
        version: versionOf(afterDrawings, drawingId),
      }),
    ),
    newPlaces: [],
    structureBuilds: step.structureErasures.flatMap(({ structureId }) => {
      const row = pieceBefore(structureId);

      return row === undefined ? [] : [{ kind: row.kind, name: row.name, ...textOf(rectOf(row)) }];
    }),
    structureMoves: step.structureMoves.flatMap(({ structureId }) => {
      const row = pieceBefore(structureId);

      return row === undefined
        ? []
        : [
            {
              structureId,
              kind: row.kind,
              name: row.name,
              ...textOf(rectOf(row)),
              version: versionOf(afterStructures, structureId),
            },
          ];
    }),
    structureErasures: created.structureBuilds.map((structureId) => ({
      structureId,
      version: versionOf(afterStructures, structureId),
    })),
  };

  // A floor never measured before has no size to go back to: its new size stays.
  if (
    step.floor !== undefined &&
    floor !== null &&
    floor.before.widthMetres !== null &&
    floor.before.depthMetres !== null
  ) {
    inverse.floor = {
      widthMetres: Number(floor.before.widthMetres).toFixed(3),
      depthMetres: Number(floor.before.depthMetres).toFixed(3),
      version: floor.version,
    };
  }

  return { step: inverse, createdPlaces: created.newPlaces.map((one) => one.locationId) };
}

/**
 * The draft against the floor as it is now: what someone else saved or removed since is no longer the draft's to
 * change, so those shapes are let go and named; the rest is kept. A new rectangle drawn for a place that someone has
 * since drawn elsewhere, or deleted, is let go too.
 */
export function rebased(
  draft: PlanDraft,
  savedDrawings: readonly StockDrawingRow[],
  savedStructures: readonly StockStructureRow[],
  locations: readonly StockLocationRow[],
  savedFloor: SavedFloor | null = null,
): { draft: PlanDraft; dropped: number } {
  let dropped = 0;
  const current = <T extends { id: string; version: number }>(
    rows: readonly T[],
    id: string,
    version: number,
  ): boolean => {
    const ok = rows.some((one) => one.id === id && one.version === version);
    if (!ok) dropped += 1;

    return ok;
  };
  const drawnPlaces = new Set(savedDrawings.map((one) => one.locationId));
  const known = new Set(locations.map((one) => one.id));
  const keep = <V>(
    entries: Readonly<Record<string, V>>,
    test: (id: string, value: V) => boolean,
  ): Record<string, V> =>
    Object.fromEntries(Object.entries(entries).filter(([id, value]) => test(id, value)));

  const next: PlanDraft = {
    ...draft,
    drawings: keep(draft.drawings, (id, held) => current(savedDrawings, id, held.version)),
    erasedDrawings: keep(draft.erasedDrawings, (id, version) =>
      current(savedDrawings, id, version),
    ),
    structures: keep(draft.structures, (id, held) => current(savedStructures, id, held.version)),
    erasedStructures: keep(draft.erasedStructures, (id, version) =>
      current(savedStructures, id, version),
    ),
    newDrawings: draft.newDrawings.filter((one) => {
      if (!('locationId' in one.place)) return true;
      const ok = known.has(one.place.locationId) && !drawnPlaces.has(one.place.locationId);
      if (!ok) dropped += 1;

      return ok;
    }),
    floor:
      draft.floor !== null && savedFloor !== null && savedFloor.version !== draft.floor.version
        ? null
        : draft.floor,
  };
  if (next.floor === null && draft.floor !== null) dropped += 1;

  return { draft: next, dropped };
}

/** Read back from the browser: anything that is not a draft of this floor is no draft at all. */
export function parseDraft(text: string | null, floorId: string): PlanDraft | null {
  if (text === null) return null;
  try {
    const value: unknown = JSON.parse(text);
    if (!isRecord(value) || value['floorId'] !== floorId) return null;
    const draft = { ...emptyDraft(floorId), ...value } as PlanDraft;
    const wellFormed =
      isRecord(draft.drawings) &&
      isRecord(draft.erasedDrawings) &&
      Array.isArray(draft.newDrawings) &&
      isRecord(draft.structures) &&
      isRecord(draft.erasedStructures) &&
      Array.isArray(draft.newStructures) &&
      typeof draft.next === 'number' &&
      (draft.floor === null ||
        (isRecord(draft.floor) &&
          ['width', 'depth', 'version'].every((side) =>
            Number.isFinite((draft.floor as unknown as Record<string, unknown>)[side]),
          ))) &&
      Object.values(draft.drawings).every((held) => isRecord(held) && isRect(held['rect'])) &&
      Object.values(draft.structures).every((held) => isRecord(held) && isRect(held['rect'])) &&
      draft.newDrawings.every(
        (one) => isRecord(one) && isRect(one['rect']) && isRecord(one['place']),
      ) &&
      draft.newStructures.every((one) => isRecord(one) && isRect(one['rect']));

    return wellFormed ? draft : null;
  } catch {
    // Not JSON: something else wrote the key, or a write was cut short. Either way there is no draft to offer.
    return null;
  }
}

/** A record less one entry, the rest as they were. */
function without<T>(record: Readonly<Record<string, T>>, id: string): Record<string, T> {
  return Object.fromEntries(Object.entries(record).filter(([key]) => key !== id));
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isRect(value: unknown): value is PlanRectangle {
  return (
    isRecord(value) &&
    ['x', 'y', 'width', 'depth', 'rotation', 'height'].every(
      (side) => typeof value[side] === 'number' && Number.isFinite(value[side]),
    )
  );
}

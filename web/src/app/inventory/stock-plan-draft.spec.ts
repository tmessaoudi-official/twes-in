// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import type { StockDrawingRow, StockLocationRow, StockStructureRow } from './inventory-types';
import {
  addDrawing,
  addStructure,
  changeCount,
  emptyDraft,
  erase,
  inverseOf,
  parseDraft,
  placeDrawing,
  placeFor,
  placeStructure,
  rebased,
  shownDrawings,
  shownFloorSize,
  shownStructures,
  sizeFloor,
  sourceOf,
  stepOf,
} from './stock-plan-draft';

const rack = (id: string, code: string, x: number, version = 1): StockDrawingRow => ({
  id,
  floorId: 'f1',
  locationId: `loc-${id}`,
  locationCode: code,
  locationName: `Rayon ${code}`,
  locationKind: 'rack',
  x: x.toFixed(3),
  y: '2.000',
  width: '4.000',
  depth: '1.500',
  rotation: 0,
  height: '2.000',
  version,
});

const wall: StockStructureRow = {
  id: 'w1',
  floorId: 'f1',
  kind: 'wall',
  name: '',
  x: '3.000',
  y: '8.000',
  width: '8.000',
  depth: '0.250',
  rotation: 0,
  height: '3.000',
  version: 4,
};

const place = (id: string, code: string): StockLocationRow => ({
  id,
  establishmentId: 'e1',
  parentId: null,
  kind: 'rack',
  code,
  name: `Rayon ${code}`,
  isDefault: false,
  childCount: 0,
  movementCount: 0,
  version: 1,
});

const A = rack('a', 'R1', 3);
const B = rack('b', 'R2', 10, 3);
const at = (x: number, y = 2) => ({ x, y, width: 4, depth: 1.5, rotation: 0, height: 2 });

describe('the plan draft', () => {
  it('moves the shape it is told, and never another one', () => {
    let draft = placeDrawing(emptyDraft('f1'), 'a', at(5), A);
    draft = placeDrawing(draft, 'b', at(10, 6), B);

    const shown = shownDrawings(draft, [A, B], []);

    expect(shown.find((one) => one.id === 'a')).toMatchObject({ x: '5.000', y: '2.000' });
    expect(shown.find((one) => one.id === 'b')).toMatchObject({ x: '10.000', y: '6.000' });
    expect(changeCount(draft)).toBe(2);
  });

  it('keeps every shape changed while another one is worked on', () => {
    let draft = placeStructure(emptyDraft('f1'), 'w1', { ...at(4, 9), depth: 0.25 }, wall);
    draft = placeDrawing(draft, 'a', at(1), A);

    expect(shownStructures(draft, [wall])[0]).toMatchObject({ x: '4.000', y: '9.000' });
    expect(shownDrawings(draft, [A], [])[0]).toMatchObject({ x: '1.000' });
    expect(changeCount(draft)).toBe(2);
  });

  it('forgets a shape put back exactly where it was saved', () => {
    let draft = placeDrawing(emptyDraft('f1'), 'a', at(5), A);
    draft = placeDrawing(draft, 'a', at(3), A);

    expect(changeCount(draft)).toBe(0);
  });

  it('keeps the version read when the shape was first changed, not a later one', () => {
    let draft = placeDrawing(emptyDraft('f1'), 'b', at(11), B);
    draft = placeDrawing(draft, 'b', at(12), { ...B, version: 9 });

    expect(stepOf(draft, [B]).step.moves).toEqual([
      expect.objectContaining({ drawingId: 'b', x: '12.000', version: 3 }),
    ]);
  });

  it('draws new shapes under keys of their own, and forgets one erased before it was saved', () => {
    const first = addDrawing(emptyDraft('f1'), at(1, 1), {
      kind: 'rack',
      code: 'R9',
      name: 'Neuf',
    });
    const second = addStructure(
      first.draft,
      { ...at(0, 0), depth: 0.2 },
      { kind: 'door', name: '' },
    );

    expect(first.key).not.toBe(second.key);
    expect(shownDrawings(second.draft, [], []).map((one) => one.locationCode)).toEqual(['R9']);
    expect(shownStructures(second.draft, []).map((one) => one.kind)).toEqual(['door']);

    const gone = erase(second.draft, [first.key], [], []);
    expect(shownDrawings(gone, [], [])).toEqual([]);
    expect(changeCount(gone)).toBe(1);
  });

  it('erases saved shapes on the version read, the place itself untouched', () => {
    let draft = placeDrawing(emptyDraft('f1'), 'b', at(13), B);
    draft = erase(draft, ['b', 'w1'], [A, B], [wall]);

    expect(shownDrawings(draft, [A, B], []).map((one) => one.id)).toEqual(['a']);
    expect(shownStructures(draft, [wall])).toEqual([]);
    const { step } = stepOf(draft, [A, B]);
    expect(step.erasures).toEqual([{ drawingId: 'b', version: 3 }]);
    expect(step.moves).toEqual([]);
    expect(step.structureErasures).toEqual([{ structureId: 'w1', version: 4 }]);
    // An erased shape takes no more gestures: its rectangle is gone from the board.
    expect(placeDrawing(draft, 'b', at(1), B)).toBe(draft);
  });

  it('sends a move its place only when the place changed', () => {
    const draft = placeDrawing(emptyDraft('f1'), 'a', at(3), A, 'loc-other');
    const shown = shownDrawings(draft, [A], [place('loc-other', 'Z1')]);

    expect(shown[0]).toMatchObject({ locationId: 'loc-other', locationCode: 'Z1' });
    expect(stepOf(draft, [A]).step.moves).toEqual([
      expect.objectContaining({ drawingId: 'a', locationId: 'loc-other' }),
    ]);
    expect(
      stepOf(placeDrawing(emptyDraft('f1'), 'a', at(4), A), [A]).step.moves[0],
    ).not.toHaveProperty('locationId');
  });

  it('lays the draft out as one step, and finds the shape a refusal names', () => {
    let draft = placeStructure(emptyDraft('f1'), 'w1', { ...at(3, 8), depth: 0.25 }, wall, {
      kind: 'wall',
      name: 'Fond',
    });
    const drawn = addDrawing(draft, at(1, 1), { locationId: 'loc-free' });
    const made = addDrawing(drawn.draft, at(6, 6), { kind: 'zone', code: ' Z2 ', name: 'Quai' });
    draft = placeFor(made.draft, made.key, { kind: 'zone', code: ' Z3 ', name: 'Quai' });

    const { step, sources } = stepOf(draft, [A]);

    expect(step.draws).toEqual([expect.objectContaining({ locationId: 'loc-free', x: '1.000' })]);
    expect(step.newPlaces).toEqual([
      expect.objectContaining({ kind: 'zone', code: 'Z3', name: 'Quai' }),
    ]);
    expect(step.structureMoves).toEqual([
      expect.objectContaining({ structureId: 'w1', name: 'Fond', version: 4, depth: '0.250' }),
    ]);
    expect(sourceOf(sources, 'newPlaces[0].code: This code is taken.')).toBe(made.key);
    expect(sourceOf(sources, 'draws[0].locationId: …')).toBe(drawn.key);
    expect(sourceOf(sources, 'structureMoves[0].x: …')).toBe('w1');
    expect(sourceOf(sources, 'Something else.')).toBeNull();
  });

  it('builds the step that puts a saved one back', () => {
    let draft = placeDrawing(emptyDraft('f1'), 'a', at(5), A);
    draft = erase(draft, ['b'], [A, B], []);
    draft = placeStructure(draft, 'w1', { ...at(4, 8), depth: 0.25 }, wall);
    draft = addDrawing(draft, at(1, 1), { kind: 'rack', code: 'R9', name: 'Neuf' }).draft;
    draft = addStructure(draft, at(0, 0), { kind: 'post', name: '' }).draft;
    const { step } = stepOf(draft, [A, B]);

    const back = inverseOf(
      step,
      { draws: [], newPlaces: [{ drawingId: 'd9', locationId: 'l9' }], structureBuilds: ['s9'] },
      [A, B],
      [wall],
      [
        { ...A, x: '5.000', version: 2 },
        { ...rack('d9', 'R9', 1), version: 1 },
      ],
      [
        { ...wall, version: 5 },
        { ...wall, id: 's9', kind: 'post', version: 1 },
      ],
    );

    expect(back.step.moves).toEqual([
      expect.objectContaining({ drawingId: 'a', x: '3.000', version: 2 }),
    ]);
    expect(back.step.draws).toEqual([
      expect.objectContaining({ locationId: 'loc-b', x: '10.000' }),
    ]);
    expect(back.step.erasures).toEqual([{ drawingId: 'd9', version: 1 }]);
    expect(back.step.structureMoves).toEqual([
      expect.objectContaining({ structureId: 'w1', x: '3.000', version: 5 }),
    ]);
    expect(back.step.structureErasures).toEqual([{ structureId: 's9', version: 1 }]);
    expect(back.createdPlaces).toEqual(['l9']);
  });

  it('lets go what someone else saved or removed since, and keeps the rest', () => {
    let draft = placeDrawing(emptyDraft('f1'), 'a', at(5), A);
    draft = placeDrawing(draft, 'b', at(12), B);
    draft = placeStructure(draft, 'w1', { ...at(4, 8), depth: 0.25 }, wall);
    draft = addDrawing(draft, at(1, 1), { locationId: 'loc-gone' }).draft;
    draft = addDrawing(draft, at(2, 2), { locationId: 'loc-free' }).draft;

    const { draft: kept, dropped } = rebased(
      draft,
      [A, { ...B, version: 4 }],
      [],
      [place('loc-free', 'R7')],
    );

    expect(dropped).toBe(3);
    expect(Object.keys(kept.drawings)).toEqual(['a']);
    expect(kept.structures).toEqual({});
    expect(kept.newDrawings.map((one) => one.place)).toEqual([{ locationId: 'loc-free' }]);
  });

  it('widens and lengthens the floor in the same step, on the version read, and forgets a size put back', () => {
    const floor = { widthMetres: '20.000', depthMetres: '12.000', version: 6 };
    let draft = sizeFloor(emptyDraft('f1'), { width: 24, depth: 15.5 }, floor);

    expect(changeCount(draft)).toBe(1);
    expect(shownFloorSize(draft, floor)).toEqual({ width: 24, depth: 15.5 });
    expect(stepOf(draft, []).step.floor).toEqual({
      widthMetres: '24.000',
      depthMetres: '15.500',
      version: 6,
    });
    // A later read of the floor does not move the version the size stands on.
    draft = sizeFloor(draft, { width: 25, depth: 15.5 }, { ...floor, version: 7 });
    expect(stepOf(draft, []).step.floor?.version).toBe(6);

    const back = inverseOf(
      stepOf(draft, []).step,
      { draws: [], newPlaces: [], structureBuilds: [] },
      [],
      [],
      [],
      [],
      {
        before: floor,
        version: 8,
      },
    );
    expect(back.step.floor).toEqual({ widthMetres: '20.000', depthMetres: '12.000', version: 8 });

    expect(changeCount(sizeFloor(draft, { width: 20, depth: 12 }, floor))).toBe(0);
    expect(stepOf(emptyDraft('f1'), []).step).not.toHaveProperty('floor');
    expect(rebased(draft, [], [], [], { ...floor, version: 7 }).dropped).toBe(1);
    expect(parseDraft(JSON.stringify(draft), 'f1')).toEqual(draft);
  });

  /** A gesture already lands on the grid; a group turned about its centre lands off it, and must keep its spacing. */
  it('sends each rectangle where the draft holds it, never rounded one by one', () => {
    let draft = placeDrawing(emptyDraft('f1'), 'a', { ...at(2.844), y: 3.288, rotation: 15 }, A);
    draft = placeDrawing(draft, 'b', { ...at(8.156), y: 4.712, rotation: 15 }, B);

    expect(stepOf(draft, [A, B]).step.moves).toEqual([
      expect.objectContaining({ drawingId: 'a', x: '2.844', y: '3.288', rotation: 15 }),
      expect.objectContaining({ drawingId: 'b', x: '8.156', y: '4.712', rotation: 15 }),
    ]);
  });

  it('reads back only a well-formed draft of the same floor', () => {
    const draft = placeDrawing(emptyDraft('f1'), 'a', at(5), A);
    const text = JSON.stringify(draft);

    expect(parseDraft(text, 'f1')).toEqual(draft);
    expect(parseDraft(text, 'f2')).toBeNull();
    expect(parseDraft('not json', 'f1')).toBeNull();
    expect(parseDraft(null, 'f1')).toBeNull();
    expect(
      parseDraft(JSON.stringify({ ...draft, drawings: { a: { rect: { x: 'one' } } } }), 'f1'),
    ).toBeNull();
  });
});

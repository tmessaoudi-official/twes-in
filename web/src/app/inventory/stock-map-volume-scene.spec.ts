// SPDX-License-Identifier: AGPL-3.0-or-later

import type { StockDrawingRow, StockStructureRow } from './inventory-types';
import {
  FLAT_METRES,
  CAMERA_FOV,
  DIMMED_TONES,
  LIT_TONES,
  PART_TONES,
  cameraPreset,
  moved,
  tonesOf,
  turned,
  volumeCounts,
  volumeBoxes,
  volumeExtent,
  type VolumeToggles,
} from './stock-map-volume-scene';

const ALL: VolumeToggles = { building: true, ground: true, heights: true };

const distance = (view: { position: number[]; target: number[] }): number =>
  Math.hypot(...view.position.map((value, index) => value - (view.target[index] ?? 0)));

function drawing(overrides: Partial<StockDrawingRow> = {}): StockDrawingRow {
  return {
    id: 'd1',
    floorId: 'f1',
    locationId: 'l1',
    locationCode: 'R1',
    locationName: 'Rayonnage 1',
    locationKind: 'rack',
    x: '2.000',
    y: '4.000',
    width: '6.000',
    depth: '1.000',
    rotation: 0,
    height: '2.400',
    ...overrides,
  };
}

function structure(overrides: Partial<StockStructureRow> = {}): StockStructureRow {
  return {
    id: 's1',
    floorId: 'f1',
    kind: 'wall',
    name: '',
    x: '0.000',
    y: '0.000',
    width: '10.000',
    depth: '0.200',
    rotation: 0,
    height: '3.000',
    ...overrides,
  };
}

describe('the stock map in volume', () => {
  it('stands a rack on its footprint, centred where the plan draws it and as tall as it was drawn', () => {
    const [rack] = volumeBoxes({
      size: null,
      drawings: [drawing()],
      structures: [],
      lit: new Set(),
      toggles: ALL,
    });

    expect(rack).toEqual(
      expect.objectContaining({
        key: 'drawing-d1',
        part: 'rack',
        locationId: 'l1',
        x: 5,
        y: 4.5,
        width: 6,
        depth: 1,
        height: 2.4,
        lit: false,
        dimmed: false,
      }),
    );
  });

  /** The plan turns clockwise as it is seen from above; about the upward axis that is the negative way round. */
  it('turns a rectangle the way the plan turns it', () => {
    const [rack] = volumeBoxes({
      size: null,
      drawings: [drawing({ rotation: 90 })],
      structures: [],
      lit: new Set(),
      toggles: ALL,
    });

    expect(rack?.turn).toBeCloseTo(-Math.PI / 2);
  });

  it('lays a bay marked out on the floor, and every rack once heights are off, flat on the ground', () => {
    const bay = drawing({ id: 'd2', locationKind: 'zone', height: '0.000' });
    const [marked] = volumeBoxes({
      size: null,
      drawings: [bay],
      structures: [],
      lit: new Set(),
      toggles: ALL,
    });
    const [flat] = volumeBoxes({
      size: null,
      drawings: [drawing()],
      structures: [],
      lit: new Set(),
      toggles: { ...ALL, heights: false },
    });

    expect(marked?.height).toBe(FLAT_METRES);
    expect(marked?.part).toBe('zone');
    expect(flat?.height).toBe(FLAT_METRES);
  });

  it('lights what is looked for and dims everything else, so the light is never colour alone', () => {
    const boxes = volumeBoxes({
      size: null,
      drawings: [drawing(), drawing({ id: 'd2', locationId: 'l2', locationCode: 'R2' })],
      structures: [],
      lit: new Set(['l2']),
      toggles: ALL,
    });

    expect(boxes.map((box) => [box.locationId, box.lit, box.dimmed])).toEqual([
      ['l1', false, true],
      ['l2', true, false],
    ]);
  });

  it('builds the walls and posts to their height, marks doors and docks on the floor, and leaves them all out on asking', () => {
    const pieces = [
      structure(),
      structure({ id: 's2', kind: 'door', height: '2.100' }),
      structure({ id: 's3', kind: 'post', width: '0.300', depth: '0.300' }),
      structure({ id: 's4', kind: 'dock', height: '1.200' }),
    ];
    const built = volumeBoxes({
      size: null,
      drawings: [],
      structures: pieces,
      lit: new Set(),
      toggles: ALL,
    });
    const without = volumeBoxes({
      size: null,
      drawings: [],
      structures: pieces,
      lit: new Set(),
      toggles: { ...ALL, building: false },
    });

    expect(built.map((box) => [box.part, box.height])).toEqual([
      ['wall', 3],
      ['door', FLAT_METRES],
      ['post', 3],
      ['dock', FLAT_METRES],
    ]);
    expect(without).toEqual([]);
  });

  it('lays the floor it was measured at under everything, and none once the ground is off', () => {
    const size = { width: 20, depth: 10 };
    const boxes = volumeBoxes({ size, drawings: [], structures: [], lit: new Set(), toggles: ALL });

    expect(boxes).toEqual([
      expect.objectContaining({
        part: 'ground',
        x: 10,
        y: 5,
        width: 20,
        depth: 10,
        base: -FLAT_METRES,
      }),
    ]);
    expect(
      volumeBoxes({
        size,
        drawings: [],
        structures: [],
        lit: new Set(),
        toggles: { ...ALL, ground: false },
      }),
    ).toEqual([]);
  });

  it('frames a floor never measured by what is drawn on it, turned shapes included', () => {
    const extent = volumeExtent(
      null,
      [drawing({ x: '0.000', y: '0.000', width: '4.000', depth: '2.000', rotation: 90 })],
      [],
    );

    // Turned a quarter about its centre (2, 1), a 4 × 2 rectangle covers x 1..3 and y -1..3.
    expect(extent.x).toBeCloseTo(1);
    expect(extent.y).toBeCloseTo(-1);
    expect(extent.width).toBeCloseTo(2);
    expect(extent.depth).toBeCloseTo(4);
  });

  it('looks at the middle of the floor from above and from a raised corner, and turns about it', () => {
    const extent = { x: 0, y: 0, width: 20, depth: 10 };
    const overview = cameraPreset('overview', extent, 1.5, 3);
    const top = cameraPreset('top', extent, 1.5, 3);

    expect(overview.target).toEqual([10, 1.5, 5]);
    expect(top.target).toEqual([10, 1.5, 5]);
    expect(top.position[0]).toBeCloseTo(10);
    expect(top.position[1]).toBeGreaterThan(overview.position[1]);
    // From the south-west and above, never from under the ground.
    expect(overview.position[0]).toBeLessThan(10);
    expect(overview.position[1]).toBeGreaterThan(0);
    expect(overview.position[2]).toBeGreaterThan(5);

    const quarter = turned(overview, Math.PI / 2);
    expect(distance(quarter)).toBeCloseTo(distance(overview));
    expect(quarter.position[1]).toBeCloseTo(overview.position[1]);
    expect(quarter.target).toEqual(overview.target);
  });

  /**
   * The whole floor fits the picture: a sphere round it, seen from the camera, stays inside the narrower of the two
   * angles. A phone held upright is narrower than it is tall, so it stands further back than a wide window does.
   */
  it('stands back far enough for the whole floor to fit, further on a picture narrower than it is tall', () => {
    const extent = { x: 0, y: 0, width: 20, depth: 10 };
    const radius = Math.hypot(20, 10, 3) / 2;
    const wide = cameraPreset('overview', extent, 1.5, 3);
    const upright = cameraPreset('overview', extent, 0.5, 3);
    const half = (CAMERA_FOV * Math.PI) / 360;

    expect(radius / distance(wide)).toBeLessThanOrEqual(Math.sin(half));
    expect(radius / distance(upright)).toBeLessThanOrEqual(
      Math.sin(Math.atan(Math.tan(half) * 0.5)),
    );
    expect(distance(upright)).toBeGreaterThan(distance(wide));
  });

  it('paints each part in the plan legend’s tones, what is looked for in the search’s, and the rest quietly', () => {
    expect(tonesOf({ part: 'rack', lit: false, dimmed: false })).toBe(PART_TONES.rack);
    expect(tonesOf({ part: 'rack', lit: true, dimmed: false })).toBe(LIT_TONES);
    expect(tonesOf({ part: 'rack', lit: false, dimmed: true })).toBe(DIMMED_TONES);
    expect(PART_TONES.rack.fill).toBe('--mat-sys-primary-container');
    expect(LIT_TONES.opacity).toBe(1);
    expect(DIMMED_TONES.opacity).toBeLessThan(1);
    // The building is seen through, or it would hide the floor it closes.
    expect(PART_TONES.wall.opacity).toBeLessThan(1);
  });

  it('counts the racks, the zones and what is lit, for the words that say what the view shows', () => {
    const boxes = volumeBoxes({
      size: { width: 10, depth: 10 },
      drawings: [
        drawing(),
        drawing({ id: 'd2', locationId: 'l2', locationKind: 'zone' }),
        drawing({ id: 'd3', locationId: 'l3', locationKind: 'quarantine' }),
      ],
      structures: [structure()],
      lit: new Set(['l1']),
      toggles: ALL,
    });

    expect(volumeCounts(boxes)).toEqual({ racks: 1, zones: 2, lit: 1 });
  });

  it('brings a view nearer its target, or further, along the line it looks', () => {
    const view = cameraPreset('overview', { x: 0, y: 0, width: 20, depth: 10 });
    const nearer = moved(view, 0.5);

    expect(nearer.target).toEqual(view.target);
    expect(nearer.position[1]).toBeCloseTo(view.position[1] / 2);
  });
});

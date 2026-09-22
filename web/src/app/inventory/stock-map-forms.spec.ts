// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  drawingForm,
  drawingInput,
  drawingValues,
  floorForm,
  floorInput,
  floorValues,
  nextCodes,
  planRectangles,
  rectValues,
  structureForm,
  structureInput,
  structureRectangles,
  structureValues,
} from './stock-map-forms';
import type {
  StockDrawingRow,
  StockFloorRow,
  StockLocationRow,
  StockOptions,
  StockStructureRow,
  StockStructureShape,
} from './inventory-types';

const OPTIONS: StockOptions = {
  establishments: [
    { id: 'e1', code: '000', name: 'Bab Saadoun' },
    { id: 'e2', code: '001', name: 'La Marsa' },
  ],
  planShapes: [],
  structureShapes: [],
};

const floor: StockFloorRow = {
  id: 'f1',
  establishmentId: 'e1',
  name: 'Rez-de-chaussée',
  level: 0,
  imageFileId: null,
  imageMetresWide: null,
  imageOpacity: 35,
  drawingCount: 2,
};

const location = (over: Partial<StockLocationRow> = {}): StockLocationRow => ({
  id: 'l1',
  establishmentId: 'e1',
  parentId: null,
  kind: 'rack',
  code: 'R1',
  name: 'Rayonnage 1',
  isDefault: false,
  childCount: 0,
  movementCount: 0,
  ...over,
});

const drawing = (over: Partial<StockDrawingRow> = {}): StockDrawingRow => ({
  id: 'd1',
  floorId: 'f1',
  locationId: 'l1',
  locationCode: 'R1',
  locationName: 'Rayonnage 1',
  locationKind: 'rack',
  x: '2.500',
  y: '4.000',
  width: '3.900',
  depth: '0.600',
  rotation: 0,
  height: '2.100',
  ...over,
});

describe('floorForm', () => {
  it('offers the establishments, and fixes the one a floor already belongs to', () => {
    const establishment = fieldOf(floorForm(OPTIONS, null), 'establishmentId');
    expect(establishment.readOnly).toBeFalsy();
    expect(establishment.options).toEqual([
      { value: 'e1', label: '000 — Bab Saadoun' },
      { value: 'e2', label: '001 — La Marsa' },
    ]);

    // A floor never moves to another establishment, so the field is shown and not offered.
    expect(fieldOf(floorForm(OPTIONS, floor), 'establishmentId').readOnly).toBe(true);
  });

  it('proposes the only establishment there is, so a one-place company types nothing', () => {
    expect(
      floorValues(null, {
        establishments: [OPTIONS.establishments[0]!],
        planShapes: [],
        structureShapes: [],
      })['establishmentId'],
    ).toBe('e1');
    expect(floorValues(null, OPTIONS)['establishmentId']).toBe('');
  });

  it('reads a floor back into its own form', () => {
    expect(floorValues(floor, OPTIONS)).toEqual({
      establishmentId: 'e1',
      name: 'Rez-de-chaussée',
      level: 0,
    });
  });

  it('keeps the plan image a floor already has, which this form does not carry', () => {
    expect(floorInput({ establishmentId: 'e1', name: 'Étage 1', level: '1' }, floor)).toEqual({
      establishmentId: 'e1',
      name: 'Étage 1',
      level: 1,
      imageFileId: null,
      imageMetresWide: null,
      imageOpacity: 35,
    });
  });
});

describe('drawingForm', () => {
  /** Decision 2: a bin is placed in its rack's front view and has no x, y on the ground. */
  it('offers no bin to draw, and leaves out what is already drawn elsewhere', () => {
    const locations = [
      location({ id: 'l1', code: 'R1' }),
      location({ id: 'l2', code: 'Z1', kind: 'zone' }),
      location({ id: 'l3', code: 'R1-A1', kind: 'bin' }),
      location({ id: 'l4', code: 'R2' }),
    ];
    const offered = fieldOf(
      drawingForm(locations, [drawing({ locationId: 'l4' })], null),
      'locationId',
    ).options;

    expect(offered?.map((option) => option.value)).toEqual(['l1', 'l2']);
  });

  /** A rectangle being edited still offers the location it is drawn for, or the form could not be saved back. */
  it('keeps the location the rectangle being edited is drawn for', () => {
    const locations = [location({ id: 'l1' }), location({ id: 'l4', code: 'R2' })];
    const drawn = drawing({ locationId: 'l4' });
    const offered = fieldOf(drawingForm(locations, [drawn], drawn), 'locationId').options;

    expect(offered?.map((option) => option.value)).toEqual(['l1', 'l4']);
  });

  it('measures in metres and bounds the turn to one circle', () => {
    const form = drawingForm([location()], [], null);
    // Metres are typed the locale's way and sent the API's way, which is what `decimal` is for.
    for (const id of ['x', 'y', 'width', 'depth', 'height']) {
      expect(fieldOf(form, id).kind).toBe('decimal');
    }
    expect(fieldOf(form, 'rotation').kind).toBe('number');
    expect(fieldOf(form, 'rotation').min).toBe(0);
    expect(fieldOf(form, 'rotation').max).toBe(345);
  });

  it('reads a rectangle back into its own form', () => {
    expect(drawingValues(drawing())).toEqual({
      locationId: 'l1',
      x: '2.500',
      y: '4.000',
      width: '3.900',
      depth: '0.600',
      rotation: 0,
      height: '2.100',
    });
  });

  /** A new rectangle starts as something a person can see and then drag, not as a rectangle of no size. */
  it('starts a new rectangle with a workable size at the origin', () => {
    const started = drawingValues(null);
    expect(started['x']).toBe('0.000');
    expect(Number(started['width'])).toBeGreaterThan(0);
    expect(Number(started['depth'])).toBeGreaterThan(0);
  });

  /**
   * The grid holds a rectangle's PLACE, never its SIZE, as the approved canvas draws it: a rack of 3,90 × 0,60 m
   * sitting at x 2,50. Rounding a real 3,90 m rack up to 4,00 would be losing a measurement somebody took.
   */
  it('takes the place to the grid and leaves the measurements as they were typed', () => {
    expect(
      drawingInput({
        locationId: 'l1',
        x: '2.6',
        y: '4.1',
        width: '3.9',
        depth: '0.6',
        rotation: '97',
        height: '2.1',
      }),
    ).toEqual({
      locationId: 'l1',
      // Where it stands: on the quarter.
      x: '2.500',
      y: '4.000',
      // How big it is: exactly what was measured.
      width: '3.900',
      depth: '0.600',
      height: '2.100',
      // How it is turned: in fifteens, because a rack stands square to a wall or at an angle off it.
      rotation: 90,
    });
  });

  it('keeps a size that is already on the grid, so the rule costs a round rack nothing', () => {
    const input = drawingInput({
      locationId: 'l1',
      x: '0',
      y: '0',
      width: '2.5',
      depth: '1',
      rotation: '0',
      height: '2',
    });
    expect([input.width, input.depth, input.height]).toEqual(['2.500', '1.000', '2.000']);
  });
});

describe('planRectangles', () => {
  it('turns the API’s decimal strings into the numbers the drawing is made of', () => {
    expect(planRectangles([drawing({ rotation: 90 })])).toEqual([
      { x: 2.5, y: 4, width: 3.9, depth: 0.6, rotation: 90, height: 2.1 },
    ]);
  });
});

function fieldOf(form: ReturnType<typeof floorForm>, id: string) {
  const field = form.sections.flatMap((section) => section.fields).find((one) => one.id === id);
  if (field === undefined) throw new Error(`The form has no ${id} field.`);
  return field;
}

describe('rectValues', () => {
  /**
   * What a GESTURE writes into the form. It must be shaped exactly like what the API answered, or the unsaved-change
   * count reads `1300` against `1300.000` as a change and says a field moved that nobody touched.
   */
  it('writes the rectangle the way the API writes it, so an untouched field reads as untouched', () => {
    const { locationId, ...geometry } = drawingValues(drawing({ rotation: 90 }));

    expect(locationId).toBe('l1');
    expect(rectValues({ x: 2.5, y: 4, width: 3.9, depth: 0.6, rotation: 90, height: 2.1 })).toEqual(
      geometry,
    );
  });

  it('leaves out the location, which no gesture on the plan may change', () => {
    expect(
      Object.keys(rectValues({ x: 0, y: 0, width: 1, depth: 1, rotation: 0, height: 0 })),
    ).not.toContain('locationId');
  });
});

describe('nextCodes', () => {
  it('counts on from the first code', () => {
    expect(nextCodes('R2', 3)).toEqual(['R2', 'R3', 'R4']);
  });

  /** The same rule the API applies, so what the panel lists is what will actually be created. */
  it('keeps the width the number was written with, and never truncates it', () => {
    expect(nextCodes('R01', 3)).toEqual(['R01', 'R02', 'R03']);
    expect(nextCodes('R99', 2)).toEqual(['R99', 'R100']);
  });

  it('handles a stem that has its own digits in it', () => {
    expect(nextCodes('A1-R7', 2)).toEqual(['A1-R7', 'A1-R8']);
  });

  /** Nothing to count on from, or nothing to make: the panel refuses rather than offering a doomed call. */
  it('gives nothing back when there is no number or no count', () => {
    expect(nextCodes('RAYONNAGE', 3)).toEqual([]);
    expect(nextCodes('R2', 0)).toEqual([]);
    expect(nextCodes('  ', 3)).toEqual([]);
  });
});

describe('the structure layer', () => {
  const wall: StockStructureRow = {
    id: 's1',
    floorId: 'f1',
    kind: 'wall',
    x: '0.000',
    y: '0.000',
    width: '6.900',
    depth: '0.200',
    rotation: 0,
    height: '3.000',
    name: 'Mur nord',
  };

  /** The tools the palette poses, at the sizes the company builds at rather than any constant here. */
  const tools: StockStructureShape[] = [
    { kind: 'wall', width: 5, depth: 0.15, height: 2.8 },
    { kind: 'door', width: 0.8, depth: 0.15, height: 2 },
  ];

  it('offers the four tools and the footprint, and nothing about a location', () => {
    const form = structureForm();
    const fields = form.sections.flatMap((section) => section.fields.map((field) => field.id));

    expect(fields).toEqual(['kind', 'name', 'x', 'y', 'width', 'depth', 'height', 'rotation']);
    // A store arrives having numbered its own building, and most walls are still just walls.
    expect(form.sections[0]?.fields.find((field) => field.id === 'name')?.required).toBeFalsy();
    // Nothing on this layer holds goods, so no field here may ever name one.
    expect(fields).not.toContain('locationId');
    const kind = form.sections[0]?.fields[0];
    expect(kind?.options?.map((option) => option.value)).toEqual(['wall', 'door', 'post', 'dock']);
  });

  it('opens on a piece as it was saved, byte for byte', () => {
    expect(structureValues(wall, tools)).toEqual({
      kind: 'wall',
      name: 'Mur nord',
      x: '0.000',
      y: '0.000',
      width: '6.900',
      depth: '0.200',
      rotation: 0,
      height: '3.000',
    });
  });

  /** A new piece starts at the company's own measurements for that tool, never at a constant written here. */
  it('starts a new piece at this company size for the tool chosen', () => {
    expect(structureValues(null, tools, 'door')).toEqual({
      kind: 'door',
      name: '',
      x: '0.000',
      y: '0.000',
      width: '0.800',
      depth: '0.150',
      rotation: 0,
      height: '2.000',
    });
  });

  /** The same rule as a rectangle of stock: the grid holds a PLACE, never a measurement somebody took. */
  it('puts the place on the quarter-metre and leaves the measurements alone', () => {
    expect(
      structureInput({
        kind: 'door',
        name: '  Porte du quai 2  ',
        x: '2.6',
        y: '4.1',
        width: '0.9',
        depth: '0.2',
        rotation: 7,
        height: '2.1',
      }),
    ).toEqual({
      kind: 'door',
      name: 'Porte du quai 2',
      x: '2.500',
      y: '4.000',
      width: '0.900',
      depth: '0.200',
      rotation: 0,
      height: '2.100',
    });
  });

  /** An unknown kind cannot reach the API: the select is the only way in, and a bad one falls back to a wall. */
  it('never sends a kind the building is not made of', () => {
    expect(
      structureInput({
        kind: 'moat',
        x: '0',
        y: '0',
        width: '1',
        depth: '1',
        rotation: 0,
        height: '1',
      }).kind,
    ).toBe('wall');
  });

  it('reads a piece as metres the drawing can work in', () => {
    expect(structureRectangles([wall])).toEqual([
      { x: 0, y: 0, width: 6.9, depth: 0.2, rotation: 0, height: 3 },
    ]);
  });
});

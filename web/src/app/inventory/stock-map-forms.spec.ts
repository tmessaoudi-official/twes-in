// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  drawingForm,
  drawingInput,
  drawingValues,
  floorForm,
  floorInput,
  floorValues,
  planRectangles,
} from './stock-map-forms';
import type {
  StockDrawingRow,
  StockFloorRow,
  StockLocationRow,
  StockOptions,
} from './inventory-types';

const OPTIONS: StockOptions = {
  establishments: [
    { id: 'e1', code: '000', name: 'Bab Saadoun' },
    { id: 'e2', code: '001', name: 'La Marsa' },
  ],
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
      floorValues(null, { establishments: [OPTIONS.establishments[0]!] })['establishmentId'],
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

  it('snaps what was typed to the grid on its way to the API', () => {
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
      x: '2.500',
      y: '4.000',
      width: '4.000',
      depth: '0.500',
      rotation: 90,
      height: '2.100',
    });
  });

  /** A height is not a measurement on the floor grid: a rack is 2.10 m tall, not 2.25. */
  it('leaves the height alone, because it is not on the floor grid', () => {
    const input = drawingInput({
      locationId: 'l1',
      x: '0',
      y: '0',
      width: '1',
      depth: '1',
      rotation: '0',
      height: '2.10',
    });
    expect(input.height).toBe('2.100');
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

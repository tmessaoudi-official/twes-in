// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import type { FormGroup } from '@angular/forms';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { InventoryFacade } from './inventory-facade';
import type {
  InventoryError,
  StockDrawingRow,
  StockFloorRow,
  StockLocationRow,
  StockOptions,
  StockStructureRow,
} from './inventory-types';
import { StockMapPage } from './stock-map-page';
import { WINDOW_CLASS, type WindowClass } from '../shared/ui/window-class';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        plan: {
          plan_of: 'Plan de {{floor}} : {{count}} rectangle(s)',
          nothing_drawn: 'Rien n’est encore dessiné sur cet étage.',
          no_floor: 'Aucun étage n’est encore dessiné.',
        },
        errors: { level_taken: 'Ce niveau est déjà occupé.' },
      },
    });
  }
}

const ground: StockFloorRow = {
  id: 'f1',
  establishmentId: 'e1',
  name: 'Rez-de-chaussée',
  level: 0,
  imageFileId: null,
  imageMetresWide: null,
  imageOpacity: 35,
  drawingCount: 1,
};
const upstairs: StockFloorRow = { ...ground, id: 'f2', name: 'Étage 1', level: 1, drawingCount: 0 };

const rack: StockLocationRow = {
  id: 'l1',
  establishmentId: 'e1',
  parentId: null,
  kind: 'rack',
  code: 'R1',
  name: 'Rayonnage 1',
  isDefault: false,
  childCount: 0,
  movementCount: 0,
};
const zone: StockLocationRow = { ...rack, id: 'l2', kind: 'zone', code: 'Z1', name: 'Visserie' };
const bin: StockLocationRow = { ...rack, id: 'l3', kind: 'bin', code: 'R1-A1', name: 'Bac A1' };

const drawn: StockDrawingRow = {
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
};

/** The canvas's own partition: 6,90 × 0,20 m, a thickness the stock palette would refuse outright. */
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
};

const options: StockOptions = {
  establishments: [{ id: 'e1', code: '000', name: 'Bab Saadoun' }],
  // This company's own sizes, not the declared defaults: a palette that posed 3,90 would pass either way.
  planShapes: [
    { shape: 'rack', width: 2.4, depth: 0.6 },
    { shape: 'zone', width: 6, depth: 4 },
    { shape: 'aisle', width: 10, depth: 1.2 },
  ],
  // Likewise the building's own measurements, so a tool posing the declared default would not pass by luck.
  structureShapes: [
    { kind: 'wall', width: 5, depth: 0.15, height: 2.8 },
    { kind: 'door', width: 0.8, depth: 0.15, height: 2 },
    { kind: 'post', width: 0.3, depth: 0.3, height: 2.8 },
    { kind: 'dock', width: 2.6, depth: 0.15, height: 3.5 },
  ],
};

describe('StockMapPage', () => {
  const error = signal<InventoryError | null>(null);
  const busy = signal(false);
  const floors = signal<readonly StockFloorRow[]>([upstairs, ground]);
  const drawings = signal<readonly StockDrawingRow[]>([drawn]);
  const structures = signal<readonly StockStructureRow[]>([wall]);
  const facade = {
    options: signal<StockOptions | null>(options).asReadonly(),
    locations: signal<readonly StockLocationRow[]>([rack, zone, bin]).asReadonly(),
    floors: floors.asReadonly(),
    drawings: drawings.asReadonly(),
    structures: structures.asReadonly(),
    busy: busy.asReadonly(),
    error: error.asReadonly(),
    loadPlanContext: vi.fn(),
    loadDrawings: vi.fn(),
    reloadDrawings: vi.fn(),
    loadStructures: vi.fn(),
    reloadStructures: vi.fn(),
    buildStructure: vi.fn(),
    eraseStructure: vi.fn(),
    createFloor: vi.fn(),
    reviseFloor: vi.fn(),
    deleteFloor: vi.fn(),
    draw: vi.fn(),
    eraseDrawing: vi.fn(),
    repeatDrawing: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  const windowClass = signal<WindowClass>('expanded');
  let fixture: ComponentFixture<StockMapPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(testId: string, value: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  /** A select opens in the CDK overlay; the control is what the form actually carries. */
  const drawingGroup = (): FormGroup =>
    (
      fixture.componentInstance as unknown as { drawingFormGroup: () => FormGroup }
    ).drawingFormGroup();

  beforeEach(async () => {
    error.set(null);
    busy.set(false);
    floors.set([upstairs, ground]);
    drawings.set([drawn]);
    structures.set([wall]);
    facade.loadPlanContext.mockReset().mockResolvedValue(undefined);
    facade.loadDrawings.mockReset().mockResolvedValue(undefined);
    facade.reloadDrawings.mockReset().mockResolvedValue(undefined);
    facade.loadStructures.mockReset().mockResolvedValue(undefined);
    facade.reloadStructures.mockReset().mockResolvedValue(undefined);
    facade.buildStructure.mockReset().mockResolvedValue(true);
    facade.eraseStructure.mockReset().mockResolvedValue(true);
    facade.createFloor.mockReset().mockResolvedValue(true);
    facade.reviseFloor.mockReset().mockResolvedValue(true);
    facade.deleteFloor.mockReset().mockResolvedValue(true);
    facade.draw.mockReset().mockResolvedValue(true);
    facade.eraseDrawing.mockReset().mockResolvedValue(true);
    facade.repeatDrawing.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    windowClass.set('expanded');
    TestBed.configureTestingModule({
      imports: [StockMapPage],
      providers: [
        ...provideQuietFeedback(),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: InventoryFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: WINDOW_CLASS, useValue: windowClass.asReadonly() },
      ],
    });
    fixture = TestBed.createComponent(StockMapPage);
    await settle();
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  /** The ground floor is level 0 and the list arrives out of order, so the sort is what picks it. */
  it('opens on the lowest floor and reads what is drawn on it', () => {
    expect(facade.loadPlanContext).toHaveBeenCalledWith('c1');
    expect(facade.loadDrawings).toHaveBeenCalledWith('c1', 'f1');
    expect(q('stock-map-svg')?.getAttribute('aria-label')).toBe(
      'Plan de Rez-de-chaussée : 1 rectangle(s)',
    );
  });

  it('draws each rectangle in metres, turned about its own centre', () => {
    const rectangle = fixture.nativeElement.querySelector(
      '[data-testid^="stock-drawing-rect-"]',
    ) as SVGRectElement;
    expect(rectangle.getAttribute('x')).toBe('2.5');
    expect(rectangle.getAttribute('y')).toBe('4');
    expect(rectangle.getAttribute('width')).toBe('3.9');
    // The footprint's second side is the SVG's height: a plan is seen from above.
    expect(rectangle.getAttribute('height')).toBe('0.6');

    const group = fixture.nativeElement.querySelector(
      '[data-testid^="stock-drawing-group-"]',
    ) as SVGGElement;
    expect(group.getAttribute('transform')).toBe('rotate(0 4.45 4.3)');
  });

  /** The plan is not the only way in: decision 8 says a phone reads the map, and a list is reachable there. */
  it('lists what is drawn beside the plan, so nothing needs pointing at the drawing', () => {
    expect(q('stock-drawing-R1')?.textContent).toContain('Rayonnage 1');
  });

  it('shows the other floor when it is asked for, and reads that floor’s rectangles', async () => {
    q('stock-floor-f2')!.click();
    await settle();

    expect(facade.loadDrawings).toHaveBeenLastCalledWith('c1', 'f2');
  });

  /**
   * The floor being looked at is MARKED, not disabled. A disabled control cannot be focused, so it would be the one
   * tab a keyboard cannot reach and a screen reader skips — and, measurably, a click on it waits for ever.
   */
  it('marks the floor being looked at without taking it out of reach', () => {
    const current = q('stock-floor-f1')!;
    expect(current.getAttribute('aria-current')).toBe('true');
    expect((current as HTMLButtonElement).disabled).toBe(false);
    expect(q('stock-floor-f2')!.getAttribute('aria-current')).toBeNull();
  });

  it('says so when a floor carries nothing yet, rather than showing an empty frame alone', async () => {
    drawings.set([]);
    await settle();

    expect(q('stock-map-empty')?.textContent).toContain('Rien n’est encore dessiné');
  });

  it('says so when the company has drawn no floor at all', async () => {
    floors.set([]);
    await settle();

    expect(q('stock-map-no-floor')?.textContent).toContain('Aucun étage');
    expect(q('stock-map-svg')).toBeNull();
  });

  it('draws a location on the floor being looked at, snapped to the grid', async () => {
    q('stock-drawing-add')!.click();
    await settle();
    drawingGroup().get('locationId')!.setValue('l2');
    type('field-x', '2.6');
    type('field-y', '4.1');
    q('stock-drawing-save')!.click();
    await settle();

    expect(facade.draw).toHaveBeenCalledWith(
      'c1',
      'f1',
      // The place goes to the grid (2,6 → 2,5); a measurement does not.
      expect.objectContaining({ locationId: 'l2', x: '2.500', y: '4.000' }),
      // Null: this is a new rectangle, not one being moved.
      null,
    );
    expect(successToasts()).toContain('inventory.plan.drawing_saved');
  });

  it('moves the rectangle being edited rather than drawing a second one for the same rack', async () => {
    q('stock-drawing-R1')!.click();
    await settle();
    q('stock-drawing-edit')!.click();
    await settle();
    q('stock-drawing-save')!.click();
    await settle();

    expect(facade.draw).toHaveBeenCalledWith('c1', 'f1', expect.anything(), 'd1');
  });

  it('erases a rectangle without touching what it was drawn for', async () => {
    q('stock-drawing-R1')!.click();
    await settle();
    q('stock-drawing-erase')!.click();
    await settle();

    expect(facade.eraseDrawing).toHaveBeenCalledWith('c1', 'f1', 'd1');
    expect(successToasts()).toContain('inventory.plan.drawing_erased');
  });

  it('offers to remove only a floor that carries nothing', async () => {
    q('stock-floor-f2')!.click();
    await settle();
    q('stock-floor-edit')!.click();
    await settle();
    expect(q('stock-floor-remove')).not.toBeNull();

    q('stock-floor-cancel')!.click();
    await settle();
    q('stock-floor-f1')!.click();
    await settle();
    q('stock-floor-edit')!.click();
    await settle();
    // The ground floor carries a rectangle, so removing it is not offered at all rather than refused.
    expect(q('stock-floor-remove')).toBeNull();
  });

  it('adds a floor of the company’s only establishment', async () => {
    q('stock-floor-add')!.click();
    await settle();
    type('field-name', 'Mezzanine');
    type('field-level', '2');
    q('stock-floor-save')!.click();
    await settle();

    expect(facade.createFloor).toHaveBeenCalledWith('c1', {
      establishmentId: 'e1',
      name: 'Mezzanine',
      level: 2,
      imageFileId: null,
      imageMetresWide: null,
      imageOpacity: 35,
    });
  });

  it('says why a level was refused', async () => {
    error.set('level_taken');
    await settle();

    expect(q('stock-map-error')?.textContent).toContain('Ce niveau est déjà occupé.');
  });

  it('offers nothing to change to somebody who may only read the stock', async () => {
    auth.hasPermission.mockReturnValue(false);
    // Built again: what may be written is read once, when the screen is made.
    fixture = TestBed.createComponent(StockMapPage);
    await settle();

    expect(q('stock-drawing-add')).toBeNull();
    expect(q('stock-floor-add')).toBeNull();
    expect(q('stock-map-svg')).not.toBeNull();
  });
  // ——— the plan as a drawing surface (docs/SPEC.md § 7, 2026-09-21 21:30) ———

  /** jsdom lays nothing out, so every rect is 0×0 and a pointer would read the same metre everywhere. */
  function surface(): Element {
    const svg = q('stock-map-svg') as unknown as Element;
    svg.getBoundingClientRect = () =>
      ({
        left: 0,
        top: 0,
        width: 400,
        height: 400,
        right: 400,
        bottom: 400,
        x: 0,
        y: 0,
      }) as DOMRect;

    return svg;
  }

  const at = (x: number, y: number, target: Element): PointerEvent =>
    ({
      pointerId: 1,
      button: 0,
      clientX: x,
      clientY: y,
      target,
      preventDefault: () => undefined,
    }) as unknown as PointerEvent;

  const plan = (): {
    grab: (event: PointerEvent, drawing: StockDrawingRow, handle: unknown) => void;
    drags: (event: PointerEvent) => void;
    drops: (event: PointerEvent) => void;
    abandon: () => void;
    handles: () => readonly { handle: { hx: number; hy: number } }[];
    mayDraw: () => boolean;
  } => fixture.componentInstance as never;

  /**
   * Dispatched at the DOM, never at the methods: the bindings are half of what a drag IS, and a test that calls
   * `grab` by hand proves the arithmetic while leaving `(pointerdown)` and `(document:pointermove)` unexercised.
   * jsdom has no PointerEvent, so a MouseEvent carries the one field the handlers read.
   */
  function fire(target: EventTarget, type: string, x: number, y: number): void {
    const event = new MouseEvent(type, {
      clientX: x,
      clientY: y,
      button: 0,
      bubbles: true,
      cancelable: true,
    });
    Object.defineProperty(event, 'pointerId', { value: 1 });
    target.dispatchEvent(event);
  }

  /**
   * A rectangle of STOCK, named rather than taken by position: the structure layer is drawn first so that a wall
   * sits under a rack, which makes `svg rect` the building rather than the stock.
   */
  const firstRect = (): Element =>
    fixture.nativeElement.querySelector('[data-testid^="stock-drawing-rect-"]') as Element;

  /** The rectangle drawn LAST, which is the one being traced: `shapes()` pushes the pending box after the saved. */
  const lastRect = (): Element =>
    [...fixture.nativeElement.querySelectorAll('[data-testid^="stock-drawing-rect-"]')].at(
      -1,
    ) as Element;

  it('moves a rectangle through the DOM bindings, not only through its methods', async () => {
    surface();
    fire(firstRect(), 'pointerdown', 100, 100);
    fire(document, 'pointermove', 200, 100);
    fire(document, 'pointerup', 200, 100);
    await settle();

    expect(drawingGroup()?.get('x')?.value).toBe('6.000');
  });

  it('moves a rectangle onto the grid and writes it into the form, sending nothing', async () => {
    const svg = surface();
    plan().grab(at(100, 100, svg), drawn, null);
    plan().drags(at(200, 100, svg));
    await settle();

    // 100 px right, on a frame of 13,9 m drawn across 400 px, is 3,47 m: 2,50 + 3,47 lands on 6,00.
    expect(drawingGroup().get('x')!.value).toBe('6.000');
    expect(drawingGroup().get('y')!.value).toBe('4.000');
    // A move carries the size through untouched, and nothing has been sent.
    expect(drawingGroup().get('width')!.value).toBe('3.900');
    expect(facade.draw).not.toHaveBeenCalled();
  });

  /** The plan must follow the form, or a dragged rectangle stays where the API last put it. */
  it('draws the rectangle where it is being dragged, not where it was saved', async () => {
    const svg = surface();
    plan().grab(at(100, 100, svg), drawn, null);
    plan().drags(at(200, 100, svg));
    await settle();

    expect(
      fixture.nativeElement
        .querySelector('[data-testid^="stock-drawing-rect-"]')
        ?.getAttribute('x'),
    ).toBe('6');
  });

  it('is a look, not a drag, until the pointer has really travelled', async () => {
    const svg = surface();
    plan().grab(at(100, 100, svg), drawn, null);
    plan().drags(at(102, 100, svg));
    await settle();

    // No editor opened and nothing changed: pressing a rack to read it must not cost an unsaved change.
    expect(drawingGroup()).toBeNull();
    expect(q('stock-drawing-unsaved')).toBeNull();
  });

  it('pulls one handle and holds the opposite corner, changing the size and not the place', async () => {
    const svg = surface();
    plan().grab(at(100, 100, svg), drawn, { hx: 1, hy: 1 });
    plan().drags(at(200, 100, svg));
    await settle();

    expect(drawingGroup().get('x')!.value).toBe('2.500');
    expect(drawingGroup().get('width')!.value).toBe('2.000');
  });

  it('counts what a drag left unsaved, because nothing else on the plan says so', async () => {
    const svg = surface();
    plan().grab(at(100, 100, svg), drawn, null);
    plan().drags(at(200, 100, svg));
    await settle();

    expect(q('stock-drawing-unsaved')?.getAttribute('data-count')).toBe('1');
  });

  it('gives the rectangle back exactly what it had when Échap is pressed', async () => {
    const svg = surface();
    plan().grab(at(100, 100, svg), drawn, null);
    plan().drags(at(200, 100, svg));
    await settle();
    plan().abandon();
    await settle();

    expect(drawingGroup().get('x')!.value).toBe('2.500');
    expect(q('stock-drawing-unsaved')).toBeNull();
  });

  /** Decision 8: the phone READS the map. A handle smaller than a fingertip is worse than no handle. */
  it('offers no handle and no drag on a phone', async () => {
    windowClass.set('compact');
    fixture = TestBed.createComponent(StockMapPage);
    await settle();
    const svg = surface();
    plan().grab(at(100, 100, svg), drawn, null);
    plan().drags(at(200, 100, svg));
    await settle();

    expect(plan().mayDraw()).toBe(false);
    expect(plan().handles()).toEqual([]);
    expect(drawingGroup()).toBeNull();
  });

  /**
   * The e2e's own sequence, which CI red on: a rectangle saved through the FORM, then dragged. Saving used to thaw
   * the frame while leaving the rectangle chosen, so the plan changed size under a pointer that was already on it.
   */
  it('drags a rectangle that was just saved through the form', async () => {
    const svg = surface();
    (fixture.componentInstance as never as { draw: (t: StockDrawingRow) => void }).draw(drawn);
    await settle();
    drawingGroup().get('x')!.setValue('5.000');
    await settle();
    drawings.set([{ ...drawn, x: '5.000' }]);
    const saved = { ...drawn, x: '5.000' };

    plan().grab(at(100, 100, svg), saved, null);
    plan().drags(at(200, 100, svg));
    await settle();

    expect(drawingGroup().get('x')!.value).not.toBe('5.000');
  });
  /**
   * A click that is swallowed because something else is in flight is the worst kind of dead control: the button is
   * there, it depresses, and nothing happens — no toast, no error, no change. `erase` and `removeFloor` both guard
   * on `busy()` and said nothing about it, which a reload in the e2e turned into a real swallowed click.
   */
  it('shows that erasing is unavailable while the plan is loading rather than swallowing the click', async () => {
    q('stock-drawing-R1')?.click();
    await settle();
    busy.set(true);
    await settle();

    expect((q('stock-drawing-erase') as HTMLButtonElement).disabled).toBe(true);

    busy.set(false);
    await settle();
    expect((q('stock-drawing-erase') as HTMLButtonElement).disabled).toBe(false);
  });

  // ——— tracing a box on bare floor (decision 1 of the approved canvas) ———

  /**
   * The tool has to be armed first. A sheet that traced a rectangle on any press would turn a finger scrolling past
   * the plan into a new rack, and would have to hold `touch-action: none` over a full-width block to do it.
   */
  it('traces a box on bare floor once the tool is armed, and opens it as a new rectangle', async () => {
    surface();
    q('stock-map-trace')?.click();
    await settle();

    fire(surface(), 'pointerdown', 100, 100);
    fire(document, 'pointermove', 200, 150);
    fire(document, 'pointerup', 200, 150);
    await settle();

    // The two corners read 0,97 × 0,82 m and 4,45 × 2,56 m on this frame, each taken to the quarter.
    expect(drawingGroup().get('x')!.value).toBe('1.000');
    expect(drawingGroup().get('y')!.value).toBe('0.750');
    expect(drawingGroup().get('width')!.value).toBe('3.500');
    expect(drawingGroup().get('depth')!.value).toBe('1.750');
    // It is a rectangle waiting for its location, and nothing has been sent.
    expect(q('stock-drawing-form')).not.toBeNull();
    expect(facade.draw).not.toHaveBeenCalled();
  });

  /** A trace decides an emprise, never an angle or a height: those are measurements, and decision 3 types them. */
  it('leaves the new rectangle’s height and angle at what the form offers', async () => {
    surface();
    q('stock-map-trace')?.click();
    await settle();
    fire(surface(), 'pointerdown', 100, 100);
    fire(document, 'pointermove', 200, 150);
    await settle();

    expect(drawingGroup().get('height')!.value).toBe('2.000');
    expect(drawingGroup().get('rotation')!.value).toBe(0);
  });

  it('draws nothing on a press that never armed the tool', async () => {
    fire(surface(), 'pointerdown', 100, 100);
    fire(document, 'pointermove', 200, 150);
    fire(document, 'pointerup', 200, 150);
    await settle();

    expect(drawingGroup()).toBeNull();
  });

  /** One box per arming: the sheet goes back to being something a finger can scroll past. */
  it('disarms itself once a box has been traced', async () => {
    surface();
    q('stock-map-trace')?.click();
    await settle();
    fire(surface(), 'pointerdown', 100, 100);
    fire(document, 'pointermove', 200, 150);
    fire(document, 'pointerup', 200, 150);
    await settle();

    expect(q('stock-map-trace')?.getAttribute('aria-pressed')).toBe('false');
  });

  /** A press that landed on a rectangle belongs to that rectangle, and it bubbles to the sheet on its way up. */
  it('moves a rectangle rather than tracing over it when the tool is armed', async () => {
    surface();
    q('stock-map-trace')?.click();
    await settle();
    fire(firstRect(), 'pointerdown', 100, 100);
    fire(document, 'pointermove', 200, 100);
    await settle();

    expect(drawingGroup().get('x')!.value).toBe('6.000');
    expect(drawingGroup().get('width')!.value).toBe('3.900');
  });

  /**
   * A box just traced has no id yet, so it is not among what the API answered. The gesture that moves it must still
   * keep what the form held, or Échap gives it nothing back — and after a trace that is the common path, not a
   * corner: the rectangle a person adjusts first is the one they have only just drawn.
   */
  it('gives a just-traced rectangle back what it had when Échap is pressed', async () => {
    surface();
    q('stock-map-trace')?.click();
    await settle();
    fire(surface(), 'pointerdown', 100, 100);
    fire(document, 'pointermove', 200, 150);
    fire(document, 'pointerup', 200, 150);
    await settle();
    const traced = drawingGroup().get('x')!.value;

    // Now move the box that was traced, and take it back.
    fire(lastRect(), 'pointerdown', 150, 120);
    fire(document, 'pointermove', 250, 120);
    await settle();
    expect(drawingGroup().get('x')!.value).not.toBe(traced);

    plan().abandon();
    await settle();
    expect(drawingGroup().get('x')!.value).toBe(traced);
  });

  // ——— the palette of ready-made shapes ———

  /**
   * Nobody types 3,90 × 0,60 forty times. Each shape is a BUTTON, which is what makes the palette reachable without
   * a pointer: activating it poses the rectangle at the centre of the plan, selected, with its form open.
   */
  it('poses a shape of the palette at the centre of the floor, at this company’s size', async () => {
    q('stock-shape-rack')?.click();
    await settle();

    expect(drawingGroup().get('width')!.value).toBe('2.400');
    expect(drawingGroup().get('depth')!.value).toBe('0.600');
    // The frame is 13,9 × 10,6 m from -2,5, -1, so its middle is 4,45 by 4,30 and a 2,40 × 0,60 box is placed on
    // the quarter-metre around it.
    expect(drawingGroup().get('x')!.value).toBe('3.250');
    expect(drawingGroup().get('y')!.value).toBe('4.000');
    expect(facade.draw).not.toHaveBeenCalled();
  });

  /** A size is the COMPANY's, never the code's: the palette shows what it will pose, in metres. */
  it('shows each shape at the size it will be posed at', async () => {
    expect(q('stock-shape-rack')?.textContent).toContain('2.4');
    expect(q('stock-shape-rack')?.textContent).toContain('0.6');
    expect(q('stock-shape-zone')?.textContent).toContain('6');
    // The four of the canvas, minus what this company has no size for: nothing is invented here. The dock is the
    // one this fixture leaves out — the aisle joined it when the repeat panel began reading its width.
    expect(q('stock-shape-dock')).toBeNull();
  });

  /** A shape posed is a rectangle being drawn, so the plan shows it before anything is saved. */
  it('draws the posed shape on the plan straight away', async () => {
    q('stock-shape-zone')?.click();
    await settle();

    const drawn = [
      ...fixture.nativeElement.querySelectorAll('[data-testid^="stock-drawing-rect-"]'),
    ].at(-1) as Element;
    expect(drawn.getAttribute('width')).toBe('6');
    expect(drawn.getAttribute('height')).toBe('4');
  });

  /** Decision 8: the phone reads the map. A palette that poses rectangles is not a reading tool. */
  it('offers no palette on a phone', async () => {
    windowClass.set('compact');
    fixture = TestBed.createComponent(StockMapPage);
    await settle();

    expect(q('stock-shape-rack')).toBeNull();
  });

  /** Decision 8 again: the phone reads the map, so there is nothing to arm. */
  it('offers no tracing tool on a phone', async () => {
    windowClass.set('compact');
    fixture = TestBed.createComponent(StockMapPage);
    await settle();

    expect(q('stock-map-trace')).toBeNull();
  });

  /**
   * Repeating a rack down an aisle. The panel opens with what a person would have typed anyway — the next code and
   * this company's own aisle width — so the common repeat needs no typing at all.
   */
  async function openRepeat(): Promise<void> {
    fixture = TestBed.createComponent(StockMapPage);
    await settle();
    (q('stock-drawing-R1') as HTMLElement).click();
    await settle();
    (q('stock-drawing-repeat') as HTMLElement).click();
    await settle();
  }

  it('opens the repeat panel on the next code and the company’s own aisle width', async () => {
    await openRepeat();

    expect((q('stock-repeat-code') as HTMLInputElement).value).toBe('R2');
    expect((q('stock-repeat-spacing') as HTMLInputElement).value).toBe('1.2');
    expect((q('stock-repeat-count') as HTMLInputElement).value).toBe('1');
  });

  /** What will be created is said BEFORE it is created, codes and all: these are stock locations, not only shapes. */
  it('lists the codes it will create before creating them', async () => {
    await openRepeat();
    type('stock-repeat-count', '3');
    await settle();

    expect(q('stock-repeat-codes')?.textContent?.trim()).toBe('R2, R3, R4');
    expect(facade.repeatDrawing).not.toHaveBeenCalled();
  });

  /** The preview is drawn dotted, one rectangle per copy, from the same arithmetic the API will run. */
  it('draws one dotted rectangle per copy, where each will land', async () => {
    await openRepeat();
    type('stock-repeat-count', '2');
    await settle();

    expect(q('stock-repeat-preview-0')?.getAttribute('y')).toBe('5.8');
    expect(q('stock-repeat-preview-1')?.getAttribute('y')).toBe('7.6');
    expect(q('stock-repeat-preview-2')).toBeNull();
  });

  /**
   * "Une copie qui sortirait du sol est refusée AVANT, pas après" (the approved canvas): the button is disabled and
   * the reason is on the screen, rather than a refusal arriving from the API once the person has pressed it.
   */
  it('refuses a copy that would leave the floor before anything is sent', async () => {
    await openRepeat();
    (q('stock-repeat-way-up') as HTMLElement).click();
    type('stock-repeat-count', '4');
    await settle();

    expect((q('stock-repeat-save') as HTMLButtonElement).disabled).toBe(true);
    expect(q('stock-repeat-summary')?.textContent).toContain('repeat_off_floor');

    (q('stock-repeat-save') as HTMLElement).click();
    await settle();
    expect(facade.repeatDrawing).not.toHaveBeenCalled();
  });

  /**
   * A spacing that is not a measurement must DISABLE the repeat, never quietly become zero. A lenient `Number()`
   * would have read `1,2,3` as nothing, shown a preview of racks back to back, and sent `"0"` — a plan the person
   * never asked for, accepted by an API that has no way to know it was a typo.
   */
  it('refuses a spacing that is not a measurement rather than reading it as zero', async () => {
    await openRepeat();
    type('stock-repeat-spacing', '1,2,3');
    await settle();

    expect((q('stock-repeat-save') as HTMLButtonElement).disabled).toBe(true);
    expect(q('stock-repeat-preview-0')).toBeNull();

    // And a real measurement, comma or point, is taken.
    type('stock-repeat-spacing', '0,6');
    await settle();
    expect((q('stock-repeat-save') as HTMLButtonElement).disabled).toBe(false);
  });

  /** The API caps a repeat at fifty; the panel says so here rather than letting the person press and get a 422. */
  it('refuses a count past the cap before it is sent', async () => {
    await openRepeat();
    type('stock-repeat-count', '51');
    await settle();

    expect((q('stock-repeat-save') as HTMLButtonElement).disabled).toBe(true);

    type('stock-repeat-count', '50');
    await settle();
    expect((q('stock-repeat-save') as HTMLButtonElement).disabled).toBe(false);
  });

  /** A code with no number to count on from is the other way a repeat cannot be made, and is refused the same way. */
  it('refuses a first code with no number in it', async () => {
    await openRepeat();
    type('stock-repeat-code', 'RAYONNAGE');
    await settle();

    expect((q('stock-repeat-save') as HTMLButtonElement).disabled).toBe(true);
  });

  it('sends the repeat as it was set, and says so once it is made', async () => {
    await openRepeat();
    type('stock-repeat-count', '3');
    type('stock-repeat-spacing', '0,6');
    (q('stock-repeat-way-right') as HTMLElement).click();
    await settle();
    (q('stock-repeat-save') as HTMLElement).click();
    await settle();

    // The comma a French keyboard types reaches the API as the point it expects.
    expect(facade.repeatDrawing).toHaveBeenCalledWith('c1', 'f1', 'd1', {
      count: 3,
      spacing: '0.6',
      way: 'right',
      firstCode: 'R2',
    });
    expect(successToasts()).toContain('inventory.plan.repeated');
    expect(q('stock-repeat-panel')).toBeNull();
  });

  /** Decision 8 once more: a phone reads the map, so there is nothing on it to repeat. */
  it('offers no repeat on a phone', async () => {
    windowClass.set('compact');
    fixture = TestBed.createComponent(StockMapPage);
    await settle();
    (q('stock-drawing-R1') as HTMLElement).click();
    await settle();

    expect(q('stock-drawing-repeat')).toBeNull();
  });
  /**
   * The building is drawn and is none of the stock: it carries no location code, so it appears in no list, and it
   * sits UNDER the rectangles, which is what makes a rack against a wall readable.
   */
  it('draws the building beneath the stock and lists none of it', () => {
    const piece = fixture.nativeElement.querySelector(
      '[data-testid="stock-structure-wall"]',
    ) as SVGRectElement;
    expect(piece.getAttribute('x')).toBe('0');
    expect(piece.getAttribute('y')).toBe('0');
    expect(piece.getAttribute('width')).toBe('6.9');
    expect(piece.getAttribute('height')).toBe('0.2');

    // Document order, which is what the eye and the pointer both follow: structure first, stock after.
    const drawn = fixture.nativeElement.querySelector(
      '[data-testid^="stock-drawing-rect-"]',
    ) as SVGRectElement;
    expect(piece.compareDocumentPosition(drawn) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    // And it is on no list of what is drawn: a wall is not a place, so it has no code to show.
    expect(q('stock-drawing-s1')).toBeNull();
    expect(q('stock-map-layers')).not.toBeNull();
    expect(q('stock-layer-count-structure')?.textContent?.trim()).toBe('1');
  });

  /** The tool poses at THIS company's measurements — 0,80 × 0,15 × 2,00 for a door — not at a constant. */
  it('poses a piece at the size this company builds at, and saves it as a piece of structure', async () => {
    (q('stock-structure-tool-door') as HTMLElement).click();
    await settle();

    expect((q('field-width') as HTMLInputElement).value).toBe('0,800');
    expect((q('field-depth') as HTMLInputElement).value).toBe('0,150');
    expect((q('field-height') as HTMLInputElement).value).toBe('2,000');

    (q('stock-structure-save') as HTMLElement).click();
    await settle();

    expect(facade.buildStructure).toHaveBeenCalledWith(
      'c1',
      'f1',
      expect.objectContaining({ kind: 'door', width: '0.800', depth: '0.150', height: '2.000' }),
      null,
    );
    expect(successToasts()).toContain('inventory.plan.structure_saved');
    expect(q('field-width')).toBeNull();
  });

  /** Correcting the kind is the common repair: a doorway traced with the wall tool is wrong in exactly one field. */
  it('opens a piece from the plan and revises the one it already is', async () => {
    (q('stock-structure-wall') as unknown as SVGRectElement).dispatchEvent(
      new MouseEvent('click', { bubbles: true }),
    );
    await settle();

    expect((q('field-width') as HTMLInputElement).value).toBe('6,900');

    (q('stock-structure-save') as HTMLElement).click();
    await settle();

    expect(facade.buildStructure).toHaveBeenCalledWith(
      'c1',
      'f1',
      expect.objectContaining({ kind: 'wall', width: '6.900' }),
      's1',
    );
  });

  it('erases a piece of structure from its own form', async () => {
    (q('stock-structure-wall') as unknown as SVGRectElement).dispatchEvent(
      new MouseEvent('click', { bubbles: true }),
    );
    await settle();
    (q('stock-structure-erase') as HTMLElement).click();
    await settle();

    expect(facade.eraseStructure).toHaveBeenCalledWith('c1', 'f1', 's1');
    expect(successToasts()).toContain('inventory.plan.structure_erased');
  });

  /**
   * The layers panel's two promises, in its own words: locked stops answering the pointer while staying drawn and
   * keyboard-reachable, hidden takes the layer off the plan altogether.
   */
  it('locks a layer against the pointer and hides it altogether', async () => {
    (q('stock-layer-locked-structure') as HTMLElement).click();
    await settle();

    const layer = q('stock-structure-layer') as unknown as SVGGElement;
    expect(layer.classList.contains('pointer-events-none')).toBe(true);
    expect(q('stock-structure-wall')).not.toBeNull();

    (q('stock-layer-shown-structure') as HTMLElement).click();
    await settle();

    expect(q('stock-structure-layer')).toBeNull();
    // Hiding the building leaves the stock exactly where it was.
    expect(
      fixture.nativeElement.querySelector('[data-testid^="stock-drawing-rect-"]'),
    ).not.toBeNull();
  });
});

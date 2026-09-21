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

const options: StockOptions = { establishments: [{ id: 'e1', code: '000', name: 'Bab Saadoun' }] };

describe('StockMapPage', () => {
  const error = signal<InventoryError | null>(null);
  const floors = signal<readonly StockFloorRow[]>([upstairs, ground]);
  const drawings = signal<readonly StockDrawingRow[]>([drawn]);
  const facade = {
    options: signal<StockOptions | null>(options).asReadonly(),
    locations: signal<readonly StockLocationRow[]>([rack, zone, bin]).asReadonly(),
    floors: floors.asReadonly(),
    drawings: drawings.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadPlanContext: vi.fn(),
    loadDrawings: vi.fn(),
    reloadDrawings: vi.fn(),
    createFloor: vi.fn(),
    reviseFloor: vi.fn(),
    deleteFloor: vi.fn(),
    draw: vi.fn(),
    eraseDrawing: vi.fn(),
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
    floors.set([upstairs, ground]);
    drawings.set([drawn]);
    facade.loadPlanContext.mockReset().mockResolvedValue(undefined);
    facade.loadDrawings.mockReset().mockResolvedValue(undefined);
    facade.reloadDrawings.mockReset().mockResolvedValue(undefined);
    facade.createFloor.mockReset().mockResolvedValue(true);
    facade.reviseFloor.mockReset().mockResolvedValue(true);
    facade.deleteFloor.mockReset().mockResolvedValue(true);
    facade.draw.mockReset().mockResolvedValue(true);
    facade.eraseDrawing.mockReset().mockResolvedValue(true);
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
    const rectangle = fixture.nativeElement.querySelector('svg rect') as SVGRectElement;
    expect(rectangle.getAttribute('x')).toBe('2.5');
    expect(rectangle.getAttribute('y')).toBe('4');
    expect(rectangle.getAttribute('width')).toBe('3.9');
    // The footprint's second side is the SVG's height: a plan is seen from above.
    expect(rectangle.getAttribute('height')).toBe('0.6');

    const group = fixture.nativeElement.querySelector('svg g') as SVGGElement;
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

    expect(fixture.nativeElement.querySelector('svg rect')?.getAttribute('x')).toBe('6');
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
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  linkedSignal,
  OnInit,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import { dirtyCount } from '../shared/form/dirty-count';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { Label } from '../shared/a11y/label';
import { InventoryFacade } from './inventory-facade';
import { INVENTORY_TABS } from './inventory-nav';
import { PageTabs } from '../shared/ui/page-tabs';
import type { StockDrawingRow, StockFloorRow } from './inventory-types';
import {
  drawingForm,
  drawingInput,
  drawingValues,
  floorForm,
  floorInput,
  floorValues,
  footprintValues,
  planRectangles,
  rectValues,
} from './stock-map-forms';
import {
  handleAt,
  movedTo,
  PLAN_HANDLES,
  planFrame,
  pointerMetres,
  resizedTo,
  tracedTo,
  type PlanBox,
  type PlanFrame,
  type PlanHandle,
  type PlanPoint,
  type PlanRectangle,
} from './stock-map-geometry';
import { WINDOW_CLASS } from '../shared/ui/window-class';

/** How much floor is left around what is drawn, in metres, so nothing touches the frame. */
const PLAN_PADDING = 1;

/**
 * How much bare floor is kept around what is drawn, in metres, beyond the margin. The frame is measured from what
 * is SAVED (see `frame` below), so it does not open up when a gesture begins: the room has to be there already, or
 * there would be nowhere to drag a rectangle to and no bare floor to trace a new one on.
 */
const EDIT_ROOM = 4;

/**
 * How far a pointer travels before it is a drag and not a click, in pixels. Without it, pressing a rectangle to
 * LOOK at it would open its editor and report a change, which is the opposite of what a glance should cost.
 */
const DRAG_THRESHOLD = 4;

/** The id a rectangle carries while it is being drawn and has no id of its own yet. */
const PENDING_ID = 'pending';

/** A gesture in progress. It holds what the rectangle was, never what it is becoming: every move recomputes. */
interface Drag {
  pointerId: number;
  /** The handle being pulled, or `null` when the rectangle itself is being moved. */
  handle: PlanHandle | null;
  /** The rectangle being worked on, and what it was when the gesture began — both `null` while one is traced. */
  drawingId: string | null;
  origin: PlanRectangle | null;
  from: PlanPoint;
  /** Frozen at the start: both must stay still, or the metres under the pointer change as it moves. */
  frame: PlanFrame;
  box: PlanBox;
  /** What the form held before the gesture, so Échap can put it back exactly. */
  before: FormValues;
  past: boolean;
  startedAt: PlanPoint;
}

/** One rectangle as the plan draws it: the numbers the SVG needs, and what is written on it. */
interface PlanShape {
  drawing: StockDrawingRow;
  rect: PlanRectangle;
  /** Degrees about the rectangle's own centre, as the SVG's rotate() takes them. */
  centreX: number;
  centreY: number;
  /** Where the code is written: inside the rectangle, turned with it. */
  labelX: number;
  labelY: number;
}

/**
 * The drawn stock map (docs/SPEC.md row 83; § 7, 2026-09-21, the eight decisions): a floor is the canvas, and each
 * zone and rack is a rectangle on it, in metres, snapped to the quarter.
 *
 * Editing is a FORM beside the plan rather than only a drag: a form is reachable by keyboard and on a phone, where
 * decision 8 says the map is read and not drawn, and it is what the approved canvas draws. Dragging a rectangle is
 * an accelerator on top of it, not the way in.
 */
@Component({
  selector: 'app-stock-map-page',
  imports: [PageTabs, MatButtonModule, MatCardModule, TranslatePipe, DescriptorForm, Label],
  templateUrl: './stock-map-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockMapPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(InventoryFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  protected readonly tabs = INVENTORY_TABS;

  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('stock.write'));

  /** The floors of the company, ground first, as the tabs read them. */
  protected readonly floors = computed(() =>
    [...this.facade.floors()].sort((one, other) => one.level - other.level),
  );

  /** Which floor is being looked at: the one chosen, or the lowest there is. */
  protected readonly chosenFloorId = signal<string | null>(null);
  protected readonly floor = computed(() => {
    const floors = this.floors();
    const chosen = this.chosenFloorId();

    return floors.find((one) => one.id === chosen) ?? floors[0] ?? null;
  });

  /**
   * What the open form holds right now, kept in step with the controls by the effect below. The plan draws the
   * rectangle being edited from THIS rather than from what the API answered, so a rectangle follows the pointer
   * while it is dragged and follows the keyboard while a measurement is typed — one mechanism for both.
   */
  private readonly formValues = signal<FormValues | null>(null);

  protected readonly previewRect = computed<PlanRectangle | null>(() => {
    const values = this.formValues();
    if (this.editing() === null || values === null) return null;

    // Read as typed, not as it will be sent: seeing 3,9 snap to 3,75 under your own fingers is not a preview.
    return {
      x: metres(values['x']),
      y: metres(values['y']),
      width: metres(values['width']),
      depth: metres(values['depth']),
      rotation: metres(values['rotation']),
      height: metres(values['height']),
    };
  });

  /** What is drawn on the floor being looked at: what was saved, with the one being edited shown as it now stands. */
  protected readonly shapes = computed<PlanShape[]>(() => {
    const editing = this.editing();
    const preview = this.previewRect();
    const shapes = this.facade.drawings().map((drawing) => {
      const [saved] = planRectangles([drawing]);
      const shown =
        preview !== null && editing !== null && editing !== 'new' && editing.id === drawing.id
          ? preview
          : (saved ?? { x: 0, y: 0, width: 0, depth: 0, rotation: 0, height: 0 });

      return shapeOf(drawing, shown);
    });

    if (editing === 'new' && preview !== null) shapes.push(shapeOf(this.pendingRow(), preview));

    return shapes;
  });

  /**
   * The part of the floor being shown, measured from what is SAVED and never from what is being edited — so it
   * changes only when something is saved, and never while a rectangle is being dragged or typed.
   *
   * An earlier version froze it on selection and thawed it on saving. That was worse than the problem it solved: the
   * plan changed scale between a save and the next gesture, so a pointer already resting on a rectangle found it
   * somewhere else the moment it was pressed [measured in CI, 2026-09-21: the frame went from 13,9 m wide to 5,9 m
   * between one step and the next]. The working margin is simply always there instead.
   */
  protected readonly frame = computed<PlanFrame>(() =>
    planFrame(planRectangles(this.facade.drawings()), PLAN_PADDING + EDIT_ROOM),
  );

  protected readonly viewBox = computed(() => {
    const frame = this.frame();

    return [frame.x, frame.y, frame.width, frame.height].join(' ');
  });

  /** What the plan says to a reader who cannot see it, which is also what a screen reader is given. */
  protected readonly planLabel = computed(() => ({
    floor: this.floor()?.name ?? '',
    count: this.shapes().length,
  }));

  protected readonly selectedId = signal<string | null>(null);
  protected readonly selected = computed(
    () => this.facade.drawings().find((drawing) => drawing.id === this.selectedId()) ?? null,
  );

  // ——— the rectangle form ———

  protected readonly editing = signal<StockDrawingRow | 'new' | null>(null);
  protected readonly drawingDescriptor = computed(() => {
    const editing = this.editing();
    if (editing === null) return null;

    return drawingForm(
      this.facade.locations(),
      this.facade.drawings(),
      editing === 'new' ? null : editing,
    );
  });
  protected readonly drawingFormGroup = linkedSignal<
    { editing: StockDrawingRow | 'new' | null; descriptor: FormDescriptor | null },
    DescriptorFormGroup | null
  >({
    source: () => ({ editing: this.editing(), descriptor: this.drawingDescriptor() }),
    computation: ({ editing, descriptor }, previous) => {
      if (editing === null || descriptor === null) return null;
      const typed =
        previous?.value && previous.source.editing === editing
          ? previous.value.getRawValue()
          : null;

      return buildFormGroup(
        descriptor,
        typed ?? untracked(() => drawingValues(editing === 'new' ? null : editing)),
      );
    },
  });

  // ——— the floor form ———

  protected readonly editingFloor = signal<StockFloorRow | 'new' | null>(null);
  protected readonly floorDescriptor = computed(() => {
    const options = this.facade.options();
    const editing = this.editingFloor();
    if (options === null || editing === null) return null;

    return floorForm(options, editing === 'new' ? null : editing);
  });
  protected readonly floorFormGroup = linkedSignal<
    { editing: StockFloorRow | 'new' | null; descriptor: FormDescriptor | null },
    DescriptorFormGroup | null
  >({
    source: () => ({ editing: this.editingFloor(), descriptor: this.floorDescriptor() }),
    computation: ({ editing, descriptor }, previous) => {
      if (editing === null || descriptor === null) return null;
      const typed =
        previous?.value && previous.source.editing === editing
          ? previous.value.getRawValue()
          : null;

      return buildFormGroup(
        descriptor,
        typed ??
          untracked(() =>
            floorValues(
              editing === 'new' ? null : editing,
              this.facade.options() ?? {
                establishments: [],
              },
            ),
          ),
      );
    },
  });

  constructor() {
    // The one wire from the form back to the plan. Both a typed measurement and a dragged one arrive here, so the
    // drawing cannot be right for one and stale for the other.
    effect((onCleanup) => {
      const group = this.drawingFormGroup();
      if (group === null) {
        this.formValues.set(null);

        return;
      }
      this.formValues.set(group.getRawValue() as FormValues);
      const watching = group.valueChanges.subscribe(() =>
        this.formValues.set(group.getRawValue() as FormValues),
      );
      onCleanup(() => watching.unsubscribe());
    });
  }

  /** A rectangle being drawn for a location not yet chosen: enough of a row for the plan to show it taking shape. */
  private pendingRow(): StockDrawingRow {
    const locationId = String(this.formValues()?.['locationId'] ?? '');
    const location = this.facade.locations().find((one) => one.id === locationId);

    return {
      id: PENDING_ID,
      floorId: this.floor()?.id ?? '',
      locationId,
      locationCode: location?.code ?? '—',
      locationName: location?.name ?? '',
      locationKind: location?.kind ?? 'zone',
      x: '0',
      y: '0',
      width: '0',
      depth: '0',
      rotation: 0,
      height: '0',
    };
  }

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;

    // A rectangle, the floor it is on and the location it is drawn for each change what this screen shows.
    this.live.reloadOn(
      ['venue_area', 'venue_spot', 'stock_location'],
      async () => {
        await this.facade.loadPlanContext(companyId);
        await this.facade.reloadDrawings(companyId);
      },
      this.destroyRef,
    );
    await this.facade.loadPlanContext(companyId);
    await this.showFloor(this.floor()?.id ?? null);
  }

  protected async showFloor(floorId: string | null): Promise<void> {
    const companyId = this.company()?.id;
    this.chosenFloorId.set(floorId);
    this.selectedId.set(null);
    this.editing.set(null);
    this.drag = null;
    if (companyId && floorId) await this.facade.loadDrawings(companyId, floorId);
  }

  protected select(drawing: StockDrawingRow): void {
    this.selectedId.set(drawing.id);
  }

  protected draw(target: StockDrawingRow | 'new'): void {
    this.facade.clearError();
    this.editingFloor.set(null);
    if (target === 'new') this.selectedId.set(null);
    else this.select(target);
    this.editing.set(target);
  }

  protected cancelDrawing(): void {
    this.editing.set(null);
    this.drag = null;
    this.facade.clearError();
  }

  // ——— the plan as a drawing surface ———

  /**
   * Who may draw. The phone READS the map and does not draw it (docs/SPEC.md § 7, decision 8, widened 2026-09-21):
   * a five-inch screen cannot carry a handle a fingertip can hit, so the handles are not there at all rather than
   * there and unusable. Everything they do stays reachable through the form, on every window.
   */
  private readonly windowClass = inject(WINDOW_CLASS);
  protected readonly mayDraw = computed(() => this.mayWrite() && this.windowClass() !== 'compact');

  /** The handles around the rectangle being worked on, in its OWN unturned corners: the group turns them with it. */
  protected readonly handles = computed(() => {
    const shape = this.shapes().find((one) => one.drawing.id === this.selectedId());
    if (shape === undefined || !this.mayDraw()) return [];

    return PLAN_HANDLES.map((handle) => {
      const at = handleAt(shape.rect, handle);

      return { handle, x: at.x, y: at.y, key: `${handle.hx}:${handle.hy}` };
    });
  });

  /** The radius a handle is drawn at, in METRES, so it stays the same size on the screen whatever the floor's size. */
  protected readonly handleRadius = computed(() => this.frame().width / 110);

  /**
   * How many fields hold something other than what was last saved. Without it a drag is illegible: the rectangle
   * moves, and nothing on the screen says the plan is now unsaved.
   */
  protected readonly unsaved = computed(() => {
    const editing = this.editing();
    const values = this.formValues();
    if (editing === null || values === null) return 0;

    return dirtyCount(values, drawingValues(editing === 'new' ? null : editing));
  });

  /** Whether the next gesture on bare floor traces a box. Armed by the tool beside the plan, for one box. */
  protected readonly tracing = signal(false);

  protected armTrace(): void {
    this.tracing.update((armed) => !armed);
  }

  private drag: Drag | null = null;

  protected grab(event: PointerEvent, drawing: StockDrawingRow, handle: PlanHandle | null): void {
    if (!this.mayDraw() || event.button !== 0) return;

    // Choosing it first is what the handles are drawn around, and what the gesture below then works on.
    this.select(drawing);
    const shape = this.shapes().find((one) => one.drawing.id === drawing.id);
    if (shape === undefined) return;

    this.hold(event, drawing.id, shape.rect, handle);
  }

  /**
   * A box traced on bare floor — **decision 1**. It is armed first, by the tool beside the plan, and never by the
   * press alone: a sheet that drew a rack on any press would need `touch-action: none` across a full-width block to
   * beat the page's own scrolling, which traps a finger trying to scroll past the plan. Armed, it takes that
   * behaviour for one box and gives it straight back.
   */
  protected trace(event: PointerEvent): void {
    if (!this.tracing()) return;

    // The press bubbles here from whatever it landed on, so a press on a rectangle is that rectangle's, not a trace.
    if ((event.target as Element).tagName.toLowerCase() !== 'svg') return;

    this.hold(event, null, null, null);
  }

  /** What every gesture starts with: the frame and the viewport frozen, and the metre the pointer began on. */
  private hold(
    event: PointerEvent,
    drawingId: string | null,
    origin: PlanRectangle | null,
    handle: PlanHandle | null,
  ): void {
    if (!this.mayDraw() || event.button !== 0) return;
    const surface = (event.target as Element).closest('svg');
    if (surface === null) return;

    const box = surface.getBoundingClientRect();
    const frame = this.frame();
    this.drag = {
      pointerId: event.pointerId,
      handle,
      drawingId,
      origin,
      from: pointerMetres({ x: event.clientX, y: event.clientY }, frame, box),
      frame,
      box,
      before: {},
      past: false,
      startedAt: { x: event.clientX, y: event.clientY },
    };
    event.preventDefault();
  }

  protected drags(event: PointerEvent): void {
    const drag = this.drag;
    if (drag === null || drag.pointerId !== event.pointerId) return;

    if (!drag.past) {
      const far =
        Math.abs(event.clientX - drag.startedAt.x) + Math.abs(event.clientY - drag.startedAt.y);
      if (far < DRAG_THRESHOLD) return;

      // Only now is it a drag: the editor opens, and what it held is kept so Échap can put it back.
      drag.past = true;
      // A box being traced opens the new-rectangle editor; one just traced has that editor open already and is not
      // among what the API answered, so looking it up there would abandon the gesture before its form was kept —
      // and Échap would then give a freshly traced rectangle nothing back.
      if (drag.drawingId === null) this.draw('new');
      else if (drag.drawingId !== PENDING_ID) {
        const drawing = this.facade.drawings().find((one) => one.id === drag.drawingId);
        if (drawing === undefined) return;
        if (this.editing() === null || this.editing() === 'new') this.draw(drawing);
      }
      drag.before = (this.drawingFormGroup()?.getRawValue() ?? {}) as FormValues;
    }

    const to = pointerMetres({ x: event.clientX, y: event.clientY }, drag.frame, drag.box);
    const origin = drag.origin;
    if (origin === null) {
      this.drawingFormGroup()?.patchValue(footprintValues(tracedTo(drag.from, to)));

      return;
    }

    const next =
      drag.handle === null ? movedTo(origin, drag.from, to) : resizedTo(origin, drag.handle, to);

    this.drawingFormGroup()?.patchValue(rectValues(next));
  }

  protected drops(event: PointerEvent): void {
    if (this.drag?.pointerId !== event.pointerId) return;

    // One box per arming: the sheet goes back to being something a finger can scroll past.
    if (this.drag.origin === null && this.drag.past) this.tracing.set(false);
    this.drag = null;
  }

  /** Échap gives the rectangle back exactly what the form held before the gesture — never a guess at it. */
  protected abandon(): void {
    const drag = this.drag;
    this.drag = null;
    this.tracing.set(false);
    if (drag?.past) this.drawingFormGroup()?.patchValue(drag.before);
  }

  protected async saveDrawing(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const floorId = this.floor()?.id;
    const editing = this.editing();
    if (!companyId || !floorId || editing === null || this.busy()) return;

    const accepted = await this.facade.draw(
      companyId,
      floorId,
      drawingInput(values),
      editing === 'new' ? null : editing.id,
    );
    if (accepted) {
      this.editing.set(null);
      this.drag = null;
      this.feedback.success('inventory.plan.drawing_saved');
    }
  }

  /** The rectangle goes; the location it was drawn for keeps its code, its tree and its stock. */
  protected async erase(drawing: StockDrawingRow): Promise<void> {
    const companyId = this.company()?.id;
    const floorId = this.floor()?.id;
    if (!companyId || !floorId || this.busy()) return;

    const erased = await this.facade.eraseDrawing(companyId, floorId, drawing.id);
    if (erased) {
      if (this.selectedId() === drawing.id) this.selectedId.set(null);
      this.editing.set(null);
      this.feedback.success('inventory.plan.drawing_erased');
    }
  }

  protected openFloor(target: StockFloorRow | 'new'): void {
    this.facade.clearError();
    this.editing.set(null);
    this.editingFloor.set(target);
  }

  protected cancelFloor(): void {
    this.editingFloor.set(null);
    this.facade.clearError();
  }

  protected async saveFloor(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editingFloor();
    if (!companyId || editing === null || this.busy()) return;

    const row = editing === 'new' ? null : editing;
    const accepted =
      row === null
        ? await this.facade.createFloor(companyId, floorInput(values, null))
        : await this.facade.reviseFloor(companyId, row.id, floorInput(values, row));
    if (accepted) {
      this.editingFloor.set(null);
      this.feedback.success('inventory.plan.floor_saved');
    }
  }

  protected async removeFloor(row: StockFloorRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;

    const removed = await this.facade.deleteFloor(companyId, row.id);
    if (removed) {
      this.editingFloor.set(null);
      await this.showFloor(this.floors()[0]?.id ?? null);
      this.feedback.success('inventory.plan.floor_removed');
    }
  }
}

/** A measurement out of a form control, as a number: what was typed, empty or half-typed reading as nothing yet. */
function metres(value: unknown): number {
  return Number(value ?? 0) || 0;
}

/** One rectangle as the SVG needs it: where it turns about, and where its code is written inside it. */
function shapeOf(drawing: StockDrawingRow, rect: PlanRectangle): PlanShape {
  return {
    drawing,
    rect,
    centreX: rect.x + rect.width / 2,
    centreY: rect.y + rect.depth / 2,
    // Written inside the rectangle and turned with it, so a label never floats off its own rack.
    labelX: rect.x + 0.15,
    labelY: rect.y + Math.min(0.45, rect.depth * 0.7),
  };
}

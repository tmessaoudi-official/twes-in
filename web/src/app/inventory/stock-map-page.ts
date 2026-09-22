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
import {
  STRUCTURE_KINDS,
  type StockDrawingRow,
  type StockFloorRow,
  type StockPlanShape,
  type StockStructureRow,
  type StockStructureShape,
  type StructureKind,
} from './inventory-types';
import {
  drawingForm,
  drawingInput,
  drawingValues,
  floorForm,
  floorInput,
  floorValues,
  footprintValues,
  nextCodes,
  planRectangles,
  rectValues,
  structureForm,
  structureInput,
  structureRectangles,
  structureValues,
} from './stock-map-forms';
import {
  centredIn,
  fitView,
  handleAt,
  movedTo,
  pannedBy,
  PLAN_HANDLES,
  PLAN_ZOOM_MIN,
  PLAN_ZOOM_STEP,
  planFrame,
  pointerMetres,
  shownFrame,
  zoomedAt,
  repeatedFrom,
  resizedTo,
  tracedTo,
  type PlanBox,
  type PlanFrame,
  type PlanHandle,
  type PlanPoint,
  type PlanRectangle,
  type PlanView,
  type PlanWay,
  PLAN_WAYS,
} from './stock-map-geometry';
import { SettingsFacade } from '../shared/settings/settings-facade';
import {
  PLAN_LABEL_MODES,
  PRESENTATION,
  type PlanLabelMode,
} from '../shared/settings/settings-registry';
import { LABEL_FONT, STRUCTURE_LABEL_FONT, fitLabel, planLabel } from './stock-map-labels';
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
  /**
   * Which layer the gesture is on. A wall moves exactly as a rack does — same press, same handles — but it is a
   * different form and a different editor, so the gesture has to say which (docs/SPEC.md § 7, 2026-09-22).
   */
  on: 'drawing' | 'structure';
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
  /** Where the label is written: inside the rectangle, turned with it. */
  labelX: number;
  labelY: number;
  /** What is written there: the code, the name, or both, as the reader asked, cut to the rectangle's own width. */
  label: string;
  /** The whole of it, carried as the drawn element's title so cutting the label hides nothing from the reader. */
  labelTitle: string;
}

/**
 * One piece of the building as the plan draws it, and where its name is written when it has one — which most
 * walls do not (docs/SPEC.md § 7, 2026-09-22). A piece still carries no CODE: nothing here holds goods.
 */
interface StructureShape {
  piece: StockStructureRow;
  rect: PlanRectangle;
  centreX: number;
  centreY: number;
  /** Where the name is written: inside the footprint, turned with it, exactly as a rack's code is. */
  labelX: number;
  labelY: number;
  /** A piece has no code, so this is its name — under every choice, or the building vanishes under "codes". */
  label: string;
  labelTitle: string;
}

/** One row of the layers panel: what it is, and how many things are on it right now. */
interface PlanLayer {
  id: string;
  count: number;
}

/** A piece being drawn and not yet saved — enough of a row for the plan to show it taking shape. */
const PENDING_PIECE: StockStructureRow = {
  id: PENDING_ID,
  floorId: '',
  kind: 'wall',
  name: '',
  x: '0',
  y: '0',
  width: '0',
  depth: '0',
  rotation: 0,
  height: '0',
};

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
  private readonly settings = inject(SettingsFacade);
  protected readonly tabs = INVENTORY_TABS;

  /**
   * What the plan writes on its rectangles. A preference and not a moment: a store reads its own numbering every
   * day and should not re-choose it on every visit (docs/SPEC.md § 7, 2026-09-22).
   */
  protected readonly labelMode = this.settings.value(PRESENTATION.planLabels);
  protected readonly labelModes = PLAN_LABEL_MODES;
  /** Bound rather than written in the template, so what is drawn and what is measured cannot drift apart. */
  protected readonly labelFont = LABEL_FONT;
  protected readonly structureLabelFont = STRUCTURE_LABEL_FONT;

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

      return shapeOf(drawing, shown, this.labelMode());
    });

    if (editing === 'new' && preview !== null)
      shapes.push(shapeOf(this.pendingRow(), preview, this.labelMode()));

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

  /**
   * What part of the floor is being looked at: nothing while the whole of it is shown, which is where the plan
   * starts and what the "fit" button goes back to (docs/SPEC.md § 7, 2026-09-22).
   */
  protected readonly view = signal<PlanView | null>(null);

  /** The frame actually drawn — the floor's own until someone comes nearer. */
  protected readonly viewed = computed(() => {
    const fit = this.frame();
    const view = this.view();

    return view === null ? fit : shownFrame(fit, view);
  });

  protected readonly zoom = computed(() => this.view()?.scale ?? PLAN_ZOOM_MIN);
  protected readonly zoomLabel = computed(() => `${Math.round(this.zoom() * 100)} %`);

  protected readonly viewBox = computed(() => {
    const frame = this.viewed();

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
                planShapes: [],
                structureShapes: [],
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

    // The same wire for the building's form. Two effects rather than one over both, so a piece being typed and a
    // rectangle being dragged cannot end up reading each other's values.
    effect((onCleanup) => {
      const group = this.structureFormGroup();
      if (group === null) {
        this.structureValuesNow.set(null);

        return;
      }
      this.structureValuesNow.set(group.getRawValue() as FormValues);
      const watching = group.valueChanges.subscribe(() =>
        this.structureValuesNow.set(group.getRawValue() as FormValues),
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
      ['venue_area', 'venue_spot', 'venue_structure', 'stock_location'],
      async () => {
        await this.facade.loadPlanContext(companyId);
        await this.facade.reloadDrawings(companyId);
        await this.facade.reloadStructures(companyId);
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
    this.editingStructure.set(null);
    this.selectedStructureId.set(null);
    if (companyId && floorId) {
      await this.facade.loadDrawings(companyId, floorId);
      await this.facade.loadStructures(companyId, floorId);
    }
  }

  /** One selection across both layers: choosing a rack lets go of the wall that was chosen (finding G). */
  protected select(drawing: StockDrawingRow): void {
    this.selectedId.set(drawing.id);
    this.selectedStructureId.set(null);
    this.editingStructure.set(null);
  }

  protected draw(target: StockDrawingRow | 'new'): void {
    this.facade.clearError();
    this.editingFloor.set(null);
    this.editingStructure.set(null);
    this.selectedStructureId.set(null);
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

  /** The same handles around a piece of the building, which now moves and resizes exactly as a rack does. */
  protected readonly structureHandles = computed(() => {
    const shape = this.builtShapes().find((one) => one.piece.id === this.selectedStructureId());
    if (shape === undefined || !this.mayDraw()) return [];

    return PLAN_HANDLES.map((handle) => {
      const at = handleAt(shape.rect, handle);

      return { handle, x: at.x, y: at.y, key: `${handle.hx}:${handle.hy}` };
    });
  });

  /** The radius a handle is drawn at, in METRES, so it stays the same size on the screen whatever the floor's size. */
  protected readonly handleRadius = computed(() => this.viewed().width / 110);

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

  /**
   * The palette's ready-made shapes, at the sizes THIS company set for them — nothing here invents a measurement,
   * so a company whose settings answer nothing gets no palette rather than a made-up one. Only where drawing is
   * possible at all: a palette that poses rectangles is not a reading tool (decision 8).
   */
  protected readonly palette = computed<readonly StockPlanShape[]>(() =>
    this.mayDraw() ? (this.facade.options()?.planShapes ?? []) : [],
  );

  /**
   * A shape posed in the middle of the floor, chosen, its form open. It is PLACED and not saved: the rectangle is
   * then dragged where it goes and only what differs from the ready-made size is corrected, which is the whole
   * reason the palette exists. Its height stays whatever the form offers — a size is an emprise, not a measurement
   * of how tall a rack stands.
   */
  protected pose(shape: StockPlanShape): void {
    this.draw('new');
    this.drawingFormGroup()?.patchValue(
      footprintValues(centredIn(this.viewed(), shape.width, shape.depth)),
    );
  }

  /** Whether the next gesture on bare floor traces a box. Armed by the tool beside the plan, for one box. */
  protected readonly tracing = signal(false);

  protected armTrace(): void {
    this.tracing.update((armed) => !armed);
  }

  // ——— repeating a rectangle down an aisle ———

  /**
   * Repeating what is selected (docs/SPEC.md row 83; the approved canvas's Repeat board). Nobody draws sixteen
   * identical racks one at a time, and a copy is a STOCK LOCATION as well as a rectangle — so this panel says so in
   * those words before it creates anything.
   */
  protected readonly repeating = signal(false);
  protected readonly repeatCount = signal(1);
  protected readonly repeatSpacing = signal('');
  protected readonly repeatWay = signal<PlanWay>('down');
  protected readonly repeatCode = signal('');
  protected readonly ways = PLAN_WAYS;

  /**
   * Opens the panel with what a person would most likely have typed anyway: the next code after the one being
   * copied, and the company's own aisle width as the free floor between two racks. Both are theirs to change — the
   * point is that the common repeat needs no typing at all.
   */
  protected openRepeat(drawing: StockDrawingRow): void {
    this.facade.clearError();
    this.select(drawing);
    this.editing.set(null);
    this.repeatCount.set(1);
    this.repeatWay.set('down');
    this.repeatSpacing.set(
      String(this.palette().find((one) => one.shape === 'aisle')?.depth ?? 1.2),
    );
    this.repeatCode.set(nextCodes(drawing.locationCode, 2)[1] ?? '');
    this.repeating.set(true);
  }

  protected cancelRepeat(): void {
    this.repeating.set(false);
    this.facade.clearError();
  }

  /**
   * The spacing as typed, a comma taken as a point — and `null` when what is there is not a measurement at all.
   *
   * `null` and not zero: `metres()` answers 0 for anything it cannot read, which would show a preview of racks back
   * to back and let `1,2,3` be sent as `"0"`. The panel's whole premise is that an impossible repeat is refused
   * before it is sent, and a spacing that did not parse is exactly that.
   */
  private readonly repeatGap = computed<number | null>(() => {
    const typed = this.repeatSpacing().trim();
    if (!/^\d+([.,]\d{1,3})?$/.test(typed)) return null;

    return Number(typed.replace(',', '.'));
  });

  /** Where each copy would land — the same arithmetic the API will run, so the preview is not an approximation. */
  protected readonly repeatPreview = computed<PlanRectangle[]>(() => {
    const shape = this.shapes().find((one) => one.drawing.id === this.selectedId());
    const gap = this.repeatGap();
    if (shape === undefined || gap === null || !this.repeating()) return [];

    return repeatedFrom(shape.rect, this.repeatCount(), gap, this.repeatWay());
  });

  /** What the copies will be called, listed before anything is made. */
  protected readonly repeatCodes = computed(() => nextCodes(this.repeatCode(), this.repeatCount()));

  /**
   * Whether the repeat can be made at all — "une copie qui sortirait du sol est refusée AVANT, pas après" (the
   * approved canvas). The button is disabled and the reason is on screen, rather than a refusal arriving from the
   * API after the person has pressed it.
   */
  protected readonly repeatOffFloor = computed(
    () =>
      this.repeatPreview().length > 0 &&
      this.repeatPreview().some((rect) => rect.x < 0 || rect.y < 0),
  );

  protected readonly repeatReady = computed(
    () =>
      this.repeatCount() >= 1 &&
      this.repeatCount() <= this.REPEAT_LIMIT &&
      this.repeatPreview().length === this.repeatCount() &&
      this.repeatCodes().length === this.repeatCount() &&
      !this.repeatOffFloor() &&
      this.repeatGap() !== null,
  );

  /** The three boxes write into signals, so the preview follows what is typed rather than what was last saved. */
  protected setRepeatCount(event: Event): void {
    this.repeatCount.set(Math.trunc(metres((event.target as HTMLInputElement).value)));
  }

  protected setRepeatSpacing(event: Event): void {
    this.repeatSpacing.set((event.target as HTMLInputElement).value);
  }

  protected setRepeatCode(event: Event): void {
    this.repeatCode.set((event.target as HTMLInputElement).value);
  }

  /** What the count box may hold, which is the API's own cap — a typo guard, not a rule about warehouses. */
  protected readonly REPEAT_LIMIT = 50;

  protected async saveRepeat(): Promise<void> {
    const companyId = this.company()?.id;
    const floorId = this.floor()?.id;
    const drawingId = this.selectedId();
    if (!companyId || !floorId || drawingId === null || !this.repeatReady() || this.busy()) return;

    const made = await this.facade.repeatDrawing(companyId, floorId, drawingId, {
      count: this.repeatCount(),
      spacing: String(this.repeatGap() ?? 0),
      way: this.repeatWay(),
      firstCode: this.repeatCode().trim(),
    });
    if (made) {
      this.repeating.set(false);
      this.feedback.success('inventory.plan.repeated');
    }
  }

  private drag: Drag | null = null;

  protected grab(event: PointerEvent, drawing: StockDrawingRow, handle: PlanHandle | null): void {
    if (!this.mayDraw() || event.button !== 0) return;

    // Choosing it first is what the handles are drawn around, and what the gesture below then works on.
    this.select(drawing);
    const shape = this.shapes().find((one) => one.drawing.id === drawing.id);
    if (shape === undefined) return;

    this.hold(event, 'drawing', drawing.id, shape.rect, handle);
  }

  /**
   * The same press on a piece of the building. Until now a wall only accepted a click that opened a form, while
   * wearing a pointer cursor that promised a gesture it did not have (docs/SPEC.md § 7, 2026-09-22).
   */
  protected grabStructure(
    event: PointerEvent,
    piece: StockStructureRow,
    handle: PlanHandle | null,
  ): void {
    if (!this.mayDraw() || event.button !== 0) return;

    this.openStructure(piece);
    const shape = this.builtShapes().find((one) => one.piece.id === piece.id);
    if (shape === undefined) return;

    this.hold(event, 'structure', piece.id, shape.rect, handle);
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

    this.hold(event, 'drawing', null, null, null);
  }

  // ——— coming nearer, and moving what is shown ———

  private pan: {
    pointerId: number;
    from: PlanPoint;
    view: PlanView;
    frame: PlanFrame;
    box: PlanBox;
  } | null = null;

  /**
   * A press on bare floor. Armed to trace it draws a box, as it always did; otherwise it takes hold of the floor
   * and moves it — but only once someone has come nearer, since showing the whole of it leaves nowhere to move to.
   *
   * A finger cannot do this: without `touch-action: none` the browser claims the drag for its own scrolling, and
   * laying that across a full-width plan traps a finger trying to scroll past it. The tablet's pinch is its own
   * piece of work.
   */
  protected press(event: PointerEvent): void {
    if (this.tracing()) {
      this.trace(event);

      return;
    }
    const view = this.view();
    if (view === null || event.button !== 0) return;
    if ((event.target as Element).tagName.toLowerCase() !== 'svg') return;

    const surface = event.currentTarget as SVGSVGElement;
    const box = surface.getBoundingClientRect();
    const frame = this.viewed();
    this.pan = {
      pointerId: event.pointerId,
      from: pointerMetres({ x: event.clientX, y: event.clientY }, frame, box),
      view,
      frame,
      box,
    };
    event.preventDefault();
  }

  /**
   * The wheel comes nearer where the pointer is, leaving that spot where it was. Coming nearer the middle of the
   * window instead walks whatever one was aiming at off the screen, which is what makes a plan feel broken.
   */
  protected wheel(event: WheelEvent): void {
    const surface = event.currentTarget as SVGSVGElement | null;
    if (surface === null) return;
    event.preventDefault();

    const fit = this.frame();
    const view = this.view() ?? fitView(fit);
    const at = pointerMetres(
      { x: event.clientX, y: event.clientY },
      this.viewed(),
      surface.getBoundingClientRect(),
    );

    this.look(
      zoomedAt(
        fit,
        view,
        view.scale * (event.deltaY < 0 ? PLAN_ZOOM_STEP : 1 / PLAN_ZOOM_STEP),
        at,
      ),
    );
  }

  /** The buttons come nearer the middle of what is already shown, which is what a button can mean. */
  protected zoomBy(factor: number): void {
    const fit = this.frame();
    const view = this.view() ?? fitView(fit);
    const frame = this.viewed();

    this.look(
      zoomedAt(fit, view, view.scale * factor, {
        x: frame.x + frame.width / 2,
        y: frame.y + frame.height / 2,
      }),
    );
  }

  /** The whole floor again. */
  protected fitAll(): void {
    this.view.set(null);
  }

  /** Showing the whole floor is held as "no view at all", so the two ways of saying it cannot disagree. */
  private look(view: PlanView): void {
    this.view.set(view.scale <= PLAN_ZOOM_MIN ? null : view);
  }

  /** What every gesture starts with: the frame and the viewport frozen, and the metre the pointer began on. */
  private hold(
    event: PointerEvent,
    on: 'drawing' | 'structure',
    drawingId: string | null,
    origin: PlanRectangle | null,
    handle: PlanHandle | null,
  ): void {
    if (!this.mayDraw() || event.button !== 0) return;
    const surface = (event.target as Element).closest('svg');
    if (surface === null) return;

    const box = surface.getBoundingClientRect();
    const frame = this.viewed();
    this.drag = {
      pointerId: event.pointerId,
      on,
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
    const pan = this.pan;
    if (pan !== null && pan.pointerId === event.pointerId) {
      const to = pointerMetres({ x: event.clientX, y: event.clientY }, pan.frame, pan.box);
      this.view.set(pannedBy(pan.view, to.x - pan.from.x, to.y - pan.from.y));

      return;
    }

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
      if (drag.on === 'drawing') {
        if (drag.drawingId === null) this.draw('new');
        else if (drag.drawingId !== PENDING_ID) {
          const drawing = this.facade.drawings().find((one) => one.id === drag.drawingId);
          if (drawing === undefined) return;
          if (this.editing() === null || this.editing() === 'new') this.draw(drawing);
        }
      }
      drag.before = (this.editedGroup(drag)?.getRawValue() ?? {}) as FormValues;
    }

    const to = pointerMetres({ x: event.clientX, y: event.clientY }, drag.frame, drag.box);
    const origin = drag.origin;
    if (origin === null) {
      this.editedGroup(drag)?.patchValue(footprintValues(tracedTo(drag.from, to)));

      return;
    }

    const next =
      drag.handle === null ? movedTo(origin, drag.from, to) : resizedTo(origin, drag.handle, to);

    this.editedGroup(drag)?.patchValue(rectValues(next));
  }

  /** The form the gesture is writing into — the building's or the stock's, never both. */
  private editedGroup(drag: Drag): DescriptorFormGroup | null {
    return drag.on === 'structure' ? this.structureFormGroup() : this.drawingFormGroup();
  }

  protected drops(event: PointerEvent): void {
    if (this.pan?.pointerId === event.pointerId) this.pan = null;
    if (this.drag?.pointerId !== event.pointerId) return;

    // One box per arming: the sheet goes back to being something a finger can scroll past.
    if (this.drag.origin === null && this.drag.past) this.tracing.set(false);
    this.drag = null;
  }

  /** Échap gives the rectangle back exactly what the form held before the gesture — never a guess at it. */
  protected abandon(): void {
    const drag = this.drag;
    this.drag = null;
    this.pan = null;
    this.tracing.set(false);
    if (drag?.past) this.editedGroup(drag)?.patchValue(drag.before);
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

  // ——— the structure layer: the building, which is none of the stock ———

  /**
   * The structure layer (docs/SPEC.md row 83; the approved canvas's Structure board). A wall, a door, a post and a
   * dock are drawn on the same floor in the same metres, and NOTHING here is a place: a wall holds no goods, so it
   * appears in no stock list, no import and no movement's location picker. That is why it is a layer apart.
   *
   * It is drawn UNDER the stock, and its layer locks, so a wall is not picked up while a rack is being moved.
   */
  protected readonly structureTools = computed<readonly StockStructureShape[]>(() =>
    this.mayDraw() ? (this.facade.options()?.structureShapes ?? []) : [],
  );

  protected readonly editingStructure = signal<StockStructureRow | 'new' | null>(null);
  protected readonly selectedStructureId = signal<string | null>(null);
  /** The piece of the building the handles are drawn around, which is what those handles then work on. */
  protected readonly selectedStructure = computed(
    () => this.facade.structures().find((piece) => piece.id === this.selectedStructureId()) ?? null,
  );

  /** What the open structure form holds, so a piece follows the keyboard exactly as a rectangle of stock does. */
  private readonly structureValuesNow = signal<FormValues | null>(null);

  protected readonly structureDescriptor = computed(() =>
    this.editingStructure() === null ? null : structureForm(),
  );

  protected readonly structureFormGroup = linkedSignal<
    { editing: StockStructureRow | 'new' | null; descriptor: FormDescriptor | null },
    DescriptorFormGroup | null
  >({
    source: () => ({ editing: this.editingStructure(), descriptor: this.structureDescriptor() }),
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
            structureValues(editing === 'new' ? null : editing, this.structureTools()),
          ),
      );
    },
  });

  /**
   * The building as the plan draws it — what was saved, with the piece being edited shown as it now stands, the
   * same one mechanism the stock rectangles use.
   */
  protected readonly builtShapes = computed<StructureShape[]>(() => {
    const editing = this.editingStructure();
    const values = this.structureValuesNow();
    const preview: PlanRectangle | null =
      editing === null || values === null
        ? null
        : {
            x: metres(values['x']),
            y: metres(values['y']),
            width: metres(values['width']),
            depth: metres(values['depth']),
            rotation: metres(values['rotation']),
            height: metres(values['height']),
          };
    const kindNow = String(values?.['kind'] ?? 'wall');

    const shapes = this.facade.structures().map((piece) => {
      const [saved] = structureRectangles([piece]);
      const shown =
        preview !== null && editing !== null && editing !== 'new' && editing.id === piece.id
          ? preview
          : (saved ?? { x: 0, y: 0, width: 0, depth: 0, rotation: 0, height: 0 });

      return builtOf(
        editing !== null && editing !== 'new' && editing.id === piece.id
          ? { ...piece, kind: kindOf(kindNow) }
          : piece,
        shown,
        this.labelMode(),
      );
    });

    if (editing === 'new' && preview !== null) {
      shapes.push(builtOf({ ...PENDING_PIECE, kind: kindOf(kindNow) }, preview, this.labelMode()));
    }

    return shapes;
  });

  /** A form is open — a rectangle of stock's or a piece of the building's — and so it stands where the tools were. */
  protected readonly inspecting = computed(
    () => this.drawingFormGroup() !== null || this.structureFormGroup() !== null,
  );

  /**
   * Whether the board must say a save is owed: anything new is, and anything saved is once a field differs from
   * what the API holds. The form beside the board carries the count; the board carries the fact, where the eye is.
   */
  protected readonly owesSave = computed(() => {
    if (this.editing() === 'new' || this.editingStructure() === 'new') return true;
    if (this.unsaved() > 0) return true;
    const piece = this.editingStructure();
    const values = this.structureValuesNow();
    if (piece === null || piece === 'new' || values === null) return false;

    return dirtyCount(values, structureValues(piece, this.structureTools())) > 0;
  });

  /**
   * The layers panel of the board. Counts are derived and never stored: a layer showing a number nobody maintains
   * is a number that goes wrong the first time something is erased somewhere else.
   *
   * The board draws a fourth layer, "Fond de plan". It is deliberately absent until the floor image exists to put
   * on it — a control that cannot do anything is the same can-never-fire shape as a preference between two views
   * while only one is built.
   */
  protected readonly layers = computed<PlanLayer[]>(() => {
    const drawings = this.facade.drawings();

    return [
      { id: 'structure', count: this.facade.structures().length },
      { id: 'racks', count: drawings.filter((one) => one.locationKind === 'rack').length },
      { id: 'zones', count: drawings.filter((one) => one.locationKind !== 'rack').length },
    ];
  });

  private readonly hidden = signal<readonly string[]>([]);
  private readonly locked = signal<readonly string[]>([]);

  protected shown(layer: string): boolean {
    return !this.hidden().includes(layer);
  }

  /**
   * A locked layer is still drawn and still read; it simply stops answering the pointer, which is the board's own
   * promise — "elle se verrouille d'un clic pour ne plus l'attraper en déplaçant un rayonnage". Its form is
   * untouched, so everything on a locked layer stays reachable by keyboard.
   */
  protected isLocked(layer: string): boolean {
    return this.locked().includes(layer);
  }

  /** Writing it down is the whole point: the next visit opens on the numbering this store actually reads. */
  protected chooseLabels(mode: PlanLabelMode): void {
    this.settings.set(PRESENTATION.planLabels, mode);
  }

  protected toggleShown(layer: string): void {
    this.hidden.update((hidden) =>
      hidden.includes(layer) ? hidden.filter((one) => one !== layer) : [...hidden, layer],
    );
  }

  protected toggleLocked(layer: string): void {
    this.locked.update((locked) =>
      locked.includes(layer) ? locked.filter((one) => one !== layer) : [...locked, layer],
    );
  }

  /**
   * A tool posed in the middle of the floor at this company's own measurements, chosen, its form open — PLACED and
   * not saved, exactly as the stock palette poses a rack. Its height comes with it, which a stock shape has none
   * of: a wall's height is the building's, not a fact about that one wall.
   */
  protected poseStructure(tool: StockStructureShape): void {
    this.openStructure('new');
    this.structureFormGroup()?.patchValue({
      ...structureValues(null, this.structureTools(), tool.kind),
      ...footprintValues(centredIn(this.viewed(), tool.width, tool.depth)),
    });
  }

  /**
   * The building's editor. It checked nothing before 2026-09-22, so anyone who could read the plan could open a
   * form that moves a wall — the one place on this page where a permission was simply not asked for.
   */
  protected openStructure(target: StockStructureRow | 'new'): void {
    if (!this.mayDraw()) return;
    // The posed piece is drawn by the saved pieces' template, so a press on it arrives here too. It is already the
    // piece being edited: opening its zeroed placeholder as a saved row reset the form and made it vanish (§ 7,
    // 2026-09-22, finding A).
    if (target !== 'new' && target.id === PENDING_ID) return;
    this.facade.clearError();
    this.editing.set(null);
    this.editingFloor.set(null);
    this.repeating.set(false);
    this.selectedId.set(null);
    this.editingStructure.set(target);
    this.selectedStructureId.set(target === 'new' ? null : target.id);
  }

  protected cancelStructure(): void {
    this.editingStructure.set(null);
    this.facade.clearError();
  }

  protected async saveStructure(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const floorId = this.floor()?.id;
    const editing = this.editingStructure();
    if (!companyId || !floorId || editing === null || this.busy()) return;

    const accepted = await this.facade.buildStructure(
      companyId,
      floorId,
      structureInput(values),
      editing === 'new' ? null : editing.id,
    );
    if (accepted) {
      this.editingStructure.set(null);
      this.feedback.success('inventory.plan.structure_saved');
    }
  }

  protected async eraseStructure(piece: StockStructureRow): Promise<void> {
    const companyId = this.company()?.id;
    const floorId = this.floor()?.id;
    if (!companyId || !floorId || this.busy()) return;

    const erased = await this.facade.eraseStructure(companyId, floorId, piece.id);
    if (erased) {
      if (this.selectedStructureId() === piece.id) this.selectedStructureId.set(null);
      this.editingStructure.set(null);
      this.feedback.success('inventory.plan.structure_erased');
    }
  }

  protected openFloor(target: StockFloorRow | 'new'): void {
    this.facade.clearError();
    this.editing.set(null);
    this.editingStructure.set(null);
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

/** A kind out of a form control, falling back to the one piece of building that is always drawable. */
function kindOf(value: string): StructureKind {
  return STRUCTURE_KINDS.find((kind) => kind === value) ?? 'wall';
}

/** One piece of the building as the SVG needs it: where it turns about, what it is, and where its name goes. */
function builtOf(
  piece: StockStructureRow,
  rect: PlanRectangle,
  mode: PlanLabelMode,
): StructureShape {
  return {
    piece,
    rect,
    centreX: rect.x + rect.width / 2,
    centreY: rect.y + rect.depth / 2,
    // A wall is a thin rectangle, so its name sits above the line rather than inside a 0,20 m band nothing fits in.
    labelX: rect.x + 0.15,
    labelY: rect.depth < 0.6 ? rect.y - 0.12 : rect.y + Math.min(0.45, rect.depth * 0.7),
    // A piece carries no code, so `planLabel` answers its name whatever the mode — that is the point of passing it.
    label: fitLabel(planLabel('', piece.name, mode), rect.width, STRUCTURE_LABEL_FONT),
    labelTitle: planLabel('', piece.name, mode),
  };
}

/** One rectangle as the SVG needs it: where it turns about, and where its code is written inside it. */
function shapeOf(drawing: StockDrawingRow, rect: PlanRectangle, mode: PlanLabelMode): PlanShape {
  return {
    drawing,
    rect,
    centreX: rect.x + rect.width / 2,
    centreY: rect.y + rect.depth / 2,
    // Written inside the rectangle and turned with it, so a label never floats off its own rack.
    labelX: rect.x + 0.15,
    labelY: rect.y + Math.min(0.45, rect.depth * 0.7),
    label: fitLabel(
      planLabel(drawing.locationCode, drawing.locationName, mode),
      rect.width,
      LABEL_FONT,
    ),
    labelTitle: planLabel(drawing.locationCode, drawing.locationName, mode),
  };
}

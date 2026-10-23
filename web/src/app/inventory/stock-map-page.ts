// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  ElementRef,
  inject,
  Injector,
  linkedSignal,
  OnInit,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { DOCUMENT, NgTemplateOutlet } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatTooltip } from '@angular/material/tooltip';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { map } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { listRecord } from '../shared/form/list-record';
import { RecordChanged } from '../shared/form/record-changed';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { AmountPipe, MeasurePipe } from '../shared/i18n/format-pipes';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { Label } from '../shared/a11y/label';
import { InventoryFacade } from './inventory-facade';
import { INVENTORY_TABS } from './inventory-nav';
import { PageTabs } from '../shared/ui/page-tabs';
import { Immersive } from '../shared/ui/immersive';
import { ScreenActions } from '../shared/actions/screen-actions';
import type { ActionConfirm, ScreenAction } from '../shared/actions/screen-action';
import { runAction } from '../shared/actions/run-action';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { MatDialog } from '@angular/material/dialog';
import { isTypingTarget } from '../shared/actions/shortcuts';
import {
  type StockDrawingRow,
  type StockFloorRow,
  type StockLevelRow,
  type StockLocationKind,
  type StockPlanShape,
  type StockStructureRow,
  type StockStructureShape,
  type WhereaboutRow,
} from './inventory-types';
import { sumQuantities } from './stock-quantities';
import {
  drawingForm,
  drawingValues,
  newDrawingValues,
  newRect,
  placeOfValues,
  typedRect,
  floorForm,
  floorInput,
  floorValues,
  kindOfShape,
  type NewPlaceProposal,
  nextCodes,
  planRectangles,
  proposedCode,
  rectValues,
  structureForm,
  structureFacts,
  structureRectangles,
  structureValues,
} from './stock-map-forms';
import {
  centredIn,
  fitView,
  handleAt,
  gripRadius,
  handlesThatFit,
  movedTo,
  pannedBy,
  PLAN_ZOOM_MIN,
  PLAN_ZOOM_STEP,
  planFrame,
  pointerMetres,
  scaleBarMetres,
  shownFrame,
  steppedBy,
  zoomedAt,
  repeatedFrom,
  resizedTo,
  shiftedGroup,
  snapMetres,
  touchesBox,
  tracedTo,
  turnedGroup,
  type PlanBox,
  type PlanFrame,
  type PlanHandle,
  type PlanPoint,
  type PlanRectangle,
  type PlanView,
  type PlanWay,
  PLAN_WAYS,
  SNAP_DEGREES,
  snapAngle,
} from './stock-map-geometry';
import {
  addDrawing,
  addStructure,
  changeCount,
  emptyDraft,
  erase as eraseFromDraft,
  inverseOf,
  isNewKey,
  parseDraft,
  rectOf,
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
  type DraftPlace,
  type PlanDraft,
  type PlanStep,
  type PlanStepSources,
} from './stock-plan-draft';
import { PLAN_DRAFT_STORAGE, planDraftKey } from './stock-plan-storage';
import { SettingsFacade } from '../shared/settings/settings-facade';
import {
  PLAN_LABEL_MODES,
  PRESENTATION,
  type PlanLabelMode,
  type StockMapView,
} from '../shared/settings/settings-registry';
import { StockMapVolume } from './stock-map-volume';
import { StockMapFirstSteps } from './stock-map-first-steps';
import { PlaceContents } from './place-contents';
import { type MovementAsked, PlaceMovement } from './place-movement';
import {
  LABEL_FONT,
  LABEL_PIXELS,
  STRUCTURE_LABEL_FONT,
  STRUCTURE_LABEL_PIXELS,
  fitLabel,
  labelFont,
  planLabel,
} from './stock-map-labels';
import { COARSE_POINTER } from '../shared/ui/pointer';
import { WINDOW_CLASS } from '../shared/ui/window-class';

/** Above this many metres a floor's grid is drawn every five metres rather than every one. */
const GRID_FINE_LIMIT = 60;

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
/** How far an arrow key moves a shape in Aménager: the plan's own grid, a quarter metre. */
const ARROW_STEP_METRES = 0.25;
const DRAG_THRESHOLD = 4;

/** A box dragged on bare floor to choose every rectangle it touches; `extend` keeps what was chosen (Shift). */
interface Lasso {
  pointerId: number;
  from: PlanPoint;
  frame: PlanFrame;
  box: PlanBox;
  extend: boolean;
  startedAt: PlanPoint;
}

/** A gesture in progress. It holds what the rectangle was, never what it is becoming: every move recomputes. */
interface Drag {
  pointerId: number;
  /**
   * Which layer the gesture is on. A wall moves exactly as a rack does — same press, same handles — but it is a
   * different form and a different editor, so the gesture has to say which (docs/SPEC.md § 7, 2026-09-22).
   */
  on: 'drawing' | 'structure' | 'floor';
  /** The handle being pulled, or `null` when the rectangle itself is being moved. */
  handle: PlanHandle | null;
  /**
   * The rectangle being worked on, and what it was when the gesture began. A box being traced has neither until it has
   * travelled, then its new key and still no origin; the floor's edge has no id and the floor as its origin.
   */
  drawingId: string | null;
  origin: PlanRectangle | null;
  from: PlanPoint;
  /** Frozen at the start: both must stay still, or the metres under the pointer change as it moves. */
  frame: PlanFrame;
  box: PlanBox;
  /** The floor's draft before the gesture, so Échap can put it back exactly. */
  before: PlanDraft;
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
  /** How the label is turned to run along a long narrow shape, or `null` where it reads across it. */
  labelTurn: string | null;
  /** The label's size in metres, readable on this board and held inside the rectangle. */
  font: number;
}

/** How much deeper than wide a shape must be before its label runs along its length rather than across it. */
const ALONG_RATIO = 1.5;

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
  font: number;
}

/** The board's two modes: Consulter reads and never moves anything, Aménager draws (the brief's § 5.1). */
type PlanMode = 'read' | 'arrange';

/** The three ways of looking at a floor; only the plan exists yet, the other two say « Bientôt ». */
const PLAN_VIEWS = ['plan', 'facade', 'volume'] as const;

/** How far one press of a pan arrow or an arrow key moves what is shown: a fifth of it, so the eye keeps its place. */
const PAN_STEP = 0.2;

/** The grips on the floor's own edges in Aménager: the far side, the far end, and the corner that pulls both. */
const FLOOR_HANDLES: readonly PlanHandle[] = [
  { hx: 1, hy: 0.5 },
  { hx: 0.5, hy: 1 },
  { hx: 1, hy: 1 },
];

/** The smallest a floor is pulled to by its edge, in metres. */
const FLOOR_LEAST = 1;

/** One row of the layers panel: what it is, and how many things are on it right now. */
interface PlanLayer {
  id: string;
  count: number;
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
  imports: [
    PageTabs,
    MatButtonModule,
    MatCardModule,
    MatIconModule,
    MatMenuModule,
    MatTooltip,
    NgTemplateOutlet,
    RouterLink,
    AmountPipe,
    MeasurePipe,
    TranslatePipe,
    DescriptorForm,
    Label,
    PickField,
    StockMapVolume,
    StockMapFirstSteps,
    PlaceContents,
    PlaceMovement,
    RecordChanged,
  ],
  templateUrl: './stock-map-page.html',
  styleUrl: './stock-map-page.css',
  changeDetection: ChangeDetectionStrategy.OnPush,
  // Échap is the page's, not the plan's alone: it also leaves the full screen, whichever view is shown.
  host: { '(document:keydown.escape)': 'escape($event)' },
})
export class StockMapPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(InventoryFacade);
  private readonly feedback = inject(Feedback);
  private readonly dialog = inject(MatDialog);
  private readonly auth = inject(AuthFacade);
  private readonly settings = inject(SettingsFacade);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly unsavedChanges = inject(UnsavedChanges);
  protected readonly tabs = INVENTORY_TABS;

  /** Whether planned views are shown, marked « Bientôt », or left out: the person's own choice. */
  protected readonly showComing = inject(ThemeFacade).showComing;
  protected readonly views = PLAN_VIEWS;

  /**
   * The mode is view state, kept in the URL so a link or a reload lands where the person was, and never a setting:
   * the page always opens reading. A mode switch only shows a mode that is plainly shown, which is why whoever
   * cannot arrange gets the « Lecture » chip in its place.
   */
  private readonly modeAsked = toSignal(
    this.route.queryParamMap.pipe(map((params) => params.get('mode'))),
    { initialValue: null },
  );
  protected readonly arranging = computed(() => this.mayDraw() && this.modeAsked() === 'arrange');

  /**
   * What the plan writes on its rectangles. A preference and not a moment: a store reads its own numbering every
   * day and should not re-choose it on every visit (docs/SPEC.md § 7, 2026-09-22).
   */
  protected readonly labelMode = this.settings.value(PRESENTATION.planLabels);
  /**
   * The volume is for looking, in either mode: in Aménager it shows the plan being arranged, so a rack moved on the
   * plan is seen standing where it now is before anything is saved. Gestures stay the plan's. The choice is kept.
   */
  protected readonly viewChosen = this.settings.value(PRESENTATION.stockMapView);
  protected readonly volumeShown = computed(() => this.viewChosen() === 'volume');
  protected readonly labelModes = PLAN_LABEL_MODES;
  /**
   * The drawing's size on screen, measured, so that a metre's length in pixels is known: what the scale bar is drawn
   * at and what the labels must reach to be read. Null until measured, and in jsdom, which has no ResizeObserver.
   */
  private readonly surfaceElement = viewChild<ElementRef<SVGSVGElement>>('surface');
  private readonly surface = signal<{ width: number; height: number } | null>(null);

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

  // ——— the floor's draft: what is arranged and not saved yet ———

  private readonly planStorage = inject(PLAN_DRAFT_STORAGE);
  /** Each floor's draft, by floor: a person may arrange two floors before saving either. */
  private readonly drafts = signal<ReadonlyMap<string, PlanDraft>>(new Map());
  protected readonly draft = computed(() => {
    const floorId = this.floor()?.id ?? '';

    return this.drafts().get(floorId) ?? emptyDraft(floorId);
  });
  /** How many shapes of the floor shown are changed and not saved. */
  protected readonly planChanges = computed(() => changeCount(this.draft()));
  /** A draft of this floor found in the browser, offered until it is taken up or let go. */
  protected readonly offered = signal<PlanDraft | null>(null);
  protected readonly keptCount = computed(() => {
    const kept = this.offered();

    return kept === null ? 0 : changeCount(kept);
  });

  /**
   * The rectangles of stock as the board shows them: in Aménager the draft over what was saved, in Consulter what was
   * saved, since a reader asks where things ARE.
   */
  protected readonly drawnRows = computed(() =>
    this.arranging()
      ? shownDrawings(this.draft(), this.facade.drawings(), this.facade.locations())
      : this.facade.drawings(),
  );
  protected readonly builtRows = computed(() =>
    this.arranging()
      ? shownStructures(this.draft(), this.facade.structures())
      : this.facade.structures(),
  );

  /** What is drawn on the floor being looked at, as the draft has it. */
  protected readonly shapes = computed<PlanShape[]>(() => {
    const pixels = this.pixelsPerMetre();

    return this.drawnRows().map((drawing) => {
      const [rect] = planRectangles([drawing]);

      return shapeOf(drawing, rect, this.labelMode(), pixels);
    });
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
  /** The floor's own size in metres, when it was measured (docs/SPEC.md § 7, 2026-09-22, findings E and H). */
  protected readonly floorSize = computed<{ width: number; depth: number } | null>(() =>
    shownFloorSize(this.arranging() ? this.draft() : emptyDraft(''), this.floor()),
  );

  /** The frame as it stood when the floor's edge was taken hold of: the board stays still under the pulling hand. */
  private readonly heldFrame = signal<PlanFrame | null>(null);

  /**
   * The box the board frames. A measured floor is framed by itself, so saving a rack no longer moves everything on
   * the board; anything drawn beyond its edge still widens it, since a plan must never hide what is on it. A floor
   * never measured is framed by everything drawn on it, the building included — a wall outside the racks' extent
   * used to fall off the board.
   */
  protected readonly frame = computed<PlanFrame>(() => {
    const held = this.heldFrame();
    if (held !== null) return held;
    const drawn = [
      ...planRectangles(this.facade.drawings()),
      ...structureRectangles(this.facade.structures()),
    ];
    const size = this.floorSize();
    if (size === null) return planFrame(drawn, PLAN_PADDING + EDIT_ROOM);

    return planFrame(
      [...drawn, { x: 0, y: 0, width: size.width, depth: size.depth, rotation: 0, height: 0 }],
      PLAN_PADDING,
    );
  });

  /** A metre grid over a measured floor, five metres on a large one so the lines stay lines and not a tint. */
  protected readonly gridLines = computed(() => {
    const size = this.floorSize();
    if (size === null) return { step: 0, xs: [] as number[], ys: [] as number[] };
    const step = Math.max(size.width, size.depth) > GRID_FINE_LIMIT ? 5 : 1;
    const along = (length: number): number[] =>
      Array.from(
        { length: Math.max(0, Math.ceil(length / step) - 1) },
        (_, index) => (index + 1) * step,
      );

    return { step, xs: along(size.width), ys: along(size.depth) };
  });

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
  /** The floor's proportions as framed, which the drawing takes below a wide screen. */
  protected readonly planAspect = computed(() => {
    const frame = this.frame();

    return frame.height > 0 ? frame.width / frame.height : 1;
  });

  /** How many pixels a metre takes as shown: the frame is fitted whole by its tighter side, as `xMidYMid meet` does. */
  protected readonly pixelsPerMetre = computed(() => {
    const surface = this.surface();
    const shown = this.viewed();
    if (surface === null || shown.width <= 0 || shown.height <= 0) return null;

    return Math.min(surface.width / shown.width, surface.height / shown.height);
  });
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

  /**
   * Every rectangle chosen. One is THE selection, with its handles, its form and its panel; several are a group, which
   * has none of those, only what a group can be told (§ 7, 2026-10-09 17:45).
   */
  protected readonly chosen = signal<ReadonlySet<string>>(new Set());
  protected readonly selectedId = computed(() => {
    const chosen = this.chosen();

    return chosen.size === 1 ? ([...chosen][0] ?? null) : null;
  });
  /** The group as it stands on this floor: a rectangle erased elsewhere drops out of it by itself. */
  protected readonly chosenDrawings = computed(() =>
    this.drawnRows().filter((drawing) => this.chosen().has(drawing.id)),
  );
  protected readonly chosenCodes = computed(() =>
    this.chosenDrawings()
      .map((drawing) => drawing.locationCode)
      .join(', '),
  );
  protected readonly selected = computed(
    () => this.drawnRows().find((drawing) => drawing.id === this.selectedId()) ?? null,
  );
  /**
   * The place whose contents the panel shows: the chosen rectangle's location, in Consulter only — Aménager's panel is
   * the shape's form and its actions, and a read there would only slow a gesture down.
   */
  protected readonly contentsLocationId = computed(() =>
    this.arranging() ? null : (this.selected()?.locationId ?? null),
  );

  // ——— the search: where goods are, lit on the plan ———

  /**
   * What the map is asked to find, kept in the URL like the mode so it can be sent as a link and survives a reload: one
   * product searched (`product`), or a delivery note's lines (`products`, with the note's `note` number to name them).
   */
  private readonly askedIds = toSignal(
    this.route.queryParamMap.pipe(
      map((params) => {
        const many = params.get('products');
        const one = params.get('product');
        if (many !== null) return many.split(',').filter((id) => id !== '');
        return one === null ? [] : [one];
      }),
    ),
    { initialValue: [] as string[] },
  );
  protected readonly noteAsked = toSignal(
    this.route.queryParamMap.pipe(map((params) => params.get('note'))),
    { initialValue: null },
  );
  /** Searching is reading: Aménager lights nothing, so no highlight ever hides a shape being moved. */
  private readonly searchedIds = computed(() => (this.arranging() ? [] : this.askedIds()), {
    equal: sameIds,
  });
  /** The answer for what is asked now, never a late one for what was asked before. */
  protected readonly search = computed(() => {
    const search = this.facade.whereabouts();
    return search !== null && sameIds(search.productIds, this.searchedIds()) ? search : null;
  });
  /** One product searched from the field or a scan, which the banner and « Où elle se trouve » speak of. */
  protected readonly found = computed(() => {
    const search = this.search();
    return search !== null && search.productIds.length === 1 && this.noteAsked() === null
      ? (search.products[0] ?? null)
      : null;
  });
  /** A delivery note's lines, looked for together: what the note's banner and its line-by-line list speak of. */
  protected readonly note = computed(() => (this.found() === null ? this.search() : null));
  protected readonly searchPick = computed<PickOption | null>(() => {
    const found = this.found();
    return found === null
      ? null
      : { id: found.productId, code: found.productReference, name: found.productName };
  });
  /** Only goods whose stock is kept can be anywhere, so the picker offers those alone. */
  protected readonly searchProducts = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    return (await this.facade.pickProducts(companyId, { words })).map((product) => ({
      id: product.id,
      code: product.reference,
      name: product.name,
    }));
  };
  /**
   * The drawn places on the floor shown holding anything looked for, by the location drawn: what one product holds
   * there, or how many of a note's lines are found there.
   */
  protected readonly hits = computed(() => {
    const floorId = this.floor()?.id ?? null;
    const hits = new Map<string, { quantity: string | null; count: number }>();
    const products = this.search()?.products ?? [];
    for (const product of products) {
      for (const row of product.rows) {
        if (row.floorId === null || row.floorId !== floorId || row.locationId === null) continue;
        const hit = hits.get(row.locationId);
        hits.set(row.locationId, {
          quantity: products.length === 1 ? row.quantity : null,
          count: (hit?.count ?? 0) + 1,
        });
      }
    }
    return hits;
  });
  /** The same places, as the volume lights them. */
  protected readonly litIds = computed(() => new Set(this.hits().keys()));
  /** How many drawn places hold something looked for on each floor, which the list of floors says. */
  protected readonly hitsByFloor = computed(() => {
    const places = new Map<string, Set<string>>();
    for (const product of this.search()?.products ?? []) {
      for (const row of product.rows) {
        if (row.floorId === null || row.locationId === null) continue;
        places.set(row.floorId, (places.get(row.floorId) ?? new Set()).add(row.locationId));
      }
    }
    return new Map([...places].map(([floorId, held]) => [floorId, held.size]));
  });
  /** The drawn places of some rows, the floor shown first and then the floors as they are listed. */
  private placesOf(rows: readonly WhereaboutRow[]) {
    const floors = this.floors();
    const shown = this.floor()?.id ?? null;
    const rank = (floorId: string | null): number =>
      floorId === shown ? -1 : floors.findIndex((one) => one.id === floorId);
    return rows
      .filter((row) => row.floorId !== null)
      .map((row) => ({
        row,
        floorName: floors.find((one) => one.id === row.floorId)?.name ?? '',
        here: row.floorId === shown,
      }))
      .sort((one, other) => rank(one.row.floorId) - rank(other.row.floorId));
  }
  /** Every drawn place holding the product searched. */
  protected readonly foundPlaces = computed(() => this.placesOf(this.found()?.rows ?? []));
  protected readonly elsewhere = computed(
    () => this.foundPlaces().filter((place) => !place.here).length,
  );
  /** What lies where nothing is drawn: said, never dropped, since the map is only as true as what it can show. */
  protected readonly undrawn = computed(
    () => this.found()?.rows.find((row) => row.floorId === null) ?? null,
  );
  protected readonly undrawnCodes = computed(() => codesOf(this.undrawn()));
  protected readonly floorTotal = computed(() =>
    sumQuantities(
      (this.found()?.rows ?? [])
        .filter((row) => row.floorId !== null && row.floorId === this.floor()?.id)
        .map((row) => row.quantity),
    ),
  );
  /** A note's lines one by one: where each is, what lies undrawn of it, and the lines found nowhere at all. */
  protected readonly noteGroups = computed(() =>
    (this.note()?.products ?? []).map((product) => {
      const undrawn = product.rows.find((row) => row.floorId === null) ?? null;
      return {
        product,
        places: this.placesOf(product.rows),
        undrawn,
        undrawnCodes: codesOf(undrawn),
      };
    }),
  );
  protected readonly noteNowhere = computed(
    () => this.noteGroups().filter((group) => group.product.rows.length === 0).length,
  );
  /**
   * How many products each drawn place holds, in a disc at its lower corner sized like its label, so a person sees
   * where goods are before pressing anything. Only in Consulter and outside a search: arranging, the figures would sit
   * under a hand moving the shapes, and a search's own figures say what matters then.
   */
  protected readonly countBadges = computed(() => {
    const badges = new Map<
      string,
      { x: number; y: number; r: number; font: number; count: string; total: number }
    >();
    if (this.arranging() || this.search() !== null) return badges;
    const holdings = this.facade.holdings();
    for (const shape of this.shapes()) {
      const total = holdings.get(shape.drawing.locationId) ?? 0;
      if (total <= 0) continue;
      const { rect } = shape;
      const r = Math.min(shape.font * 0.85, Math.min(rect.width, rect.depth) / 2);
      const inset = Math.min(0.1, r / 3);
      const count = total > 99 ? '99+' : String(total);
      badges.set(shape.drawing.id, {
        x: rect.width > 2 * (r + inset) ? rect.x + rect.width - r - inset : rect.x + rect.width / 2,
        y: rect.depth > 2 * (r + inset) ? rect.y + rect.depth - r - inset : rect.y + rect.depth / 2,
        r,
        font: r * [1.15, 1.15, 0.95, 0.75][count.length],
        count,
        total,
      });
    }
    return badges;
  });

  /**
   * Each lit place's figure, in a pill over its far corner sized to what it will say: one product's quantity — its
   * digits, the decimals the unit counts and a space every three digits — or how many of a note's lines are there.
   * Its type follows the view, so a whole floor seen at once still reads its figures, and never goes below the labels'
   * own size.
   */
  protected readonly hitBadges = computed(() => {
    const decimals = this.found()?.unitDecimals ?? 0;
    // The board fits the view by its longer side, so that side is what sets how large a metre is drawn.
    const shown = this.viewed();
    const font = Math.max(LABEL_FONT, Math.max(shown.width, shown.height) / 40);
    const height = font * 1.5;
    const badges = new Map<
      string,
      {
        x: number;
        y: number;
        width: number;
        height: number;
        font: number;
        quantity: string | null;
        count: number;
        decimals: number;
      }
    >();
    for (const shape of this.shapes()) {
      const hit = this.hits().get(shape.drawing.locationId);
      if (hit === undefined) continue;
      const characters =
        hit.quantity === null ? String(hit.count).length + 7 : figureLength(hit.quantity, decimals);
      const width = Math.max(height, (characters * 0.62 + 0.8) * font);
      badges.set(shape.drawing.id, {
        x: shape.rect.x + shape.rect.width - width / 2,
        // Above the shape and not on it, where a long rack writes its own label.
        y: shape.rect.y - height * 1.15,
        width,
        height,
        font,
        quantity: hit.quantity,
        count: hit.count,
        decimals,
      });
    }
    return badges;
  });
  protected readonly mayReadProducts = computed(() => this.auth.hasPermission('product.read'));
  /** The search answered for a floor already chosen: a floor holding none of it is opened once, never again. */
  private jumpedFor: string | null = null;

  /** The chosen rectangle as it is drawn, for the size the panel says. */
  protected readonly selectedShape = computed(
    () => this.shapes().find((shape) => shape.drawing.id === this.selectedId()) ?? null,
  );

  // ——— the rectangle form: it reads the draft and writes into it, never to the API ———

  /** The rectangle of stock whose form is open: its id, or its key while it is not saved yet. */
  protected readonly editing = signal<string | null>(null);
  /** That rectangle as the board shows it. */
  protected readonly editingRow = computed(
    () => this.drawnRows().find((drawing) => drawing.id === this.editing()) ?? null,
  );
  /**
   * Which place each rectangle on the board is drawn for, compared by that alone: a drag moves a rectangle and never
   * changes what it is drawn for, so the form's choice of places is not rebuilt under a moving hand.
   */
  private readonly drawnFor = computed(() => this.drawnRows(), { equal: sameBindings });
  protected readonly drawingDescriptor = computed(() => {
    const id = this.editing();
    if (id === null) return null;
    const rows = this.drawnFor();
    const row = rows.find((one) => one.id === id);
    if (row === undefined) return null;

    return drawingForm(
      this.facade.locations(),
      rows.filter((one) => one.id !== id),
      isNewKey(id) ? null : row,
      this.floor()?.establishmentId ?? null,
    );
  });
  protected readonly drawingFormGroup = linkedSignal<
    { editing: string | null; descriptor: FormDescriptor | null },
    DescriptorFormGroup | null
  >({
    source: () => ({ editing: this.editing(), descriptor: this.drawingDescriptor() }),
    computation: ({ editing, descriptor }, previous) => {
      if (editing === null || descriptor === null) return null;
      const typed =
        previous?.value && previous.source.editing === editing
          ? previous.value.getRawValue()
          : null;

      return buildFormGroup(descriptor, typed ?? untracked(() => this.drawingValuesOf(editing)));
    },
  });

  /** What a rectangle's form opens with: the rectangle as the draft has it, and what it is drawn for. */
  private drawingValuesOf(id: string): FormValues {
    const row = this.drawnRows().find((one) => one.id === id) ?? null;
    if (!isNewKey(id) || row === null) return drawingValues(row);
    const place: DraftPlace =
      this.draft().newDrawings.find((one) => one.key === id)?.place ?? this.newPlace('rack');

    return newDrawingValues(rectOf(row), place);
  }

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

  /** The saved version the open floor form stands on, and what another person's save changed in it. */
  protected readonly floorSync = listRecord({
    kind: 'venue_area',
    editing: this.editingFloor,
    rows: this.facade.floors,
    form: this.floorFormGroup,
    reload: async () => {
      const companyId = this.company()?.id;
      if (companyId) await this.facade.loadPlanContext(companyId);
    },
    values: (row) =>
      floorValues(
        row,
        this.facade.options() ?? { establishments: [], planShapes: [], structureShapes: [] },
      ),
  });

  constructor() {
    inject(ScreenActions).declare(this.fullAction);
    // The shell steps behind the map while it fills the window, and comes back whichever way it is left.
    effect(() => this.immersive.set(this.full()));
    const browserLeft = (): void => this.browserLeft();
    this.document.addEventListener('fullscreenchange', browserLeft);
    this.destroyRef.onDestroy(() => {
      this.document.removeEventListener('fullscreenchange', browserLeft);
      this.giveBrowserBack();
      this.immersive.set(false);
    });
    // A place chosen on the plan or in the 3D brings its line in « Dessiné sur cet étage » into view, as the list and
    // the plans show one selection (docs/SPEC.md § 7, 2026-10-09 23:19). scrollIntoView is optional: jsdom has none.
    // Only a new choice scrolls: `selected` is read again on every move and reload of the same place, and scrolling
    // then moved the page under whatever the person had open, a dropdown's panel following its field off the screen.
    const host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    const injector = inject(Injector);
    effect(() => {
      if (this.selectedId() === null) return;
      const code = untracked(this.selected)?.locationCode;
      if (code === undefined) return;
      afterNextRender(
        () =>
          host
            .querySelector(`[data-testid="stock-drawing-${CSS.escape(code)}"]`)
            ?.scrollIntoView?.({ block: 'nearest' }),
        { injector },
      );
    });
    effect((onCleanup) => {
      const element = this.surfaceElement()?.nativeElement;
      if (element === undefined || typeof ResizeObserver === 'undefined') {
        this.surface.set(null);
        return;
      }
      const observer = new ResizeObserver(([entry]) => {
        const { width, height } = entry.contentRect;
        this.surface.set(width > 0 && height > 0 ? { width, height } : null);
      });
      observer.observe(element);
      onCleanup(() => observer.disconnect());
    });
    // The one wire from a form to the draft. A typed measurement lands there as a dragged one does; a gesture writes
    // the draft and then the open form's fields, quietly, so it does not come back round through here.
    effect((onCleanup) => {
      const group = this.drawingFormGroup();
      const id = untracked(() => this.editing());
      if (group === null || id === null) return;
      const watching = group.valueChanges.subscribe(() =>
        this.fromDrawingForm(id, group.getRawValue() as FormValues),
      );
      onCleanup(() => watching.unsubscribe());
    });
    effect((onCleanup) => {
      const group = this.structureFormGroup();
      const id = untracked(() => this.editingStructure());
      if (group === null || id === null) return;
      const watching = group.valueChanges.subscribe(() =>
        this.fromStructureForm(id, group.getRawValue() as FormValues),
      );
      onCleanup(() => watching.unsubscribe());
    });

    effect(() => {
      const companyId = this.company()?.id;
      const productIds = this.searchedIds();
      if (companyId) untracked(() => void this.facade.loadWhereabouts(companyId, productIds));
    });

    // A search opens the floor holding the goods when the one shown holds none of them, once per search: a person who
    // then opens another floor is not sent back to it.
    effect(() => {
      const search = this.search();
      const floors = this.floors();
      if (search === null) {
        this.jumpedFor = null;
        return;
      }
      const key = search.productIds.join(',');
      if (floors.length === 0 || key === this.jumpedFor) return;
      this.jumpedFor = key;
      const rows = search.products.flatMap((product) => product.rows);
      const shown = untracked(() => this.floor()?.id);
      if (rows.some((row) => row.floorId === shown)) return;
      const holding = floors.find((one) => rows.some((row) => row.floorId === one.id));
      if (holding) untracked(() => void this.showFloor(holding.id));
    });

    // A scan in Consulter is a search; while arranging it is left to the product card, as anywhere else.
    inject(ScanBus).handle((scan) => this.scanned(scan));

    // What a form holds and the API does not is unsaved work, here as on a record page: leaving the page asks first.
    this.unsavedChanges.declare(this.pendingChanges);

    // Out of Aménager by any road — the switch, the browser's Back, a window narrowed to a phone — nothing stays
    // half-drawn behind a board that no longer shows the tools to finish it.
    effect(() => {
      if (!this.arranging()) untracked(() => this.putToolsDown());
    });
  }

  /** How many shapes are changed and not saved, on every floor arranged: what leaving the page asks about. */
  private readonly pendingChanges = computed(() =>
    [...this.drafts().values()].reduce((sum, draft) => sum + changeCount(draft), 0),
  );

  /**
   * Consulter or Aménager. Leaving Aménager loses nothing: the draft stays, in the page and in this browser, and is
   * on the board again on the way back, so the switch asks nothing.
   */
  protected async chooseMode(mode: PlanMode): Promise<void> {
    if (mode === (this.arranging() ? 'arrange' : 'read')) return;

    if (mode === 'read') this.putToolsDown();
    await this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { mode: mode === 'arrange' ? 'arrange' : null },
      queryParamsHandling: 'merge',
    });
  }

  /** A search from the field or a scan replaces whatever was looked for, a delivery note's lines included. */
  protected async searchFor(option: PickOption | null): Promise<void> {
    await this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { product: option?.id ?? null, products: null, note: null },
      queryParamsHandling: 'merge',
    });
  }

  /** From a card of « Où elle se trouve » to the place itself: its floor opened, the place chosen. */
  protected async goTo(row: WhereaboutRow): Promise<void> {
    if (row.floorId === null || row.locationId === null) return;
    if (row.floorId !== this.floor()?.id) await this.showFloor(row.floorId);
    const drawing = this.drawnRows().find((one) => one.locationId === row.locationId);
    if (drawing) this.select(drawing);
  }

  /** A code scanned while reading the plan: the stocked product it names, looked for, its whole code offered first. */
  private async scanned(scan: Scan): Promise<ScanOutcome> {
    const companyId = this.company()?.id;
    if (!companyId || this.arranging()) return { kind: 'unclaimed' };
    const [product] = await this.facade.pickProducts(companyId, { words: scan.code });
    if (product === undefined) return { kind: 'unclaimed' };
    await this.searchFor({ id: product.id, code: product.reference, name: product.name });
    return { kind: 'done', key: 'inventory.plan.search.scanned', params: { name: product.name } };
  }

  /** A company with no floor yet starts its plan where floors are made. */
  protected async startFirstFloor(): Promise<void> {
    await this.chooseMode('arrange');
    this.openFloor('new');
  }

  private putToolsDown(): void {
    this.editing.set(null);
    this.editingStructure.set(null);
    this.editingFloor.set(null);
    this.repeating.set(false);
    this.tracing.set(false);
    this.drag = null;
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
        await this.facade.reloadWhereabouts(companyId);
      },
      this.destroyRef,
    );
    // What a place holds changes with every movement anywhere, and with the homes a product file sets; the chosen place's
    // own lines are read again by « Ce qu'il y a ici » itself.
    this.live.reloadOn(
      ['stock', 'product', 'product_home_location', 'delivery_note', 'invoice'],
      async () => {
        await this.facade.reloadHoldings(companyId);
        await this.facade.reloadWhereabouts(companyId);
      },
      this.destroyRef,
    );
    await this.facade.loadPlanContext(companyId);
    await this.showFloor(this.floor()?.id ?? null);
  }

  // ——— the draft: each floor's, written in one place and kept in this browser ———

  /** Every change to the floor shown goes through here; nothing changes while a kept draft waits for an answer. */
  private change(edit: (draft: PlanDraft) => PlanDraft): void {
    const floorId = this.floor()?.id;
    if (!floorId || this.offered() !== null) return;
    this.setDraft(floorId, edit(this.drafts().get(floorId) ?? emptyDraft(floorId)));
  }

  private setDraft(floorId: string, draft: PlanDraft): void {
    this.drafts.update((drafts) => new Map(drafts).set(floorId, draft));
    this.keep(floorId, draft);
  }

  /** The draft written to this browser, or its key removed once there is nothing in it. */
  private keep(floorId: string, draft: PlanDraft): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    const key = planDraftKey(companyId, floorId);
    try {
      if (changeCount(draft) === 0) this.planStorage.removeItem(key);
      else this.planStorage.setItem(key, JSON.stringify(draft));
    } catch {
      // Site data blocked or full: the draft still lives in the page, it is only not kept past it.
    }
  }

  /** A draft of this floor kept in this browser from an earlier visit, offered to take up or let go. */
  private offerKept(floorId: string | null): void {
    this.offered.set(null);
    const companyId = this.company()?.id;
    if (
      !companyId ||
      !floorId ||
      changeCount(this.drafts().get(floorId) ?? emptyDraft(floorId)) > 0
    )
      return;
    let text: string | null;
    try {
      text = this.planStorage.getItem(planDraftKey(companyId, floorId));
    } catch {
      return;
    }
    const kept = parseDraft(text, floorId);
    if (kept !== null && changeCount(kept) > 0) this.offered.set(kept);
  }

  /** « Reprendre »: the kept draft on the board, but what someone else changed since, which is theirs now. */
  protected resume(): void {
    const kept = this.offered();
    if (kept === null || kept.floorId !== this.floor()?.id) return;
    this.offered.set(null);
    const { draft, dropped } = rebased(
      kept,
      this.facade.drawings(),
      this.facade.structures(),
      this.facade.locations(),
      this.floor(),
    );
    this.setDraft(kept.floorId, draft);
    if (dropped > 0) this.feedback.failure('inventory.plan.draft.dropped', { count: dropped });
    else this.feedback.success('inventory.plan.draft.resumed', { count: changeCount(draft) });
  }

  /** « Abandonner »: the kept draft let go, and gone from this browser too. */
  protected abandon(): void {
    const kept = this.offered();
    if (kept === null) return;
    this.offered.set(null);
    this.keep(kept.floorId, emptyDraft(kept.floorId));
    this.feedback.success('inventory.plan.draft.abandoned');
  }

  private savedDrawing(id: string): StockDrawingRow | null {
    return this.facade.drawings().find((one) => one.id === id) ?? null;
  }

  private savedStructure(id: string): StockStructureRow | null {
    return this.facade.structures().find((one) => one.id === id) ?? null;
  }

  /** What a rectangle's form now says, into the draft: where it stands and what it is drawn for. */
  private fromDrawingForm(id: string, values: FormValues): void {
    const rect = typedRect(values);
    if (isNewKey(id)) {
      this.change((draft) =>
        placeFor(placeDrawing(draft, id, rect, null), id, placeOfValues(values)),
      );

      return;
    }
    const locationId = typeof values['locationId'] === 'string' ? values['locationId'] : '';
    this.change((draft) =>
      placeDrawing(
        draft,
        id,
        rect,
        this.savedDrawing(id),
        locationId === '' ? undefined : locationId,
      ),
    );
  }

  private fromStructureForm(id: string, values: FormValues): void {
    const facts = structureFacts(values);
    this.change((draft) =>
      placeStructure(draft, id, typedRect(values), this.savedStructure(id), facts),
    );
  }

  /** A shape moved by a gesture or a key: into the draft, and into its form when that form is open, quietly. */
  private place(on: 'drawing' | 'structure', id: string, rect: PlanRectangle): void {
    if (on === 'structure') {
      this.change((draft) => placeStructure(draft, id, rect, this.savedStructure(id)));
      if (this.editingStructure() === id) {
        this.structureFormGroup()?.patchValue(rectValues(rect), { emitEvent: false });
      }

      return;
    }
    this.change((draft) => placeDrawing(draft, id, rect, this.savedDrawing(id)));
    if (this.editing() === id) {
      this.drawingFormGroup()?.patchValue(rectValues(rect), { emitEvent: false });
    }
  }

  /** Shapes off the board, into the draft: nothing is sent before « Enregistrer ». */
  private eraseShapes(ids: readonly string[]): void {
    if (ids.length === 0) return;
    this.change((draft) =>
      eraseFromDraft(draft, ids, this.facade.drawings(), this.facade.structures()),
    );
    const gone = new Set(ids);
    if (gone.has(this.editing() ?? '')) this.editing.set(null);
    if (gone.has(this.editingStructure() ?? '')) this.editingStructure.set(null);
    if (gone.has(this.selectedStructureId() ?? '')) this.selectedStructureId.set(null);
    this.chosen.set(new Set([...this.chosen()].filter((id) => !gone.has(id))));
    this.repeating.set(false);
  }

  /** A new rectangle of stock on the board, chosen, its form open. */
  private addShape(rect: PlanRectangle, place: DraftPlace): string | null {
    let key: string | null = null;
    this.change((draft) => {
      const made = addDrawing(draft, rect, place);
      key = made.key;

      return made.draft;
    });
    if (key !== null) this.openDrawing(key);

    return key;
  }

  /**
   * « Enregistrer »: the floor's whole draft in one request, all of it or none of it. Its toast's « Annuler » takes the
   * whole step back, the places it created included, which held nothing yet.
   */
  protected async savePlan(): Promise<void> {
    const companyId = this.company()?.id;
    const floor = this.floor();
    const draft = this.draft();
    const count = changeCount(draft);
    if (!companyId || floor === null || count === 0 || this.busy()) return;

    const savedDrawings = this.facade.drawings();
    const savedStructures = this.facade.structures();
    const { step, sources } = stepOf(draft, savedDrawings);
    const saved = await this.facade.changePlan(companyId, floor.id, step);
    if (saved === null) {
      await this.planRefused(companyId, floor.id, sources);

      return;
    }
    this.editing.set(null);
    this.editingStructure.set(null);
    this.chosen.set(new Set([...this.chosen()].filter((id) => !isNewKey(id))));
    if (isNewKey(this.selectedStructureId() ?? '')) this.selectedStructureId.set(null);
    this.setDraft(floor.id, emptyDraft(floor.id));
    const back = inverseOf(
      step,
      saved.created,
      savedDrawings,
      savedStructures,
      saved.drawings,
      saved.structures,
      saved.floorVersion === null ? null : { before: floor, version: saved.floorVersion },
    );
    this.feedback.success(
      'inventory.plan.plan_saved',
      { count },
      { key: 'inventory.plan.undo', run: () => void this.undoPlan(companyId, floor.id, back) },
    );
  }

  private async undoPlan(
    companyId: string,
    floorId: string,
    back: { step: PlanStep; createdPlaces: string[] },
  ): Promise<void> {
    await this.undo(async () => {
      if ((await this.facade.changePlan(companyId, floorId, back.step)) === null) return false;
      for (const placeId of back.createdPlaces)
        await this.facade.deleteLocation(companyId, placeId);

      return true;
    });
  }

  /**
   * A step refused. Saved by someone else since: the floor is read again and only their shapes are let go, the rest
   * of the draft kept and said. Refused for one shape: that shape is chosen with its form open, where the error is.
   */
  private async planRefused(
    companyId: string,
    floorId: string,
    sources: PlanStepSources,
  ): Promise<void> {
    if (this.facade.error() === 'record_changed') {
      this.facade.clearError();
      await Promise.all([
        this.facade.loadPlanContext(companyId),
        this.facade.reloadDrawings(companyId),
        this.facade.reloadStructures(companyId),
      ]);
      const { draft, dropped } = rebased(
        this.draft(),
        this.facade.drawings(),
        this.facade.structures(),
        this.facade.locations(),
        this.floor(),
      );
      this.setDraft(floorId, draft);
      this.feedback.failure('inventory.plan.plan_changed', { count: dropped });

      return;
    }
    const entry = this.facade.refusedEntry();
    const id = entry === null ? null : sourceOf(sources, entry);
    if (id === null) return;
    const piece = this.builtRows().find((one) => one.id === id);
    if (piece !== undefined) this.openStructure(piece);
    else if (this.drawnRows().some((one) => one.id === id)) this.openDrawing(id);
  }

  /** « Abandonner les changements »: the board back to what is saved, with « Annuler » on the toast to undo that. */
  protected discardPlan(): void {
    const floorId = this.floor()?.id;
    const draft = this.draft();
    const count = changeCount(draft);
    if (!floorId || count === 0) return;
    this.putToolsDown();
    this.letGo();
    this.selectedStructureId.set(null);
    this.setDraft(floorId, emptyDraft(floorId));
    this.feedback.success(
      'inventory.plan.plan_discarded',
      { count },
      {
        key: 'inventory.plan.undo',
        run: () => {
          // Only onto a board left as saved: work begun since is not overwritten by what was let go.
          if (changeCount(this.drafts().get(floorId) ?? emptyDraft(floorId)) > 0) {
            this.feedback.failure('live.undo_refused');

            return;
          }
          this.setDraft(floorId, draft);
          this.feedback.success('inventory.plan.undone');
        },
      },
    );
  }

  protected async showFloor(floorId: string | null): Promise<void> {
    const companyId = this.company()?.id;
    this.chosenFloorId.set(floorId);
    this.endMovement();
    this.chooseOnly(null);
    this.editing.set(null);
    this.drag = null;
    this.editingStructure.set(null);
    this.selectedStructureId.set(null);
    if (companyId && floorId) {
      await this.facade.loadDrawings(companyId, floorId);
      await this.facade.loadStructures(companyId, floorId);
    }
    this.offerKept(floorId);
  }

  /**
   * A line's movement open in the panel (docs/SPEC.md § 7, 2026-10-09 10:31): while a move is, a place touched on the
   * plan, in the list or in the 3D is where the goods go, and the chosen place stays chosen.
   */
  protected readonly movement = signal<MovementAsked | null>(null);
  /** Where the open move sends the goods, by location: the plan outlines it. */
  protected readonly destination = signal<string | null>(null);
  /** The line carried by its grip, until it is let go. */
  protected readonly carried = signal<StockLevelRow | null>(null);
  /** The places the open move may send the goods to: every drawn one but where they are. */
  protected readonly moving = computed(() => {
    const movement = this.movement();
    return movement?.operation === 'move' && !this.arranging() ? movement : null;
  });

  /** Arranging draws the places, it records no stock: a count or a receipt open in the panel is let go. */
  private readonly movementLeftOnArranging = effect(() => {
    if (this.arranging()) untracked(() => this.endMovement());
  });

  protected startMovement(asked: MovementAsked): void {
    this.destination.set(null);
    this.movement.set(asked);
  }

  protected endMovement(): void {
    this.movement.set(null);
    this.destination.set(null);
  }

  /** True when the touch named the open move's destination rather than a place to choose. */
  private aimed(drawing: StockDrawingRow): boolean {
    const moving = this.moving();
    if (moving === null) return false;
    if (drawing.locationId !== moving.line.locationId) this.destination.set(drawing.locationId);
    return true;
  }

  /** A line dropped on a place opens « Déplacer » to it, filled in, to be confirmed once. */
  protected dropOn(event: DragEvent, drawing: StockDrawingRow): void {
    const line = this.carried();
    this.carried.set(null);
    if (line === null || this.arranging() || drawing.locationId === line.locationId) return;
    event.preventDefault();
    this.movement.set({ operation: 'move', line });
    this.destination.set(drawing.locationId);
  }

  /** A place takes a carried line, so the browser shows it may be let go there. */
  protected dragOver(event: DragEvent, drawing: StockDrawingRow): void {
    const line = this.carried();
    if (line !== null && !this.arranging() && drawing.locationId !== line.locationId) {
      event.preventDefault();
    }
  }

  /** One selection across both layers: choosing a rack lets go of the wall that was chosen (finding G). */
  protected select(drawing: StockDrawingRow): void {
    if (this.aimed(drawing)) return;
    this.endMovement();
    this.chooseOnly(drawing.id);
    this.selectedStructureId.set(null);
    this.editingStructure.set(null);
    // An open form follows the choice, so the form beside the board is always the shape being worked on.
    if (this.editing() !== null) this.editing.set(drawing.id);
  }

  /** A place clicked in the 3D is chosen as a click on the plan chooses it. */
  protected pickInVolume(drawingId: string): void {
    const drawing = this.drawnRows().find((one) => one.id === drawingId);
    if (drawing !== undefined) this.select(drawing);
  }

  private chooseOnly(id: string | null): void {
    this.chosen.set(new Set(id === null ? [] : [id]));
  }

  /** A click on a rectangle chooses it alone; with Shift it joins the group, or leaves it if it was in it. */
  protected choose(event: MouseEvent, drawing: StockDrawingRow): void {
    // The click a browser sends at the end of a group's drag is the drag's, not a choice of that one rectangle.
    if (this.draggedGroup) {
      this.draggedGroup = false;

      return;
    }
    if (!event.shiftKey || this.moving() !== null) {
      this.select(drawing);

      return;
    }
    const chosen = new Set(this.chosen());
    if (chosen.has(drawing.id)) chosen.delete(drawing.id);
    else chosen.add(drawing.id);
    this.chooseGroup(chosen);
  }

  /**
   * Several chosen: the one rectangle's form closes, as a group has no one form to write into, and the wall that
   * was chosen is let go, as for one.
   */
  private chooseGroup(ids: ReadonlySet<string>): void {
    this.endMovement();
    this.chosen.set(ids);
    this.selectedStructureId.set(null);
    this.editingStructure.set(null);
    if (ids.size > 1) {
      this.editing.set(null);
      this.drag = null;
    }
  }

  /** « Tout désélectionner », a press of bare floor that does not travel, and Échap with nothing under way. */
  protected letGo(): void {
    this.chosen.set(new Set());
  }

  // ——— a group, moved, turned and undrawn together (§ 7, 2026-10-09 17:45) ———

  protected readonly SNAP_DEGREES = SNAP_DEGREES;
  /** Whether a group can be acted on here: in Aménager, by whoever may draw, with several chosen. */
  protected readonly actsOnGroup = computed(
    () => this.arranging() && this.mayDraw() && this.chosenDrawings().length > 1,
  );
  private groupDrag: {
    pointerId: number;
    from: PlanPoint;
    frame: PlanFrame;
    box: PlanBox;
    origins: ReadonlyMap<string, PlanRectangle>;
    before: PlanDraft;
    past: boolean;
    startedAt: PlanPoint;
  } | null = null;
  private draggedGroup = false;

  /** The group as the board shows it now. */
  private groupRects(): ReadonlyMap<string, PlanRectangle> {
    return new Map(this.chosenDrawings().map((drawing) => [drawing.id, rectOf(drawing)] as const));
  }

  /** A press on a rectangle of the group takes hold of all of it: they move by one step, snapped once. */
  private holdGroup(event: PointerEvent): void {
    const surface = (event.target as Element).closest('svg');
    if (surface === null || this.offered() !== null) return;
    const box = surface.getBoundingClientRect();
    const frame = this.viewed();
    this.groupDrag = {
      pointerId: event.pointerId,
      from: pointerMetres({ x: event.clientX, y: event.clientY }, frame, box),
      frame,
      box,
      origins: this.groupRects(),
      before: this.draft(),
      past: false,
      startedAt: { x: event.clientX, y: event.clientY },
    };
    event.preventDefault();
  }

  /** Each rectangle of the group where it now stands, into the draft. */
  private placeGroup(rects: ReadonlyMap<string, PlanRectangle>): void {
    this.change((draft) =>
      [...rects].reduce(
        (next, [id, rect]) => placeDrawing(next, id, rect, this.savedDrawing(id)),
        draft,
      ),
    );
  }

  protected turnGroup(degrees: number): void {
    if (this.actsOnGroup()) this.placeGroup(turnedGroup(this.groupRects(), degrees));
  }

  private moveGroup(dx: number, dy: number): void {
    if (this.actsOnGroup()) this.placeGroup(shiftedGroup(this.groupRects(), dx, dy));
  }

  /** The keys of a group: the arrows move it, R turns it, Suppr takes it off — what they do to one rectangle. */
  private groupKey(event: KeyboardEvent): boolean {
    const step = event.shiftKey ? 1 : ARROW_STEP_METRES;
    const moves: Record<string, [number, number]> = {
      ArrowLeft: [-step, 0],
      ArrowRight: [step, 0],
      ArrowUp: [0, -step],
      ArrowDown: [0, step],
    };
    const move = moves[event.key];
    if (move !== undefined) this.moveGroup(...move);
    else if (event.key.toLowerCase() === 'r')
      this.turnGroup(event.shiftKey ? -SNAP_DEGREES : SNAP_DEGREES);
    else if (event.key === 'Delete') this.eraseGroup();
    else return false;

    return true;
  }

  /** The whole group off the board, into the draft. */
  protected eraseGroup(): void {
    if (this.actsOnGroup()) this.eraseShapes([...this.chosen()]);
  }

  /** Choosing a piece of the building to read about it, which is all a press on one does in Consulter. */
  protected selectStructure(piece: StockStructureRow): void {
    this.selectedStructureId.set(piece.id);
    this.chooseOnly(null);
    this.editingStructure.set(null);
  }

  /** A piece pressed on the plan or in the list: read in Consulter, opened in Aménager. */
  protected pressStructure(piece: StockStructureRow): void {
    if (this.arranging()) this.openStructure(piece);
    else this.selectStructure(piece);
  }

  /** A rectangle's form opened beside the board: the rectangle chosen, everything else's form closed. */
  protected draw(drawing: StockDrawingRow): void {
    this.openDrawing(drawing.id);
  }

  private openDrawing(id: string): void {
    this.facade.clearError();
    this.endMovement();
    this.editingFloor.set(null);
    this.editingStructure.set(null);
    this.selectedStructureId.set(null);
    this.repeating.set(false);
    this.chooseOnly(id);
    this.editing.set(id);
  }

  /** « Fermer »: the form goes, what it changed stays in the draft. */
  protected closeDrawing(): void {
    this.editing.set(null);
    this.facade.clearError();
  }

  /** « Effacer » on a rectangle: off the board, into the draft; its place keeps its code and its stock. */
  protected erase(drawing: StockDrawingRow): void {
    this.eraseShapes([drawing.id]);
  }

  // ——— the plan as a drawing surface ———

  /**
   * Who may draw. The phone READS the map and does not draw it (docs/SPEC.md § 7, decision 8, widened 2026-09-21):
   * a five-inch screen cannot carry a handle a fingertip can hit, so the handles are not there at all rather than
   * there and unusable. Everything they do stays reachable through the form, on every window.
   */
  protected readonly windowClass = inject(WINDOW_CLASS);
  protected readonly mayDraw = computed(() => this.mayWrite() && this.windowClass() !== 'compact');
  /**
   * The arrows that move a nearer view. A phone shows them only once there is somewhere to move: greyed out at
   * 100 %, they took a row of its screen and pushed the rest of the plan's controls under its bottom bar.
   */
  protected readonly panArrows = computed(
    () => this.windowClass() !== 'compact' || this.zoom() > 1,
  );

  /** The handles around the rectangle being worked on, in its OWN unturned corners: the group turns them with it. */
  protected readonly handles = computed(() => {
    const shape = this.shapes().find((one) => one.drawing.id === this.selectedId());
    if (shape === undefined || !this.arranging()) return [];

    return handlesThatFit(shape.rect, this.gripRadius()).map((handle) => {
      const at = handleAt(shape.rect, handle);

      return { handle, x: at.x, y: at.y, key: `${handle.hx}:${handle.hy}` };
    });
  });

  /** The same handles around a piece of the building, which now moves and resizes exactly as a rack does. */
  protected readonly structureHandles = computed(() => {
    const shape = this.builtShapes().find((one) => one.piece.id === this.selectedStructureId());
    if (shape === undefined || !this.arranging()) return [];

    return handlesThatFit(shape.rect, this.gripRadius()).map((handle) => {
      const at = handleAt(shape.rect, handle);

      return { handle, x: at.x, y: at.y, key: `${handle.hx}:${handle.hy}` };
    });
  });

  /** The radius a handle is drawn at, in METRES, so it stays the same size on the screen whatever the floor's size. */
  protected readonly handleRadius = computed(() => this.viewed().width / 110);

  private readonly coarsePointer = inject(COARSE_POINTER);

  /** The radius of the grip around each handle, in metres: what a mouse or a finger takes hold of. */
  protected readonly gripRadius = computed(() =>
    gripRadius(this.pixelsPerMetre(), this.coarsePointer(), this.handleRadius()),
  );

  /**
   * The grips on the floor's own edges, while arranging a measured floor: pulled, the floor is wider or longer in the
   * draft, saved with the rest by « Enregistrer ». The floor's form still takes its measures typed.
   */
  protected readonly floorHandles = computed(() => {
    const size = this.floorSize();
    if (!this.arranging() || !this.mayDraw() || size === null) return [];

    return FLOOR_HANDLES.map((handle) => ({
      handle,
      x: handle.hx * size.width,
      y: handle.hy * size.depth,
      key: `${handle.hx}:${handle.hy}`,
    }));
  });

  /**
   * The palette's ready-made shapes, at the sizes THIS company set for them — nothing here invents a measurement,
   * so a company whose settings answer nothing gets no palette rather than a made-up one. Only where drawing is
   * possible at all: a palette that poses rectangles is not a reading tool (decision 8).
   */
  protected readonly palette = computed<readonly StockPlanShape[]>(() =>
    this.arranging() ? (this.facade.options()?.planShapes ?? []) : [],
  );

  /**
   * A shape posed in the middle of the floor, chosen, its form open. It is PLACED and not saved: the rectangle is
   * then dragged where it goes and only what differs from the ready-made size is corrected, which is the whole
   * reason the palette exists. Its height stays whatever the form offers — a size is an emprise, not a measurement
   * of how tall a rack stands.
   */
  protected pose(shape: StockPlanShape): void {
    this.tracing.set(false);
    const kind = kindOfShape(shape.shape);
    this.addShape(
      {
        ...centredIn(this.viewed(), shape.width, shape.depth),
        rotation: 0,
        height: newRect().height,
      },
      this.newPlace(kind),
    );
  }

  /** The new place a new rectangle proposes: of this kind, with the code after the floor establishment's last one. */
  private proposal(kind: StockLocationKind): NewPlaceProposal {
    const establishmentId = this.floor()?.establishmentId;
    // The draft's own new places count too, or two racks posed before saving would both be offered the same code.
    const drafted = this.draft().newDrawings.flatMap((one) =>
      'code' in one.place && establishmentId
        ? [
            {
              ...one.place,
              id: one.key,
              establishmentId,
              parentId: null,
              isDefault: false,
              childCount: 0,
              movementCount: 0,
              version: 0,
            },
          ]
        : [],
    );

    return {
      kind,
      code: establishmentId
        ? proposedCode([...this.facade.locations(), ...drafted], establishmentId, kind)
        : '',
    };
  }

  /** A new place for a new rectangle, as the draft holds it: of this kind, its code proposed, its name to type. */
  private newPlace(kind: StockLocationKind): DraftPlace {
    return { kind, code: this.proposal(kind).code, name: '' };
  }

  /** Whether the next gesture on bare floor traces a box. Armed by the tool beside the plan, for one box. */
  protected readonly tracing = signal(false);

  protected armTrace(): void {
    this.tracing.update((armed) => !armed);
  }

  /** The free rectangle by its measures rather than traced: posed in the middle, its form open, no gesture needed. */
  protected typeMeasures(): void {
    this.tracing.set(false);
    const fresh = newRect();
    this.addShape(
      {
        ...fresh,
        ...centredIn(this.viewed(), fresh.width, fresh.depth),
        rotation: 0,
        height: fresh.height,
      },
      this.newPlace('rack'),
    );
  }

  // ——— repeating a rectangle down an aisle ———

  /**
   * Repeating what is selected (docs/SPEC.md row 83; the approved canvas's Repeat board). Nobody draws sixteen
   * identical racks one at a time, and a copy is a STOCK LOCATION as well as a rectangle — so this panel says so in
   * those words before it creates anything.
   */
  protected readonly repeating = signal(false);
  /**
   * Repeating saves at once, places and all, so it waits for the draft to be saved: the copies would otherwise be
   * made from where the rack stood, not where the board shows it.
   */
  protected readonly mayRepeat = computed(
    () => this.planChanges() === 0 && !isNewKey(this.selectedId() ?? ''),
  );
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
    if (!this.mayRepeat()) return;
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
    // A Shift press is a choice, which its click makes: it moves nothing.
    if (!this.arranging() || event.button !== 0 || event.shiftKey) return;
    if (handle === null && this.chosenDrawings().length > 1 && this.chosen().has(drawing.id)) {
      if (this.actsOnGroup()) this.holdGroup(event);

      return;
    }

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
    if (!this.arranging() || event.button !== 0) return;

    this.openStructure(piece);
    const shape = this.builtShapes().find((one) => one.piece.id === piece.id);
    if (shape === undefined) return;

    this.hold(event, 'structure', piece.id, shape.rect, handle);
  }

  /** The floor's own edge taken hold of: the board's frame is held still while it is pulled. */
  protected grabFloor(event: PointerEvent, handle: PlanHandle): void {
    const size = this.floorSize();
    if (!this.arranging() || event.button !== 0 || size === null || this.offered() !== null) return;
    this.heldFrame.set(this.frame());
    this.hold(
      event,
      'floor',
      null,
      { x: 0, y: 0, width: size.width, depth: size.depth, rotation: 0, height: 0 },
      handle,
    );
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

  /** Space held down, which turns a drag of the plan into moving the view, as in a drawing tool. */
  protected readonly spaceHeld = signal(false);
  private lasso: Lasso | null = null;
  /** The box being dragged, in metres, while it is; `null` before it has travelled. */
  protected readonly lassoRect = signal<{
    x: number;
    y: number;
    width: number;
    depth: number;
  } | null>(null);

  protected spaceDown(event: KeyboardEvent): void {
    if (event.key !== ' ' || typed(event.target)) return;
    this.spaceHeld.set(true);
  }

  protected spaceUp(event: KeyboardEvent): void {
    if (event.key === ' ') this.spaceHeld.set(false);
  }

  /**
   * A press on bare floor. Armed to trace it draws a rectangle, as it always did. Otherwise a mouse or a pen drags a
   * box that chooses every rectangle it touches, and moves the view only with Space held or the middle button — the
   * convention of drawing tools (§ 7, 2026-10-09 17:47). A finger keeps moving the view: a box is a mouse's gesture,
   * and the finger has no Shift to keep what it chose.
   *
   * Moving the view waits until someone has come nearer, since showing the whole floor leaves nowhere to move to. A
   * finger only moves it then too: without `touch-action: none` the browser claims the drag for its own scrolling,
   * and laying that across a full-width plan traps a finger trying to scroll past it.
   */
  protected press(event: PointerEvent): void {
    // A new press: whatever click ended the last group drag has come, or never will.
    this.draggedGroup = false;
    if (this.tracing()) {
      this.trace(event);

      return;
    }
    const onFloor = (event.target as Element).tagName.toLowerCase() === 'svg';
    const moving =
      event.button === 1 ||
      (event.button === 0 && (this.spaceHeld() || event.pointerType === 'touch'));
    if (!moving) {
      // A press on a rectangle is that rectangle's, in either mode: it is chosen by its click, moved in Aménager.
      if (event.button === 0 && onFloor) this.startLasso(event);

      return;
    }
    const view = this.view();
    if (view === null || (!onFloor && event.pointerType === 'touch')) return;

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

  /**
   * One press of a pan arrow, or an arrow key on the board: what a drag of the view does, done by a single press,
   * which WCAG 2.5.7 asks of a map. Nothing to move to while the whole floor is shown.
   */
  protected step(across: number, down: number): void {
    const view = this.view();
    if (view === null) return;
    const shown = this.viewed();

    this.look(
      steppedBy(
        this.frame(),
        view,
        across * shown.width * PAN_STEP,
        down * shown.height * PAN_STEP,
      ),
    );
  }

  /**
   * The board's own keys, the convention of web maps: + and − come nearer and go back, the arrows move what is
   * shown. Only when the board itself has the focus, so a button inside it keeps its own Enter and Space.
   */
  protected boardKey(event: KeyboardEvent): void {
    if (event.target !== event.currentTarget) return;
    if (this.arranging() && this.arrangeKey(event)) {
      event.preventDefault();

      return;
    }
    const moves: Record<string, () => void> = {
      '+': () => this.zoomBy(PLAN_ZOOM_STEP),
      '=': () => this.zoomBy(PLAN_ZOOM_STEP),
      '-': () => this.zoomBy(1 / PLAN_ZOOM_STEP),
      ArrowLeft: () => this.step(-1, 0),
      ArrowRight: () => this.step(1, 0),
      ArrowUp: () => this.step(0, -1),
      ArrowDown: () => this.step(0, 1),
    };
    const move = moves[event.key];
    if (move === undefined) return;

    // The arrows would otherwise scroll the page as well as the plan.
    event.preventDefault();
    move();
  }

  /**
   * What a gesture does, from the keyboard (the stock map brief, § 5.3), on the shape chosen in Aménager: the arrows
   * move it a quarter metre (a metre with Shift), R turns it a step (back with Shift), Suppr undraws it, Tab goes to
   * the next rectangle. Each writes into the shape's form as a drag does, so nothing is saved before Enregistrer.
   * True when the key was the shape's; a key it does not take is left to the board, and the last Tab to the page.
   */
  private arrangeKey(event: KeyboardEvent): boolean {
    if (event.key === 'Tab') return this.nextShape(event.shiftKey ? -1 : 1);
    if (this.actsOnGroup()) return this.groupKey(event);

    const piece = this.selectedStructure();
    const drawing = this.selected();
    const shape: { on: 'drawing' | 'structure'; id: string; rect: PlanRectangle } | null =
      piece !== null
        ? { on: 'structure', id: piece.id, rect: rectOf(piece) }
        : drawing !== null
          ? { on: 'drawing', id: drawing.id, rect: rectOf(drawing) }
          : null;
    if (shape === null) return false;
    if (event.key === 'Delete') {
      this.eraseShapes([shape.id]);

      return true;
    }

    const step = event.shiftKey ? 1 : ARROW_STEP_METRES;
    const moves: Record<string, [number, number]> = {
      ArrowLeft: [-step, 0],
      ArrowRight: [step, 0],
      ArrowUp: [0, -step],
      ArrowDown: [0, step],
    };
    const move = moves[event.key];
    const turn =
      event.key.toLowerCase() === 'r' ? (event.shiftKey ? -SNAP_DEGREES : SNAP_DEGREES) : null;
    if (move !== undefined) {
      this.place(shape.on, shape.id, {
        ...shape.rect,
        x: Math.max(0, shape.rect.x + move[0]),
        y: Math.max(0, shape.rect.y + move[1]),
      });
    } else if (turn !== null) {
      this.place(shape.on, shape.id, {
        ...shape.rect,
        rotation: snapAngle(shape.rect.rotation + turn),
      });
    } else {
      return false;
    }

    return true;
  }

  /** The next or previous rectangle in the order the list shows them; false past either end, so Tab leaves. */
  private nextShape(direction: 1 | -1): boolean {
    if (this.editing() !== null || this.editingStructure() !== null) return false;
    const shapes = this.shapes();
    const at = shapes.findIndex((one) => one.drawing.id === this.selectedId());
    if (at === -1) return false;
    const next = shapes[at + direction];
    if (next === undefined) return false;
    this.select(next.drawing);

    return true;
  }

  /**
   * The scale bar: a round length a person reads at a glance and can count on the grid, drawn under the plan as long
   * as that many metres are on screen, so it stays true at every zoom without standing on anything drawn.
   */
  protected readonly scaleBar = computed(() => {
    const length = scaleBarMetres(this.viewed().width);
    const pixels = this.pixelsPerMetre();

    return {
      label: length < 1 ? `${length * 100} cm` : `${length} m`,
      pixels: pixels === null ? null : length * pixels,
    };
  });

  /** Showing the whole floor is held as "no view at all", so the two ways of saying it cannot disagree. */
  private look(view: PlanView): void {
    this.view.set(view.scale <= PLAN_ZOOM_MIN ? null : view);
  }

  /** What every gesture starts with: the frame and the viewport frozen, and the metre the pointer began on. */
  private hold(
    event: PointerEvent,
    on: 'drawing' | 'structure' | 'floor',
    drawingId: string | null,
    origin: PlanRectangle | null,
    handle: PlanHandle | null,
  ): void {
    if (!this.arranging() || event.button !== 0 || this.offered() !== null) {
      this.heldFrame.set(null);

      return;
    }
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
      before: this.draft(),
      past: false,
      startedAt: { x: event.clientX, y: event.clientY },
    };
    event.preventDefault();
  }

  private startLasso(event: PointerEvent): void {
    const surface = event.currentTarget as SVGSVGElement;
    const box = surface.getBoundingClientRect();
    const frame = this.viewed();
    this.lasso = {
      pointerId: event.pointerId,
      from: pointerMetres({ x: event.clientX, y: event.clientY }, frame, box),
      frame,
      box,
      extend: event.shiftKey,
      startedAt: { x: event.clientX, y: event.clientY },
    };
    event.preventDefault();
  }

  private lassoTo(lasso: Lasso, event: PointerEvent): PlanPoint {
    return pointerMetres({ x: event.clientX, y: event.clientY }, lasso.frame, lasso.box);
  }

  protected drags(event: PointerEvent): void {
    const held = this.groupDrag;
    if (held !== null && held.pointerId === event.pointerId) {
      if (!held.past) {
        const far =
          Math.abs(event.clientX - held.startedAt.x) + Math.abs(event.clientY - held.startedAt.y);
        if (far < DRAG_THRESHOLD) return;
        held.past = true;
      }
      const to = pointerMetres({ x: event.clientX, y: event.clientY }, held.frame, held.box);
      // The step is snapped once, for the group, so the rectangles keep the spacing they had between them.
      this.placeGroup(
        shiftedGroup(held.origins, snapMetres(to.x - held.from.x), snapMetres(to.y - held.from.y)),
      );

      return;
    }

    const lasso = this.lasso;
    if (lasso !== null && lasso.pointerId === event.pointerId) {
      const far =
        Math.abs(event.clientX - lasso.startedAt.x) + Math.abs(event.clientY - lasso.startedAt.y);
      if (this.lassoRect() === null && far < DRAG_THRESHOLD) return;
      const to = this.lassoTo(lasso, event);
      this.lassoRect.set({
        x: Math.min(lasso.from.x, to.x),
        y: Math.min(lasso.from.y, to.y),
        width: Math.abs(to.x - lasso.from.x),
        depth: Math.abs(to.y - lasso.from.y),
      });

      return;
    }

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
      // Only now is it a drag, and what the board held is kept so Échap can put it back exactly.
      drag.past = true;
      drag.before = this.draft();
    }

    const to = pointerMetres({ x: event.clientX, y: event.clientY }, drag.frame, drag.box);
    const origin = drag.origin;
    if (drag.on === 'floor') {
      const floor = this.floor();
      if (origin === null || drag.handle === null || floor === null) return;
      const pulled = resizedTo(origin, drag.handle, to);
      const size = {
        width: Math.max(FLOOR_LEAST, pulled.x + pulled.width),
        depth: Math.max(FLOOR_LEAST, pulled.y + pulled.depth),
      };
      this.change((draft) => sizeFloor(draft, size, floor));

      return;
    }
    if (origin === null) {
      // A box being traced: a new rectangle on its first move, which every later move reshapes.
      const rect = { ...tracedTo(drag.from, to), rotation: 0, height: newRect().height };
      if (drag.drawingId === null) drag.drawingId = this.addShape(rect, this.newPlace('rack'));
      else this.place('drawing', drag.drawingId, rect);

      return;
    }
    if (drag.drawingId === null) return;

    const next =
      drag.handle === null ? movedTo(origin, drag.from, to) : resizedTo(origin, drag.handle, to);
    this.place(drag.on, drag.drawingId, next);
  }

  protected drops(event: PointerEvent): void {
    const held = this.groupDrag;
    if (held !== null && held.pointerId === event.pointerId) {
      this.groupDrag = null;
      this.draggedGroup = held.past;

      return;
    }
    const lasso = this.lasso;
    if (lasso !== null && lasso.pointerId === event.pointerId) {
      this.lasso = null;
      this.dropLasso(lasso, event);

      return;
    }
    if (this.pan?.pointerId === event.pointerId) this.pan = null;
    if (this.drag?.pointerId !== event.pointerId) return;

    // One box per arming: the sheet goes back to being something a finger can scroll past.
    if (this.drag.on === 'drawing' && this.drag.origin === null && this.drag.past)
      this.tracing.set(false);
    this.drag = null;
    // The floor framed again once its edge is let go, with whatever size the draft now gives it.
    this.heldFrame.set(null);
  }

  /**
   * A box that travelled chooses what it touches, even in part; a press that did not lets the choice go — unless a
   * form is open, which a stray click beside it must not leave without its rectangle.
   */
  private dropLasso(lasso: Lasso, event: PointerEvent): void {
    const travelled = this.lassoRect() !== null;
    this.lassoRect.set(null);
    if (!travelled) {
      if (!lasso.extend && this.editing() === null) this.letGo();

      return;
    }
    const to = this.lassoTo(lasso, event);
    const touched = this.shapes()
      .filter((shape) => touchesBox(lasso.from, to, shape.rect))
      .map((shape) => shape.drawing.id);
    if (touched.length === 0 && lasso.extend) return;
    const ids = new Set(lasso.extend ? [...this.chosen(), ...touched] : touched);
    if (ids.size === 1) {
      const one = this.drawnRows().find((drawing) => ids.has(drawing.id));
      if (one !== undefined) this.select(one);

      return;
    }
    this.chooseGroup(ids);
  }

  /**
   * Échap gives the rectangle back exactly what the form held before the gesture — never a guess at it. With nothing
   * under way it leaves the full screen, as its button says, and keeps what is chosen; otherwise, with no form open,
   * it lets the choice go. A gesture belongs to the plan, so the 3D has only the full screen to leave.
   */
  protected escape(event: Event): void {
    if (!this.volumeShown() && this.stopGesture()) return;
    if (this.full()) {
      // Échap in the search closes what it offers; the full screen is left from the plan.
      if (!isTypingTarget(event.target)) this.leaveFull();
      return;
    }
    if (this.volumeShown() || this.editing() !== null) return;
    this.letGo();
  }

  /** Ends a gesture under way, its rectangle put back as it stood; true when there was one. */
  private stopGesture(): boolean {
    const drag = this.drag;
    const held = this.groupDrag;
    const underway =
      drag !== null || held !== null || this.pan !== null || this.lasso !== null || this.tracing();
    this.drag = null;
    this.groupDrag = null;
    this.pan = null;
    this.lasso = null;
    this.lassoRect.set(null);
    this.tracing.set(false);
    this.heldFrame.set(null);
    const before = drag?.past ? drag.before : held?.past ? held.before : null;
    if (before !== null) this.putBack(before);

    return underway;
  }

  /** The floor's draft as it stood before a gesture, and the open forms with it, quietly. */
  private putBack(before: PlanDraft): void {
    this.setDraft(before.floorId, before);
    if (this.editing() !== null && !this.drawnRows().some((one) => one.id === this.editing())) {
      this.editing.set(null);
    }
    const drawingId = this.editing();
    const drawing = this.drawnRows().find((one) => one.id === drawingId);
    if (drawing !== undefined) {
      this.drawingFormGroup()?.patchValue(rectValues(rectOf(drawing)), { emitEvent: false });
    }
    const piece = this.builtRows().find((one) => one.id === this.editingStructure());
    if (piece !== undefined) {
      this.structureFormGroup()?.patchValue(rectValues(rectOf(piece)), { emitEvent: false });
    }
  }

  // ——— the map over the whole window (docs/SPEC.md § 7, 2026-10-09 23:19 and 2026-10-10 00:27) ———

  private readonly document = inject(DOCUMENT);
  private readonly immersive = inject(Immersive);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private readonly injector = inject(Injector);
  /** The map over the whole window: the board fills it, and the rest floats over it. */
  protected readonly full = signal(false);
  /** The floors and the lists, behind « Listes » while the map fills the window. */
  protected readonly listsOpen = signal(false);
  protected readonly compact = computed(() => this.windowClass() === 'compact');
  /** There is a plan to fill the window with. */
  protected readonly mayFill = computed(() => this.company() !== null && this.floors().length > 0);
  /**
   * Whether the browser's own full screen is this page's to give back. It is asked for on top of the page's own, where
   * the browser has one: an iPhone has none, and a frame or a policy may refuse it, and the page's own then stands.
   */
  private holdsBrowser = false;

  /** F, held one scan gap by the shell like every screen's key, so a code beginning with it opens nothing. */
  private readonly fullAction = computed<ScreenAction[]>(() => {
    const full = this.full();
    return [
      {
        id: 'full-screen',
        label: full ? 'inventory.plan.full.leave_short' : 'inventory.plan.full.enter',
        icon: full ? 'fullscreen_exit' : 'fullscreen',
        shortcut: 'f',
        shown: this.mayFill(),
        run: () => (this.full() ? this.leaveFull() : this.enterFull()),
      },
    ];
  });

  protected enterFull(): void {
    if (this.full()) return;
    this.full.set(true);
    const root = this.document.documentElement;
    if (!this.document.fullscreenEnabled || typeof root.requestFullscreen !== 'function') return;
    root.requestFullscreen().then(
      () => (this.holdsBrowser = true),
      // Refused, by a frame or a policy: the ruled fallback is the page's own full screen, already shown.
      () => (this.holdsBrowser = false),
    );
  }

  protected leaveFull(): void {
    if (!this.full()) return;
    this.full.set(false);
    this.listsOpen.set(false);
    this.giveBrowserBack();
    // The focus goes back to what opened it, rather than to the top of a page the person never left.
    afterNextRender(
      () => this.host.querySelector<HTMLElement>('[data-testid="stock-map-full"]')?.focus(),
      { injector: this.injector },
    );
  }

  /** Exits only a full screen this page holds: exiting one it does not hold is refused by the browser. */
  private giveBrowserBack(): void {
    const held = this.holdsBrowser && this.document.fullscreenElement != null;
    this.holdsBrowser = false;
    if (held) void this.document.exitFullscreen();
  }

  /** The browser's own Échap never reaches the page: its leaving the full screen is the map's leaving it. */
  private browserLeft(): void {
    if (!this.holdsBrowser || this.document.fullscreenElement != null) return;
    this.holdsBrowser = false;
    this.stopGesture();
    this.leaveFull();
  }

  protected toggleLists(): void {
    this.listsOpen.update((open) => !open);
  }

  /**
   * « Annuler » on a plan's toast: the change taken back, and said. It never writes over another person's change:
   * refused as saved since, it says nothing was taken back.
   */
  private async undo(takeBack: () => Promise<unknown>): Promise<void> {
    if (await takeBack()) this.feedback.success('inventory.plan.undone');
    else if (this.facade.error() === 'record_changed') {
      this.facade.clearError();
      this.feedback.failure('live.undo_refused');
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
    this.arranging() ? (this.facade.options()?.structureShapes ?? []) : [],
  );

  /** The piece of the building whose form is open: its id, or its key while it is not saved yet. */
  protected readonly editingStructure = signal<string | null>(null);
  protected readonly selectedStructureId = signal<string | null>(null);
  /** The piece of the building the handles are drawn around, which is what those handles then work on. */
  protected readonly selectedStructure = computed(
    () => this.builtRows().find((piece) => piece.id === this.selectedStructureId()) ?? null,
  );

  protected readonly structureDescriptor = computed(() =>
    this.editingStructure() === null ? null : structureForm(),
  );

  protected readonly structureFormGroup = linkedSignal<
    { editing: string | null; descriptor: FormDescriptor | null },
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
            structureValues(
              this.builtRows().find((piece) => piece.id === editing) ?? null,
              this.structureTools(),
            ),
          ),
      );
    },
  });

  /** The building as the plan draws it: the draft over what was saved, the same one mechanism the stock uses. */
  protected readonly builtShapes = computed<StructureShape[]>(() => {
    const pixels = this.pixelsPerMetre();

    return this.builtRows().map((piece) => builtOf(piece, rectOf(piece), this.labelMode(), pixels));
  });

  /** A form is open — a rectangle of stock's or a piece of the building's — and so it stands where the tools were. */
  protected readonly inspecting = computed(
    () => this.drawingFormGroup() !== null || this.structureFormGroup() !== null,
  );

  /**
   * Whether the board must say a save is owed: anything new is, and anything saved is once a field differs from
   * what the API holds. The form beside the board carries the count; the board carries the fact, where the eye is.
   */
  protected readonly owesSave = computed(() => this.planChanges() > 0);

  /**
   * The layers panel of the board. Counts are derived and never stored: a layer showing a number nobody maintains
   * is a number that goes wrong the first time something is erased somewhere else.
   *
   * The board draws a fourth layer, "Fond de plan". It is deliberately absent until the floor image exists to put
   * on it — a control that cannot do anything is the same can-never-fire shape as a preference between two views
   * while only one is built.
   */
  protected readonly layers = computed<PlanLayer[]>(() => {
    const drawings = this.drawnRows();

    return [
      { id: 'structure', count: this.builtRows().length },
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

  protected chooseView(view: StockMapView): void {
    this.settings.set(PRESENTATION.stockMapView, view);
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
    const height = metres(structureValues(null, this.structureTools(), tool.kind)['height']);
    let key: string | null = null;
    this.change((draft) => {
      const made = addStructure(
        draft,
        { ...centredIn(this.viewed(), tool.width, tool.depth), rotation: 0, height },
        { kind: tool.kind, name: '' },
      );
      key = made.key;

      return made.draft;
    });
    const posed = this.builtRows().find((piece) => piece.id === key);
    if (posed !== undefined) this.openStructure(posed);
  }

  /**
   * The building's editor. It checked nothing before 2026-09-22, so anyone who could read the plan could open a
   * form that moves a wall — the one place on this page where a permission was simply not asked for.
   */
  protected openStructure(target: StockStructureRow): void {
    if (!this.arranging()) return;
    this.facade.clearError();
    this.editing.set(null);
    this.editingFloor.set(null);
    this.repeating.set(false);
    this.chooseOnly(null);
    this.editingStructure.set(target.id);
    this.selectedStructureId.set(target.id);
  }

  /** « Fermer »: the form goes, what it changed stays in the draft. */
  protected closeStructure(): void {
    this.editingStructure.set(null);
    this.facade.clearError();
  }

  /** « Effacer » on a piece of the building: off the board, into the draft. */
  protected eraseStructure(piece: StockStructureRow): void {
    this.eraseShapes([piece.id]);
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

    // What the form does not show, the plan image, is carried from the floor as it stands now, not as it was opened.
    const row =
      editing === 'new'
        ? null
        : (this.facade.floors().find((floor) => floor.id === editing.id) ?? editing);
    const accepted =
      row === null
        ? await this.facade.createFloor(companyId, floorInput(values, null))
        : await this.facade.reviseFloor(
            companyId,
            row.id,
            floorInput(values, row),
            this.floorSync.version(),
          );
    if (accepted) {
      this.editingFloor.set(null);
      this.feedback.success('inventory.plan.floor_saved');
    } else if (this.error() === 'record_changed') {
      this.facade.clearError();
      await this.floorSync.savedElsewhere();
    }
  }

  /**
   * Only a floor with no location drawn on it is offered, yet its walls and racks go with it and nothing draws them
   * again: the question names it before anything goes.
   */
  protected removeFloor(row: StockFloorRow): void {
    if (this.busy()) return;
    runAction(
      {
        run: () => void this.removeFloorAsked(row),
        confirm: {
          kind: 'definitif',
          title: 'inventory.plan.remove_floor_title',
          message: 'inventory.plan.remove_floor_message',
          messageParams: { name: row.name },
          confirmLabel: 'inventory.plan.remove_floor',
          keepLabel: 'inventory.plan.keep_floor',
        },
      },
      (asked) =>
        this.dialog
          .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
            data: asked,
            autoFocus: 'dialog',
          })
          .afterClosed(),
    );
  }

  private async removeFloorAsked(row: StockFloorRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;

    const removed = await this.facade.deleteFloor(companyId, row.id);
    if (removed) {
      this.editingFloor.set(null);
      await this.showFloor(this.floors()[0]?.id ?? null);
      this.feedback.effect('inventory.plan.floor_removed', {}, 'definitif');
    }
  }
}

/** A key meant for a field or a button, whose Space types or presses rather than taking hold of the plan. */
function typed(target: EventTarget | null): boolean {
  return (
    target instanceof Element &&
    target.closest('input, textarea, select, button, [contenteditable]') !== null
  );
}

function metres(value: unknown): number {
  return Number(value ?? 0) || 0;
}

/** One piece of the building as the SVG needs it: where it turns about, what it is, and where its name goes. */
function builtOf(
  piece: StockStructureRow,
  rect: PlanRectangle,
  mode: PlanLabelMode,
  pixelsPerMetre: number | null,
): StructureShape {
  // A wall is a thin rectangle, so its name sits above the line rather than inside a 0,20 m band nothing fits in.
  const beside = rect.depth < 0.6;
  const name = planLabel('', piece.name, mode);
  const font = labelFont(
    STRUCTURE_LABEL_FONT,
    STRUCTURE_LABEL_PIXELS,
    pixelsPerMetre,
    beside ? null : rect.depth,
    { label: name, length: rect.width },
  );

  return {
    piece,
    rect,
    centreX: rect.x + rect.width / 2,
    centreY: rect.y + rect.depth / 2,
    labelX: rect.x + 0.15,
    labelY: beside ? rect.y - 0.12 : rect.y + baseline(font, rect.depth),
    // A piece carries no code, so `planLabel` answers its name whatever the mode — that is the point of passing it.
    label: fitLabel(planLabel('', piece.name, mode), rect.width, font),
    labelTitle: planLabel('', piece.name, mode),
    font,
  };
}

/** Where a label's baseline sits below its rectangle's top edge: its letters inside, as near the top as they fit. */
function baseline(font: number, depth: number): number {
  return Math.min(Math.max(0.45, font * 1.05), depth * 0.7);
}

/** One rectangle as the SVG needs it: where it turns about, and where its code is written inside it. */
function shapeOf(
  drawing: StockDrawingRow,
  rect: PlanRectangle,
  mode: PlanLabelMode,
  pixelsPerMetre: number | null,
): PlanShape {
  const whole = planLabel(drawing.locationCode, drawing.locationName, mode);
  // A rack seen from above is long and narrow, and across it only a code's first letters fit: there the label runs
  // down its length, turned a quarter, its letters standing on the rack's middle line.
  const along = rect.depth > rect.width * ALONG_RATIO;
  const font = labelFont(
    LABEL_FONT,
    LABEL_PIXELS,
    pixelsPerMetre,
    along ? rect.width : rect.depth,
    {
      label: whole,
      length: along ? rect.depth : rect.width,
    },
  );
  const labelX = along ? rect.x + rect.width / 2 - font * 0.35 : rect.x + 0.15;
  const labelY = along ? rect.y + 0.15 : rect.y + baseline(font, rect.depth);

  return {
    drawing,
    rect,
    centreX: rect.x + rect.width / 2,
    centreY: rect.y + rect.depth / 2,
    // Written inside the rectangle and turned with it, so a label never floats off its own rack.
    labelX,
    labelY,
    label: fitLabel(whole, along ? rect.depth : rect.width, font),
    labelTitle: whole,
    labelTurn: along ? `rotate(90 ${labelX} ${labelY})` : null,
    font,
  };
}

/** Whether two boards draw the same rectangles for the same places, wherever each now stands. */
function sameBindings(one: readonly StockDrawingRow[], other: readonly StockDrawingRow[]): boolean {
  return (
    one.length === other.length &&
    one.every((row, at) => row.id === other[at].id && row.locationId === other[at].locationId)
  );
}

/** Whether two lists name the same products in the same order, so an equal question is not asked again. */
function sameIds(one: readonly string[], other: readonly string[]): boolean {
  return one.length === other.length && one.every((id, at) => id === other[at]);
}

/** The places of an undrawn row, by code, as a sentence lists them. */
function codesOf(row: WhereaboutRow | null): string {
  return row?.lines.map((line) => line.locationCode).join(', ') ?? '';
}

/** How many characters a quantity takes once shown: its digits, a space every three, its decimals and its sign. */
function figureLength(quantity: string, decimals: number): number {
  const whole = quantity.replace('-', '').split('.')[0];
  return (
    whole.length +
    Math.floor((whole.length - 1) / 3) +
    (decimals > 0 ? decimals + 1 : 0) +
    (quantity.startsWith('-') ? 1 : 0)
  );
}

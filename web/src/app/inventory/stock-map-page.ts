// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
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
  planRectangles,
} from './stock-map-forms';
import { planViewBox, type PlanRectangle } from './stock-map-geometry';

/** How much floor is left around what is drawn, in metres, so nothing touches the frame. */
const PLAN_PADDING = 1;

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

  /** What is drawn on the floor being looked at, and the frame that holds all of it. */
  protected readonly shapes = computed<PlanShape[]>(() =>
    this.facade.drawings().map((drawing) => {
      const [rect] = planRectangles([drawing]);
      const shape = rect ?? { x: 0, y: 0, width: 0, depth: 0, rotation: 0, height: 0 };

      return {
        drawing,
        rect: shape,
        centreX: shape.x + shape.width / 2,
        centreY: shape.y + shape.depth / 2,
        // Written inside the rectangle and turned with it, so a label never floats off its own rack.
        labelX: shape.x + 0.15,
        labelY: shape.y + Math.min(0.45, shape.depth * 0.7),
      } satisfies PlanShape;
    }),
  );

  protected readonly viewBox = computed(() =>
    planViewBox(
      this.shapes().map((shape) => shape.rect),
      PLAN_PADDING,
    ),
  );

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
    if (companyId && floorId) await this.facade.loadDrawings(companyId, floorId);
  }

  protected select(drawing: StockDrawingRow): void {
    this.selectedId.set(drawing.id);
  }

  protected draw(target: StockDrawingRow | 'new'): void {
    this.facade.clearError();
    this.editingFloor.set(null);
    this.editing.set(target);
    this.selectedId.set(target === 'new' ? null : target.id);
  }

  protected cancelDrawing(): void {
    this.editing.set(null);
    this.facade.clearError();
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

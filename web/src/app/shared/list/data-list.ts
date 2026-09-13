// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  type CdkDragDrop,
  CdkDrag,
  CdkDragHandle,
  CdkDropList,
  moveItemInArray,
} from '@angular/cdk/drag-drop';
import { NgTemplateOutlet } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  contentChildren,
  DestroyRef,
  Directive,
  DOCUMENT,
  inject,
  input,
  linkedSignal,
  signal,
  TemplateRef,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, type PageEvent } from '@angular/material/paginator';
import { MatSortModule, type Sort } from '@angular/material/sort';
import { MatTableModule } from '@angular/material/table';
import { TranslatePipe } from '@ngx-translate/core';
import { SettingsFacade } from '../settings/settings-facade';
import { listPreferencesSetting, listViewsSetting } from '../settings/settings-registry';
import type {
  ListColumn,
  ListDescriptor,
  ListFilterValues,
  ListPreferences,
  ListSort,
  ListView,
} from './list-types';
import {
  applyFilters,
  filterRows,
  isColumnVisible,
  orderColumns,
  paginate,
  removeView,
  resolveColumns,
  saveView,
  sortRows,
} from './list-view';

/** `<ng-template appDataListCell="columnId" let-row>`: how one column's cell renders instead of its plain value. */
@Directive({ selector: 'ng-template[appDataListCell]' })
export class DataListCell {
  readonly column = input.required<string>({ alias: 'appDataListCell' });
  readonly template = inject<TemplateRef<{ $implicit: unknown }>>(TemplateRef);
}

/** `<ng-template appDataListRowActions let-row>`: the trailing cell of each row, such as a remove button. */
@Directive({ selector: 'ng-template[appDataListRowActions]' })
export class DataListRowActions {
  readonly template = inject<TemplateRef<{ $implicit: unknown }>>(TemplateRef);
}

const ACTIONS_COLUMN = '__actions';
const RESIZE_STEP = 16;
const MIN_WIDTH = 48;
const MAX_WIDTH = 960;
const FALLBACK_WIDTH = 160;
const ACTIONS_WIDTH = 96;

const clampWidth = (width: number): number => Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, width));

/** Unique enough for one person's views of one list; `crypto.randomUUID` needs a secure context. */
const newViewId = (): string =>
  `v${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;

/** What a view restores, in a comparable form: filter options in id order, so key order never matters. */
const viewState = (query: string, filters: ListFilterValues, layout: ListPreferences): string =>
  JSON.stringify([query, Object.entries(filters).sort(([a], [b]) => a.localeCompare(b)), layout]);

/**
 * Every list screen: a text filter and faceted filters, saved views, sortable columns a person may hide, reorder (by dragging or with buttons) and
 * resize (by pointer or keyboard), pages, and an empty state. What it shows comes from the screen's descriptor
 * and the pure functions in list-view.ts; what a person chose is kept through the presentation settings.
 */
@Component({
  selector: 'app-data-list',
  imports: [
    NgTemplateOutlet,
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
    MatTableModule,
    MatSortModule,
    MatPaginatorModule,
    MatFormFieldModule,
    MatInputModule,
    MatIconModule,
    MatButtonModule,
    TranslatePipe,
  ],
  templateUrl: './data-list.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DataList<Row> {
  private readonly settings = inject(SettingsFacade);
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);

  readonly descriptor = input.required<ListDescriptor<Row>>();
  readonly rows = input.required<readonly Row[]>();
  readonly testId = input('data-list');
  readonly rowTestId = input<((row: Row) => string) | null>(null);
  readonly emptyKey = input.required<string>();
  readonly emptyTestId = input('data-list-empty');

  private readonly cells = contentChildren(DataListCell);
  protected readonly actions = contentChild(DataListRowActions);

  private readonly setting = computed(() => listPreferencesSetting(this.descriptor().id));
  private readonly stored = computed(() => this.settings.value(this.setting()));
  protected readonly preferences = computed(() => this.stored()());
  private readonly viewsSetting = computed(() => listViewsSetting(this.descriptor().id));
  protected readonly views = computed(() => this.settings.value(this.viewsSetting())());

  protected readonly query = signal('');
  protected readonly chosenFilters = signal<ListFilterValues>({});
  protected readonly viewsOpen = signal(false);
  protected readonly viewName = signal('');
  protected readonly pageIndex = signal(0);
  protected readonly pageSize = linkedSignal(() => this.descriptor().pageSizes[0] ?? 25);
  protected readonly chooserOpen = signal(false);
  private readonly liveWidth = signal<{ id: string; width: number } | null>(null);

  protected readonly columns = computed(() =>
    resolveColumns(this.descriptor(), this.preferences()),
  );
  protected readonly columnIds = computed(() => [
    ...this.columns().map((column) => column.id),
    ...(this.actions() ? [ACTIONS_COLUMN] : []),
  ]);
  protected readonly chooser = computed(() =>
    orderColumns(this.descriptor(), this.preferences()).map((column) => ({
      column,
      visible: isColumnVisible(column, this.preferences()),
    })),
  );
  protected readonly sort = computed<ListSort | null>(
    () => this.preferences().sort ?? this.descriptor().defaultSort ?? null,
  );
  protected readonly filterable = computed(() =>
    this.descriptor().columns.some((column) => column.filterable),
  );
  protected readonly filters = computed(() => this.descriptor().filters ?? []);
  /** The saved view that matches what the screen shows now, if any. */
  protected readonly currentViewId = computed(() => {
    const now = viewState(this.query(), this.chosenFilters(), this.preferences());
    return (
      this.views().find((view) => viewState(view.query, view.filters, view.layout) === now)?.id ??
      null
    );
  });
  protected readonly cellTemplates = computed(
    () => new Map(this.cells().map((cell) => [cell.column(), cell.template] as const)),
  );
  protected readonly page = computed(() => {
    const columns = this.descriptor().columns;
    const narrowed = applyFilters(this.rows(), this.filters(), this.chosenFilters());
    const shown = sortRows(filterRows(narrowed, columns, this.query()), columns, this.sort());
    return paginate(shown, this.pageIndex(), this.pageSize());
  });
  protected readonly paged = computed(
    () => this.rows().length > (this.descriptor().pageSizes[0] ?? Infinity),
  );
  protected readonly actionsColumn = ACTIONS_COLUMN;
  protected readonly actionsWidth = ACTIONS_WIDTH;
  protected readonly minWidth = MIN_WIDTH;
  protected readonly maxWidth = MAX_WIDTH;
  /** At least the sum of the columns, so a narrow screen scrolls the list's own container and never the page. */
  protected readonly tableWidth = computed(
    () =>
      this.columns().reduce((sum, column) => sum + this.widthOf(column), 0) +
      (this.actions() ? ACTIONS_WIDTH : 0),
  );

  private stopResize: (() => void) | null = null;

  constructor() {
    this.destroyRef.onDestroy(() => this.stopResize?.());
  }

  protected testIdOf(row: Row): string | null {
    return this.rowTestId()?.(row) ?? null;
  }

  protected trackRow = (_index: number, row: Row): string => this.descriptor().rowId(row);

  /** The width a column occupies: a live drag, then the chosen or declared width, then the fallback. */
  protected widthOf(column: ListColumn<Row>): number {
    const live = this.liveWidth();
    return live?.id === column.id ? live.width : (column.width ?? FALLBACK_WIDTH);
  }

  protected onFilter(event: Event): void {
    this.query.set((event.target as HTMLInputElement).value);
    this.pageIndex.set(0);
  }

  protected clearFilter(): void {
    this.query.set('');
    this.pageIndex.set(0);
  }

  protected onFacet(filterId: string, event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    this.chosenFilters.update((chosen) => ({
      ...Object.fromEntries(Object.entries(chosen).filter(([id]) => id !== filterId)),
      ...(value === '' ? {} : { [filterId]: value }),
    }));
    this.pageIndex.set(0);
  }

  protected onViewName(event: Event): void {
    this.viewName.set((event.target as HTMLInputElement).value);
  }

  /** Saves the text filter, the filter options and the column layout under the typed name. */
  protected onSaveView(event: Event): void {
    event.preventDefault();
    if (this.viewName().trim() === '') return;
    const draft = {
      name: this.viewName(),
      query: this.query(),
      filters: this.chosenFilters(),
      layout: this.preferences(),
    };
    this.settings.set(this.viewsSetting(), saveView(this.views(), draft, newViewId()));
    this.viewName.set('');
  }

  protected applyView(view: ListView): void {
    this.query.set(view.query);
    this.chosenFilters.set({ ...view.filters });
    this.settings.set(this.setting(), view.layout);
    this.pageIndex.set(0);
  }

  protected deleteView(id: string): void {
    this.settings.set(this.viewsSetting(), removeView(this.views(), id));
  }

  protected onSort(sort: Sort): void {
    this.save({
      sort: sort.direction === '' ? null : { column: sort.active, direction: sort.direction },
    });
  }

  protected onPage(event: PageEvent): void {
    this.pageIndex.set(event.pageIndex);
    this.pageSize.set(event.pageSize);
  }

  protected toggleColumn(id: string): void {
    const entries = this.chooser().map((entry) =>
      entry.column.id === id && entry.column.hideable !== false
        ? { ...entry, visible: !entry.visible }
        : entry,
    );
    this.saveLayout(
      entries.map((entry) => entry.column.id),
      entries,
    );
  }

  protected move(id: string, delta: -1 | 1): void {
    const ids = this.chooser().map((entry) => entry.column.id);
    const from = ids.indexOf(id);
    const to = from + delta;
    if (from < 0 || to < 0 || to >= ids.length) return;
    moveItemInArray(ids, from, to);
    this.saveLayout(ids, this.chooser());
  }

  protected drop(event: CdkDragDrop<unknown>): void {
    const ids = this.chooser().map((entry) => entry.column.id);
    moveItemInArray(ids, event.previousIndex, event.currentIndex);
    this.saveLayout(ids, this.chooser());
  }

  protected resetColumns(): void {
    this.settings.reset(this.setting());
    this.pageIndex.set(0);
  }

  protected onResizeKey(event: KeyboardEvent, column: ListColumn<Row>): void {
    const delta =
      event.key === 'ArrowRight' ? RESIZE_STEP : event.key === 'ArrowLeft' ? -RESIZE_STEP : 0;
    if (delta === 0) return;
    event.preventDefault();
    event.stopPropagation();
    this.saveWidth(column.id, this.widthOf(column) + delta);
  }

  protected startResize(event: PointerEvent, column: ListColumn<Row>): void {
    event.preventDefault();
    event.stopPropagation();
    this.stopResize?.();
    const startX = event.clientX;
    const startWidth = this.renderedWidth(column, event);
    const view = this.document.defaultView;
    if (!view) return;

    const move = (moved: PointerEvent) =>
      this.liveWidth.set({
        id: column.id,
        width: clampWidth(Math.round(startWidth + moved.clientX - startX)),
      });
    const up = () => {
      const live = this.liveWidth();
      this.stopResize?.();
      if (live) this.saveWidth(live.id, live.width);
    };
    view.addEventListener('pointermove', move);
    view.addEventListener('pointerup', up);
    this.stopResize = () => {
      view.removeEventListener('pointermove', move);
      view.removeEventListener('pointerup', up);
      this.liveWidth.set(null);
      this.stopResize = null;
    };
  }

  /**
   * Where a pointer drag starts: the header as drawn, which a full-width table may have stretched past its
   * declared width. The cell hosts MatSortHeader, so a template variable would name that, not the element.
   */
  private renderedWidth(column: ListColumn<Row>, event: Event): number {
    const header = (event.currentTarget as HTMLElement | null)?.closest('th');
    return Math.round(header?.getBoundingClientRect().width ?? 0) || this.widthOf(column);
  }

  private saveWidth(id: string, width: number): void {
    this.save({ widths: { ...this.preferences().widths, [id]: clampWidth(width) } });
  }

  /** The chooser writes the whole layout, so a column hidden by default stays hidden once others are placed. */
  private saveLayout(
    order: string[],
    entries: readonly { column: ListColumn<Row>; visible: boolean }[],
  ): void {
    this.save({
      order,
      hidden: entries.filter((entry) => !entry.visible).map((entry) => entry.column.id),
    });
  }

  private save(patch: Partial<ListPreferences>): void {
    this.settings.set(this.setting(), { ...this.preferences(), ...patch });
  }
}

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
  effect,
  inject,
  input,
  linkedSignal,
  type OnInit,
  output,
  signal,
  TemplateRef,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatMenuModule } from '@angular/material/menu';
import { MatPaginatorModule, type PageEvent } from '@angular/material/paginator';
import { MatSortModule, type Sort } from '@angular/material/sort';
import { MatTableModule } from '@angular/material/table';
import { ActivatedRoute, type Params, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { SettingsFacade } from '../settings/settings-facade';
import { listPreferencesSetting, listViewsSetting } from '../settings/settings-registry';
import type {
  ListColumn,
  ListDescriptor,
  ListFilterValues,
  ListPreferences,
  ListQuery,
  ListSort,
  ListView,
  RowAction,
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
import { Label } from '../a11y/label';

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
/** How long typing pauses before a list the API pages asks for the words. */
export const LIST_SEARCH_PAUSE_MS = 300;

const clampWidth = (width: number): number => Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, width));

/** Unique enough for one person's views of one list; `crypto.randomUUID` needs a secure context. */
const newViewId = (): string =>
  `v${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;

/** What a view restores, in a comparable form: filter options in id order, so key order never matters. */
const viewState = (query: string, filters: ListFilterValues, layout: ListPreferences): string =>
  JSON.stringify([query, Object.entries(filters).sort(([a], [b]) => a.localeCompare(b)), layout]);

/**
 * Every list screen: a text filter and filters offered as counted choices, saved views, sortable columns a person may hide, reorder (by dragging or with buttons) and
 * resize (by pointer or keyboard), pages, and an empty state. What it shows comes from the screen's descriptor
 * and the pure functions in list-view.ts; what a person chose is kept through the presentation settings.
 *
 * A list the API pages (docs/SPEC.md § 7, lists at scale) is given its `total`: the rows are then one page, shown as
 * they come, and the list says through `queryChange` which words, filter options, sort and page it wants. That state
 * also lives in the address (`q`, one parameter per filter, `sort` as `name` or `-name`, `page` from 1, `size`), so a
 * reload, a bookmark or a shared link opens the same page; each change replaces the address rather than adding to
 * the history.
 */
@Component({
  selector: 'app-data-list',
  imports: [
    Label,
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
    MatMenuModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './data-list.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DataList<Row> implements OnInit {
  private readonly settings = inject(SettingsFacade);
  private readonly router = inject(Router, { optional: true });
  private readonly route = inject(ActivatedRoute, { optional: true });
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);

  readonly descriptor = input.required<ListDescriptor<Row>>();
  readonly rows = input.required<readonly Row[]>();
  readonly testId = input('data-list');
  readonly rowTestId = input<((row: Row) => string) | null>(null);
  readonly emptyKey = input.required<string>();
  readonly emptyTestId = input('data-list-empty');
  /** How many rows the whole list holds when the API pages it; null when `rows` is the whole list. */
  readonly total = input<number | null>(null);
  /** What a list the API pages wants shown: emitted on opening and on every change a person makes. */
  readonly queryChange = output<ListQuery>();

  private readonly cells = contentChildren(DataListCell);
  protected readonly actions = contentChild(DataListRowActions);

  private readonly setting = computed(() => listPreferencesSetting(this.descriptor().id));
  private readonly stored = computed(() => this.settings.value(this.setting()));
  protected readonly preferences = computed(() => this.stored()());
  private readonly viewsSetting = computed(() => listViewsSetting(this.descriptor().id));
  protected readonly views = computed(() => this.settings.value(this.viewsSetting())());

  protected readonly query = signal('');
  /** The words a list the API pages asked for: what was typed, once typing paused. */
  private readonly searched = signal('');
  private searchTimer: ReturnType<typeof setTimeout> | null = null;
  private lastQuery: string | null = null;
  protected readonly byApi = computed(() => this.total() !== null);
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
  /**
   * The row's declared actions, split the way the trailing column draws them: the frequent ones as buttons, the
   * rare and the destructive ones behind "⋮" (design review finding 1). Destructive is never a button, whatever
   * its frequency — a button under the pointer is the wrong place for it.
   */
  protected readonly buttonActions = computed(() =>
    (this.descriptor().actions ?? []).filter(
      (action) => action.rare !== true && action.destructive !== true,
    ),
  );
  protected readonly menuActions = computed(() =>
    (this.descriptor().actions ?? []).filter(
      (action) => action.rare === true || action.destructive === true,
    ),
  );
  /** Whether the row declares anything at all for its trailing column, projected template included. */
  protected readonly hasRowControls = computed(
    () => this.actions() !== undefined || (this.descriptor().actions ?? []).length > 0,
  );

  /**
   * Which column carries the link that opens the record: the one the list named, else the first that cannot be
   * hidden — a link a person can hide is a record they can no longer open.
   */
  protected readonly linkColumnId = computed(() => {
    const descriptor = this.descriptor();
    if (descriptor.link === undefined) return null;
    return (
      descriptor.linkColumn ??
      descriptor.columns.find((column) => column.hideable === false)?.id ??
      descriptor.columns[0]?.id ??
      null
    );
  });

  protected readonly columnIds = computed(() => [
    ...this.columns().map((column) => column.id),
    ...(this.hasRowControls() ? [ACTIONS_COLUMN] : []),
  ]);

  /** The actions of one row, with those it cannot take left out rather than shown disabled. */
  protected shownActions(actions: readonly RowAction<Row>[], row: Row): readonly RowAction<Row>[] {
    return actions.filter((action) => action.shown?.(row) ?? true);
  }

  protected runAction(action: RowAction<Row>, row: Row): void {
    action.run?.(row);
  }
  protected readonly chooser = computed(() =>
    orderColumns(this.descriptor(), this.preferences()).map((column) => ({
      column,
      visible: isColumnVisible(column, this.preferences()),
    })),
  );
  /** A sort the address named: it wins over the one a person saved until they pick another. */
  private readonly addressSort = signal<ListSort | null>(null);
  protected readonly sort = computed<ListSort | null>(
    () => this.addressSort() ?? this.preferences().sort ?? this.descriptor().defaultSort ?? null,
  );
  protected readonly filterable = computed(() =>
    this.descriptor().columns.some((column) => column.filterable),
  );
  protected readonly filters = computed(() => this.descriptor().filters ?? []);
  /**
   * Each filter's choices with how many rows each would show: counted over the rows the text filter and the other
   * filters leave, so a number never promises rows the next click cannot deliver. The rows are all on the page, so
   * the count is exact.
   */
  protected readonly facets = computed(() => {
    const chosen = this.chosenFilters();
    if (this.byApi()) {
      // The page holds a part of the rows only: a count over it would be wrong, so none is shown.
      return this.filters().map((filter) => ({
        filter,
        chosen: chosen[filter.id] ?? '',
        total: null,
        options: filter.options.map((option) => ({ option, count: null })),
      }));
    }
    const searched = filterRows(this.rows(), this.descriptor().columns, this.query());
    return this.filters().map((filter) => {
      const others = Object.fromEntries(Object.entries(chosen).filter(([id]) => id !== filter.id));
      const base = applyFilters(searched, this.filters(), others);
      return {
        filter,
        chosen: chosen[filter.id] ?? '',
        total: base.length,
        options: filter.options.map((option) => ({
          option,
          count: base.filter((row) => filter.value(row) === option.value).length,
        })),
      };
    });
  });
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
    const total = this.total();
    if (total !== null) {
      return { rows: this.rows(), pageIndex: this.pageIndex(), total };
    }
    const columns = this.descriptor().columns;
    const narrowed = applyFilters(this.rows(), this.filters(), this.chosenFilters());
    const shown = sortRows(filterRows(narrowed, columns, this.query()), columns, this.sort());
    return paginate(shown, this.pageIndex(), this.pageSize());
  });
  protected readonly paged = computed(
    () => this.page().total > (this.descriptor().pageSizes[0] ?? Infinity),
  );
  /** Nothing to show because there is nothing at all, not because of what a person asked for. */
  protected readonly empty = computed(
    () =>
      this.rows().length === 0 &&
      (!this.byApi() || (this.searched() === '' && Object.keys(this.chosenFilters()).length === 0)),
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
  /** The rows this list has shown since it opened, by id; null until it first has some (docs/SPEC.md § 7, 2026-09-17). */
  private seen: Set<string> | null = null;
  /** The rows that arrived last while the list was open: another tab or person added them. */
  protected readonly arrived = signal<ReadonlySet<string>>(new Set());
  /** How many of those the page does not show, sorted onto another page or hidden by a filter. */
  protected readonly arrivedElsewhere = computed(() => {
    const arrived = this.arrived();
    if (arrived.size === 0 || this.byApi()) return 0;
    const rowId = this.descriptor().rowId;
    const onPage = new Set(this.page().rows.map(rowId));
    return this.rows().filter((row) => arrived.has(rowId(row)) && !onPage.has(rowId(row))).length;
  });

  constructor() {
    this.destroyRef.onDestroy(() => {
      this.stopResize?.();
      if (this.searchTimer !== null) clearTimeout(this.searchTimer);
    });
    effect(() => {
      if (!this.byApi()) return;
      const query: ListQuery = {
        query: this.searched(),
        filters: this.chosenFilters(),
        sort: this.sort(),
        pageIndex: this.pageIndex(),
        pageSize: this.pageSize(),
      };
      untracked(() => this.ask(query));
    });
    effect(() => {
      const rows = this.rows();
      untracked(() => this.noteArrivals(rows));
    });
  }

  /** A list the API pages opens on what the address names; anything it does not recognise is left out. */
  ngOnInit(): void {
    const params = this.route?.snapshot.queryParamMap;
    if (!this.byApi() || !params) return;
    const descriptor = this.descriptor();
    const words = params.get('q') ?? '';
    this.query.set(words);
    this.searched.set(words);
    const chosen: ListFilterValues = {};
    for (const filter of descriptor.filters ?? []) {
      const value = params.get(filter.id);
      if (filter.options.some((option) => option.value === value) && value !== null) {
        chosen[filter.id] = value;
      }
    }
    this.chosenFilters.set(chosen);
    const sort = params.get('sort');
    const column = sort?.replace(/^-/, '');
    if (sort && descriptor.columns.some((known) => known.id === column && known.sortable)) {
      this.addressSort.set({ column: column!, direction: sort.startsWith('-') ? 'desc' : 'asc' });
    }
    const size = Number(params.get('size'));
    if (descriptor.pageSizes.includes(size)) this.pageSize.set(size);
    const page = Number(params.get('page'));
    if (Number.isInteger(page) && page > 1) this.pageIndex.set(page - 1);
  }

  /**
   * Asks for another page unless it is the one already asked for, as an option picked again or a view with the same
   * choices would. The next rows answer a new question, so none of them "arrived".
   */
  private ask(query: ListQuery): void {
    const key = JSON.stringify([
      query.query,
      Object.entries(query.filters).sort(([a], [b]) => a.localeCompare(b)),
      query.sort,
      query.pageIndex,
      query.pageSize,
    ]);
    if (key === this.lastQuery) return;
    this.lastQuery = key;
    this.seen = null;
    this.arrived.set(new Set());
    this.queryChange.emit(query);
    this.keepInAddress(query);
  }

  /** Writes the state into the address, leaving it untouched when it already says so (as on opening). */
  private keepInAddress(query: ListQuery): void {
    if (!this.router || !this.route) return;
    const descriptor = this.descriptor();
    const defaultSort = descriptor.defaultSort ?? null;
    const sorted =
      query.sort === null ||
      (query.sort.column === defaultSort?.column && query.sort.direction === defaultSort.direction)
        ? null
        : `${query.sort.direction === 'desc' ? '-' : ''}${query.sort.column}`;
    const queryParams: Params = {
      q: query.query === '' ? null : query.query,
      ...Object.fromEntries(
        (descriptor.filters ?? []).map((filter) => [filter.id, query.filters[filter.id] ?? null]),
      ),
      sort: sorted,
      page: query.pageIndex === 0 ? null : String(query.pageIndex + 1),
      size: query.pageSize === descriptor.pageSizes[0] ? null : String(query.pageSize),
    };
    const current = this.route.snapshot.queryParamMap;
    if (Object.entries(queryParams).every(([key, value]) => current.get(key) === value)) return;
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams,
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  /** The words take effect at once on a list with every row, after a pause on a list the API pages. */
  private search(words: string, pause: boolean): void {
    this.query.set(words);
    this.pageIndex.set(0);
    if (this.searchTimer !== null) clearTimeout(this.searchTimer);
    this.searchTimer = null;
    if (!pause || !this.byApi()) {
      this.searched.set(words);
      return;
    }
    this.searchTimer = setTimeout(() => {
      this.searchTimer = null;
      this.searched.set(words);
    }, LIST_SEARCH_PAUSE_MS);
  }

  protected isArrived(row: Row): boolean {
    return this.arrived().has(this.descriptor().rowId(row));
  }

  /** Shows the page holding the first row that arrived out of sight, clearing the filters if they hide it. */
  protected showArrivals(): void {
    const rowId = this.descriptor().rowId;
    const onPage = new Set(this.page().rows.map(rowId));
    const target = this.rows().find(
      (row) => this.arrived().has(rowId(row)) && !onPage.has(rowId(row)),
    );
    if (target === undefined) return;
    const columns = this.descriptor().columns;
    const shownWith = (query: string, chosen: ListFilterValues): readonly Row[] =>
      sortRows(
        filterRows(applyFilters(this.rows(), this.filters(), chosen), columns, query),
        columns,
        this.sort(),
      );
    let shown = shownWith(this.query(), this.chosenFilters());
    if (!shown.includes(target)) {
      this.search('', false);
      this.chosenFilters.set({});
      shown = shownWith('', {});
    }
    this.pageIndex.set(Math.floor(shown.indexOf(target) / this.pageSize()));
  }

  private noteArrivals(rows: readonly Row[]): void {
    const ids = rows.map(this.descriptor().rowId);
    if (this.seen === null) {
      if (ids.length > 0) this.seen = new Set(ids);
      return;
    }
    const seen = this.seen;
    const fresh = ids.filter((id) => !seen.has(id));
    for (const id of fresh) seen.add(id);
    if (fresh.length > 0) this.arrived.set(new Set(fresh));
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
    this.search((event.target as HTMLInputElement).value, true);
  }

  protected clearFilter(): void {
    this.search('', false);
  }

  /** Picks one option of a filter; the empty value shows every row again. */
  protected onFacet(filterId: string, value: string): void {
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
    this.search(view.query, false);
    this.chosenFilters.set({ ...view.filters });
    this.settings.set(this.setting(), view.layout);
  }

  protected deleteView(id: string): void {
    this.settings.set(this.viewsSetting(), removeView(this.views(), id));
  }

  protected onSort(sort: Sort): void {
    this.addressSort.set(null);
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

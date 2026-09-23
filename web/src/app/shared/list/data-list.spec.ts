// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { ActivatedRoute, convertToParamMap, provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { listPreferencesSetting, listViewsSetting } from '../settings/settings-registry';
import { DataList, DataListCell } from './data-list';
import { WINDOW_CLASS, type WindowClass } from '../ui/window-class';
import type { ListDescriptor, ListPreferences, ListQuery, ListView } from './list-types';
import { NO_LIST_PREFERENCES } from './list-types';

interface Customer {
  id: string;
  name: string;
  city: string;
  balance: number;
  status: 'active' | 'archived';
}

const all: Customer[] = Array.from({ length: 30 }, (_, index) => ({
  id: String(index + 1),
  name: `Customer ${String(index + 1).padStart(2, '0')}`,
  city: index % 3 === 0 ? 'Sfax' : 'Paris',
  balance: index * 10,
  status: index % 2 === 0 ? 'active' : 'archived',
}));

const descriptor: ListDescriptor<Customer> = {
  id: 'customers',
  rowId: (row) => row.id,
  pageSizes: [10, 25],
  columns: [
    {
      id: 'name',
      label: 'c.name',
      value: (row) => row.name,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    { id: 'city', label: 'c.city', value: (row) => row.city, sortable: true, filterable: true },
    {
      id: 'balance',
      label: 'c.balance',
      value: (row) => row.balance,
      sortable: true,
      align: 'end',
      width: 120,
    },
    { id: 'status', label: 'c.status', value: (row) => row.status },
  ],
  filters: [
    {
      id: 'status',
      label: 'c.status',
      value: (row) => row.status,
      options: [
        { value: 'active', label: 'c.active' },
        { value: 'archived', label: 'c.archived' },
      ],
    },
  ],
};

@Component({
  imports: [DataList, DataListCell],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-data-list
      [descriptor]="descriptor"
      [rows]="rows()"
      testId="customers-table"
      [rowTestId]="rowTestId"
      emptyKey="c.none"
      emptyTestId="customers-empty"
    >
      <ng-template appDataListCell="status" let-row>
        <b data-testid="status-cell">{{ row.status === 'active' ? 'ACTIVE' : 'ARCHIVED' }}</b>
      </ng-template>
    </app-data-list>
  `,
})
class Host {
  readonly descriptor = descriptor;
  readonly rows = signal<Customer[]>(all);
  readonly rowTestId = (row: Customer) => `customer-${row.id}`;
}

/** A screen whose API pages the list: it hands over one page and the total, and reads what the list asks for. */
@Component({
  imports: [DataList],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-data-list
      [descriptor]="descriptor"
      [rows]="rows()"
      [total]="total()"
      (queryChange)="queries.push($event)"
      testId="customers-table"
      [rowTestId]="rowTestId"
      emptyKey="c.none"
      emptyTestId="customers-empty"
    />
  `,
})
class ServerHost {
  readonly descriptor = descriptor;
  readonly rows = signal<Customer[]>([...all].reverse().slice(0, 10));
  readonly total = signal(30);
  readonly queries: ListQuery[] = [];
  readonly rowTestId = (row: Customer) => `customer-${row.id}`;
}

const ran: string[] = [];

/** A list declaring what finding 1 asks for: the row a real link, and its actions declared once. */
const declared: ListDescriptor<Customer> = {
  ...descriptor,
  link: (row) => ['/customers', row.id],
  actions: [
    {
      id: 'call',
      label: 'c.call',
      icon: 'call',
      run: (row) => ran.push(`call:${row.id}`),
      disabled: (row) => row.id === '3',
    },
    {
      id: 'invoice',
      label: 'c.invoice',
      icon: 'receipt',
      link: () => ['/invoices/new'],
      linkQuery: (row) => ({ customerId: row.id }),
    },
    {
      id: 'export',
      label: 'c.export',
      icon: 'download',
      rare: true,
      run: (row) => ran.push(`export:${row.id}`),
    },
    {
      id: 'archive',
      label: 'c.archive',
      icon: 'delete',
      destructive: true,
      run: (row) => ran.push(`archive:${row.id}`),
      shown: (row) => row.status === 'active',
      // A function of the row, so the question can name it: "Archiver Customer 01 ?" rather than a list of
      // eleven identical questions.
      confirm: (row) => ({
        title: 'c.archive_title',
        message: 'c.archive_message',
        messageParams: { name: row.name },
        confirmLabel: 'c.archive_confirm',
        keepLabel: 'c.keep',
      }),
    },
  ],
};

@Component({
  imports: [DataList, DataListCell],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-data-list
      [descriptor]="descriptor"
      [rows]="rows()"
      testId="customers-table"
      [rowTestId]="rowTestId"
      emptyKey="c.none"
      emptyTestId="customers-empty"
    >
      <ng-template appDataListCell="name" let-row>
        {{ row.name }}<b data-testid="name-extra">!</b>
      </ng-template>
    </app-data-list>
  `,
})
class DeclaredHost {
  readonly descriptor = declared;
  readonly rows = signal<Customer[]>(all.slice(0, 3));
  readonly rowTestId = (row: Customer) => `customer-${row.id}`;
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      c: {
        name: 'Name',
        city: 'City',
        balance: 'Balance',
        status: 'Status',
        call: 'Call',
        invoice: 'Invoice',
        export: 'Export',
        archive: 'Archive',
        none: 'No customers.',
        active: 'Active',
        archived: 'Archived',
        archive_title: 'Archive?',
        archive_message: 'Archive {{name}}?',
        archive_confirm: 'Archive',
        keep: 'Keep',
      },
      list: {
        filter: 'Filter',
        filter_clear: 'Clear',
        columns: 'Columns',
        columns_reset: 'Reset columns',
        move_column: 'Move {{column}}',
        resize_column: 'Resize {{column}}',
        no_match: 'Nothing matches {{query}}',
        no_match_filters: 'Nothing matches these filters',
        filter_any: 'All',
        views: 'Views',
        views_none: 'No saved views',
        view_name: 'View name',
        view_save: 'Save view',
        view_apply: 'Apply {{name}}',
        view_delete: 'Delete {{name}}',
        actions: 'Actions',
        more_actions: 'More actions',
        new_row: '{{count}} new row',
        new_rows: '{{count}} new rows',
      },
    });
  }
}

describe('DataList', () => {
  let storage: PageMemoryStorage;
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`) as HTMLElement | null;
  const all$ = (selector: string): HTMLElement[] =>
    Array.from(fixture.nativeElement.querySelectorAll(selector)) as HTMLElement[];
  const headers = () =>
    all$('[data-testid^="list-header-"]').map((cell) =>
      cell.getAttribute('data-testid')!.replace('list-header-', ''),
    );
  const rowIds = () =>
    all$('[data-testid^="customer-"]').map((row) =>
      row.getAttribute('data-testid')!.replace('customer-', ''),
    );
  const preferences = (): ListPreferences =>
    TestBed.inject(SettingsFacade).value(listPreferencesSetting('customers'))();

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const views = (): ListView[] =>
    TestBed.inject(SettingsFacade).value(listViewsSetting('customers'))();

  async function type(testId: string, value: string): Promise<void> {
    const field = q(testId) as HTMLInputElement;
    field.value = value;
    field.dispatchEvent(new Event('input'));
    await settle();
  }

  async function mount(
    saved?: ListPreferences,
    savedViews?: ListView[],
    initialRows: Customer[] = all,
    queryParams: Record<string, string> = {},
  ): Promise<void> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { queryParamMap: convertToParamMap(queryParams) } },
        },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: storage },
        { provide: Session, useValue: { me: () => ({ user: { id: 'u1' } }) } },
      ],
    });
    if (saved) TestBed.inject(SettingsFacade).set(listPreferencesSetting('customers'), saved);
    if (savedViews) TestBed.inject(SettingsFacade).set(listViewsSetting('customers'), savedViews);
    fixture = TestBed.createComponent(Host);
    fixture.componentInstance.rows.set(initialRows);
    await settle();
  }

  beforeEach(async () => {
    storage = new PageMemoryStorage();
    await mount();
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('shows the visible columns and the first page of rows, with the test ids the screen asked for', () => {
    expect(q('customers-table')).not.toBeNull();
    expect(headers()).toEqual(['name', 'city', 'balance', 'status']);
    expect(rowIds()).toEqual(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']);
    // Each cell names its column, so a reader finds a value by what it is rather than where it stands.
    const cells = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        'tbody tr:first-child td[data-column]',
      ),
    ).map((cell) => cell.getAttribute('data-column'));
    expect(cells).toEqual(['name', 'city', 'balance', 'status']);
  });

  it('renders a cell through the template the screen gave, and gives a list without actions no trailing column', () => {
    expect(q('customer-1')?.querySelector('[data-testid="status-cell"]')?.textContent?.trim()).toBe(
      'ACTIVE',
    );
    expect(q('customer-1')?.querySelector('.twes-row-actions')).toBeNull();
  });

  it('narrows the rows as a person types a filter, and says when nothing matches', async () => {
    const filter = q('list-filter') as HTMLInputElement;

    filter.value = 'sfax';
    filter.dispatchEvent(new Event('input'));
    await settle();
    expect(rowIds()).toEqual(['1', '4', '7', '10', '13', '16', '19', '22', '25', '28']);

    filter.value = 'nowhere';
    filter.dispatchEvent(new Event('input'));
    await settle();
    expect(rowIds()).toEqual([]);
    expect(q('list-no-match')?.textContent).toContain('nowhere');
    expect(q('customers-empty')).toBeNull();
  });

  it('shows the empty state when there are no rows at all', async () => {
    fixture.componentInstance.rows.set([]);
    await settle();

    expect(q('customers-empty')?.textContent?.trim()).toBe('No customers.');
    expect(q('list-no-match')).toBeNull();
  });

  it('sorts on a header click and remembers the sort', async () => {
    q('list-header-name')!.click();
    await settle();
    q('list-header-name')!.click();
    await settle();

    expect(rowIds()[0]).toBe('30');
    expect(preferences().sort).toEqual({ column: 'name', direction: 'desc' });
  });

  it('hides a column from the chooser and remembers it', async () => {
    q('list-columns')!.click();
    await settle();
    q('list-column-toggle-city')!.click();
    await settle();

    expect(headers()).toEqual(['name', 'balance', 'status']);
    expect(preferences().hidden).toEqual(['city']);
  });

  it('offers no way to hide a column the screen says must stay', async () => {
    q('list-columns')!.click();
    await settle();

    expect((q('list-column-toggle-name') as HTMLInputElement | null)?.disabled ?? true).toBe(true);
  });

  it('moves a column without dragging, and keeps the order', async () => {
    q('list-columns')!.click();
    await settle();
    q('list-column-up-balance')!.click();
    await settle();

    expect(headers()).toEqual(['name', 'balance', 'city', 'status']);
    expect(preferences().order).toEqual(['name', 'balance', 'city', 'status']);
  });

  it('resizes a column from the keyboard and keeps the width', async () => {
    q('list-resize-balance')!.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
    );
    await settle();

    expect(preferences().widths).toEqual({ balance: 136 });
    expect(q('list-header-balance')!.style.width).toBe('136px');
  });

  it('starts from what a person saved', async () => {
    await mount({
      ...NO_LIST_PREFERENCES,
      hidden: ['balance'],
      sort: { column: 'balance', direction: 'desc' },
    });

    expect(headers()).toEqual(['name', 'city', 'status']);
    expect(rowIds()[0]).toBe('30');
  });

  it('resets the columns to the declared layout', async () => {
    await mount({ ...NO_LIST_PREFERENCES, hidden: ['city'] });
    q('list-columns')!.click();
    await settle();
    q('list-columns-reset')!.click();
    await settle();

    expect(headers()).toEqual(['name', 'city', 'balance', 'status']);
    expect(preferences()).toEqual(NO_LIST_PREFERENCES);
  });

  it('pages through the rows', async () => {
    (
      fixture.nativeElement.querySelector('.mat-mdc-paginator-navigation-next') as HTMLButtonElement
    ).click();
    await settle();

    expect(rowIds()[0]).toBe('11');
  });

  it('gives every resize handle the width it controls, as a focusable separator must', () => {
    const handles = all$('[data-testid^="list-resize-"]');

    expect(handles).toHaveLength(4);
    for (const handle of handles) {
      expect(Number(handle.getAttribute('aria-valuenow'))).toBeGreaterThan(0);
      expect(handle.getAttribute('aria-valuemin')).not.toBeNull();
      expect(handle.getAttribute('aria-valuemax')).not.toBeNull();
    }
    expect(q('list-resize-balance')!.getAttribute('aria-valuenow')).toBe('120');
  });

  it('keeps the table as wide as its columns, so a narrow screen scrolls the list and not the page', () => {
    // name, city and status declare no width (160 each), balance declares 120; a list that declares no action
    // has no trailing column and does not pay the 96 px it would cost.
    expect(q('customers-table')!.style.minWidth).toBe('600px');
  });

  it('offers each filter as a group of choices, each saying how many rows it would show', async () => {
    const chip = (value: string) => q(`list-facet-status-${value}`)!;
    const label = (value: string) => chip(value).textContent?.replace(/\s+/g, ' ').trim();

    expect(q('list-facet-status')?.getAttribute('role')).toBe('group');
    expect(q('list-facet-status')?.getAttribute('aria-label')).toBe('Status');
    expect([label('all'), label('active'), label('archived')]).toEqual([
      'All 30',
      'Active 15',
      'Archived 15',
    ]);
    expect(chip('all').getAttribute('aria-pressed')).toBe('true');
    expect(chip('archived').getAttribute('aria-pressed')).toBe('false');

    await type('list-filter', 'sfax');
    expect([label('all'), label('active'), label('archived')]).toEqual([
      'All 10',
      'Active 5',
      'Archived 5',
    ]);

    chip('archived').click();
    await settle();
    expect(chip('archived').getAttribute('aria-pressed')).toBe('true');
    expect(chip('all').getAttribute('aria-pressed')).toBe('false');
    expect(label('all')).toBe('All 10');

    chip('all').click();
    await settle();
    expect(rowIds()).toHaveLength(10);
    expect(chip('all').getAttribute('aria-pressed')).toBe('true');
  });

  it('narrows the rows to the option picked in a filter, and says when the filters match nothing', async () => {
    q('list-facet-status-archived')!.click();
    await settle();

    expect(rowIds()).toEqual(['2', '4', '6', '8', '10', '12', '14', '16', '18', '20']);

    await type('list-filter', 'Customer 01');
    expect(rowIds()).toEqual([]);
    expect(q('list-no-match')?.textContent).toContain('Customer 01');

    await type('list-filter', '');
    fixture.componentInstance.rows.set(all.filter((row) => row.status === 'active'));
    await settle();
    expect(q('list-no-match')?.textContent?.trim()).toBe('Nothing matches these filters');
  });

  it('saves what is shown as a named view, and brings it back after the screen changed', async () => {
    await type('list-filter', 'sfax');
    q('list-columns')!.click();
    await settle();
    q('list-column-toggle-city')!.click();
    await settle();

    q('list-views')!.click();
    await settle();
    expect((q('list-view-save') as HTMLButtonElement).disabled).toBe(true);
    await type('list-view-name', '  Sfax only ');
    q('list-view-save')!.click();
    await settle();

    const [saved] = views();
    expect(views()).toHaveLength(1);
    expect(saved).toMatchObject({ name: 'Sfax only', query: 'sfax', filters: {} });
    expect(saved.layout.hidden).toEqual(['city']);

    q('list-columns-reset')!.click();
    await type('list-filter', '');
    expect(headers()).toEqual(['name', 'city', 'balance', 'status']);

    q(`list-view-apply-${saved.id}`)!.click();
    await settle();
    expect(headers()).toEqual(['name', 'balance', 'status']);
    expect((q('list-filter') as HTMLInputElement).value).toBe('sfax');
    expect(rowIds()).toEqual(['1', '4', '7', '10', '13', '16', '19', '22', '25', '28']);
    expect(q(`list-view-apply-${saved.id}`)!.getAttribute('aria-pressed')).toBe('true');
  });

  it('applies a saved view that picked a filter option', async () => {
    await mount(undefined, [
      {
        id: 'v1',
        name: 'Archived',
        query: '',
        filters: { status: 'archived' },
        layout: NO_LIST_PREFERENCES,
      },
    ]);
    q('list-views')!.click();
    await settle();
    q('list-view-apply-v1')!.click();
    await settle();

    expect(q('list-facet-status-archived')!.getAttribute('aria-pressed')).toBe('true');
    expect(rowIds()[0]).toBe('2');
  });

  it('deletes a saved view', async () => {
    const view: ListView = {
      id: 'v1',
      name: 'Mine',
      query: '',
      filters: {},
      layout: NO_LIST_PREFERENCES,
    };
    await mount(undefined, [view, { ...view, id: 'v2', name: 'Theirs' }]);
    q('list-views')!.click();
    await settle();
    q('list-view-delete-v1')!.click();
    await settle();

    expect(views().map((kept) => kept.id)).toEqual(['v2']);
    expect(q('list-view-apply-v1')).toBeNull();
  });

  describe('when the API pages the list', () => {
    let server: ComponentFixture<ServerHost>;
    const queries = (): ListQuery[] => server.componentInstance.queries;
    const lastQuery = (): ListQuery | undefined => queries().at(-1);
    const serverRowIds = () =>
      (
        Array.from(
          server.nativeElement.querySelectorAll('[data-testid^="customer-"]'),
        ) as HTMLElement[]
      ).map((row) => row.getAttribute('data-testid')!.replace('customer-', ''));
    const pause = () => new Promise((resolve) => setTimeout(resolve, 350));

    async function settleServer(): Promise<void> {
      server.detectChanges();
      await server.whenStable();
      server.detectChanges();
    }

    beforeEach(async () => {
      fixture.destroy();
      server = TestBed.createComponent(ServerHost);
      await settleServer();
    });

    afterEach(() => server.destroy());

    it('shows the page it was given as it is, counts the pages by the total, and asks for the first page', () => {
      expect(serverRowIds()).toEqual(['30', '29', '28', '27', '26', '25', '24', '23', '22', '21']);
      expect(q('list-paginator')?.textContent).toContain('30');
      expect(queries()).toEqual([
        { query: '', filters: {}, sort: null, pageIndex: 0, pageSize: 10 },
      ]);
    });

    it('asks for the words a person typed once they pause, from the first page', async () => {
      const field = q('list-filter') as HTMLInputElement;
      field.value = 'sfa';
      field.dispatchEvent(new Event('input'));
      field.value = 'sfax';
      field.dispatchEvent(new Event('input'));
      await settleServer();
      expect(queries()).toHaveLength(1);

      await pause();
      await settleServer();

      expect(queries()).toHaveLength(2);
      expect(lastQuery()).toMatchObject({ query: 'sfax', pageIndex: 0 });
    });

    it('asks for a sort, a filter option and another page, and counts no choice it cannot see', async () => {
      q('list-header-name')!.click();
      await settleServer();
      expect(lastQuery()?.sort).toEqual({ column: 'name', direction: 'asc' });

      expect(q('list-facet-status-active')?.querySelector('.twes-chip-count')).toBeNull();
      q('list-facet-status-active')!.click();
      await settleServer();
      expect(lastQuery()).toMatchObject({ filters: { status: 'active' }, pageIndex: 0 });

      (
        q('list-paginator')!.querySelector(
          '.mat-mdc-paginator-navigation-next',
        ) as HTMLButtonElement
      ).click();
      await settleServer();
      expect(lastQuery()?.pageIndex).toBe(1);
    });

    it('asks nothing again for what it already asked, such as an option picked twice', async () => {
      q('list-facet-status-active')!.click();
      await settleServer();
      expect(queries()).toHaveLength(2);

      q('list-facet-status-active')!.click();
      q('list-resize-city')!.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }));
      await settleServer();

      expect(queries()).toHaveLength(2);
    });

    it('highlights a row a reload brought onto the page, and none once a person asked for another page', async () => {
      server.componentInstance.rows.set([{ ...all[0], id: 'n1' }, ...all.slice(0, 9)]);
      await settleServer();
      expect(q('customer-n1')?.classList).toContain('twes-row-new');

      (
        q('list-paginator')!.querySelector(
          '.mat-mdc-paginator-navigation-next',
        ) as HTMLButtonElement
      ).click();
      await settleServer();
      server.componentInstance.rows.set(all.slice(10, 20));
      await settleServer();

      expect(server.nativeElement.querySelectorAll('.twes-row-new')).toHaveLength(0);
      expect(q('list-new')).toBeNull();
    });

    it('opens on the page, words, filter options, sort and size the address names', async () => {
      server.destroy();
      await mount(undefined, undefined, all, {
        q: 'sfax',
        status: 'archived',
        sort: '-name',
        page: '2',
        size: '25',
        unknown: 'x',
      });
      fixture.destroy();
      server = TestBed.createComponent(ServerHost);
      await settleServer();

      expect(queries()).toEqual([
        {
          query: 'sfax',
          filters: { status: 'archived' },
          sort: { column: 'name', direction: 'desc' },
          pageIndex: 1,
          pageSize: 25,
        },
      ]);
      expect((q('list-filter') as HTMLInputElement).value).toBe('sfax');
      expect(q('list-facet-status-archived')?.getAttribute('aria-pressed')).toBe('true');
    });

    it('keeps what a person chose in the address, in place of the previous one', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);

      q('list-facet-status-active')!.click();
      q('list-header-name')!.click();
      await settleServer();

      expect(navigate).toHaveBeenLastCalledWith([], {
        relativeTo: TestBed.inject(ActivatedRoute),
        queryParams: { q: null, status: 'active', sort: 'name', page: null, size: null },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
    });

    it('says the list is empty, or that nothing matches what a person asked for', async () => {
      server.componentInstance.rows.set([]);
      server.componentInstance.total.set(0);
      await settleServer();
      expect(q('customers-empty')).not.toBeNull();

      q('list-facet-status-archived')!.click();
      await settleServer();
      expect(q('customers-empty')).toBeNull();
      expect(q('list-no-match')).not.toBeNull();
    });
  });

  describe('while it is open and rows arrive', () => {
    const arrived = (overrides: Partial<Customer>): Customer => ({
      id: 'n1',
      name: 'Customer 00',
      city: 'Paris',
      balance: 0,
      status: 'active',
      ...overrides,
    });

    it('highlights a row that arrived, and none of the rows already shown', async () => {
      fixture.componentInstance.rows.set([arrived({}), ...all]);
      await settle();

      expect(q('customer-n1')?.classList).toContain('twes-row-new');
      expect(q('customer-1')?.classList).not.toContain('twes-row-new');
      expect(q('list-new')).toBeNull();
    });

    it('highlights nothing when the rows are first read', async () => {
      await mount(undefined, undefined, []);

      fixture.componentInstance.rows.set(all);
      await settle();

      expect(all$('.twes-row-new')).toHaveLength(0);
    });

    it('says how many arrived beyond the page shown, and shows them on request', async () => {
      fixture.componentInstance.rows.set([...all, arrived({ name: 'Customer 99' })]);
      await settle();

      expect(q('list-new')?.textContent).toContain('1');
      q('list-new')!.click();
      await settle();

      expect(rowIds()).toContain('n1');
      expect(q('customer-n1')?.classList).toContain('twes-row-new');
      expect(q('list-new')).toBeNull();
    });

    it('clears the filters that hide a row which arrived when asked to show it', async () => {
      await type('list-filter', 'sfax');
      fixture.componentInstance.rows.set([arrived({ city: 'Tunis' }), ...all]);
      await settle();

      q('list-new')!.click();
      await settle();

      expect((q('list-filter') as HTMLInputElement).value).toBe('');
      expect(rowIds()).toContain('n1');
    });
  });

  describe('the row as a link and its declared actions', () => {
    let host: ComponentFixture<DeclaredHost>;
    const inRow = (id: string, selector: string): HTMLElement | null =>
      host.nativeElement.querySelector(`[data-testid="customer-${id}"] ${selector}`);

    beforeEach(async () => {
      ran.length = 0;
      host = TestBed.createComponent(DeclaredHost);
      host.detectChanges();
      await host.whenStable();
      host.detectChanges();
    });

    it('pays for the trailing column only where actions are declared', () => {
      // The width has to include the actions column, or a table at its minimum width clips the controls it just
      // pinned to its right edge.
      expect(
        (host.nativeElement.querySelector('[data-testid="customers-table"]') as HTMLElement).style
          .minWidth,
      ).toBe('696px');
    });

    it('opens the record through a real link on the row, not a click handler', async () => {
      // Design review finding 1: a real link is what makes a middle click, a copied address and a screen reader's
      // list of links work. A row that opens on (click) gives none of those.
      const link = inRow('1', 'a[data-testid="list-link-1"]');
      expect(link?.tagName).toBe('A');
      expect(link?.getAttribute('href')).toBe('/customers/1');
      expect(link?.textContent).toContain('Customer 01');
      // The link sits on the row's name, the column that cannot be hidden — not on every cell.
      expect(inRow('1', '[data-testid="list-link-1"]')?.closest('td')?.textContent).toContain(
        'Customer 01',
      );
      expect(host.nativeElement.querySelectorAll('a[data-testid^="list-link-"]')).toHaveLength(3);
    });

    it('wraps the cell template rather than replacing it, since the naming column usually has one', async () => {
      // The column that names a record is the one most likely to carry a template (a number beside its type, a
      // name beside a badge). A link that replaced the cell would silently drop that.
      const link = inRow('1', 'a[data-testid="list-link-1"]');
      expect(link?.querySelector('[data-testid="name-extra"]')?.textContent).toBe('!');
    });

    it('shows the frequent actions as buttons and folds the rest into a menu', async () => {
      expect(inRow('1', '[data-testid="row-action-call-1"]')).not.toBeNull();
      expect(inRow('1', '[data-testid="row-action-invoice-1"]')).not.toBeNull();
      // Rare and destructive ones cost no width in every row.
      expect(inRow('1', '[data-testid="row-action-export-1"]')).toBeNull();
      expect(inRow('1', '[data-testid="row-action-archive-1"]')).toBeNull();
      expect(inRow('1', '[data-testid="row-more-1"]')).not.toBeNull();

      (inRow('1', '[data-testid="row-more-1"]') as HTMLElement).click();
      host.detectChanges();
      await host.whenStable();
      expect(q('row-menu-export-1')).not.toBeNull();
      expect(q('row-menu-archive-1')).not.toBeNull();
    });

    it('names each icon button, and runs what it declares', async () => {
      const call = inRow('2', '[data-testid="row-action-call-2"]') as HTMLElement;
      expect(call.getAttribute('aria-label')).toBe('Call');
      call.click();
      expect(ran).toEqual(['call:2']);

      // An action that is a navigation is a link, so it behaves like one — carrying which row it came from,
      // since the address is what says whose invoice this is.
      const invoice = inRow('2', '[data-testid="row-action-invoice-2"]') as HTMLElement;
      expect(invoice.tagName).toBe('A');
      expect(invoice.getAttribute('href')).toBe('/invoices/new?customerId=2');
    });

    it('refuses an action for now without taking it off the screen', async () => {
      // A control that vanishes while something is saving is a control nobody can learn; `disabled` is the state
      // for "not now", `shown` for "never here".
      const call = inRow('3', '[data-testid="row-action-call-3"]') as HTMLButtonElement;
      expect(call.disabled).toBe(true);
      call.click();
      expect(ran).toEqual([]);
    });

    it('leaves out an action the row cannot take', async () => {
      // Customer 2 is archived, so there is nothing to archive: the entry is absent rather than disabled.
      (inRow('2', '[data-testid="row-more-2"]') as HTMLElement).click();
      host.detectChanges();
      await host.whenStable();
      expect(q('row-menu-archive-2')).toBeNull();
      expect(q('row-menu-export-2')).not.toBeNull();
    });

    it('asks before running a row action that says to ask, and runs nothing when the answer is no', async () => {
      // The same rule the toolbar, the keyboard and the palette follow (row 45): a row's own delete is not a
      // lesser kind of destruction, and it used to happen on the click with no question at all.
      (inRow('1', '[data-testid="row-more-1"]') as HTMLElement).click();
      host.detectChanges();
      await host.whenStable();
      (q('row-menu-archive-1') as HTMLElement).click();
      host.detectChanges();
      await host.whenStable();

      // Named, not a bare "Archive?": over a list of rows that question is unanswerable.
      expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
        'Archive Customer 01?',
      );
      expect(ran).toEqual([]);

      (document.querySelector('[data-testid="confirm-keep"]') as HTMLElement).click();
      host.detectChanges();
      await host.whenStable();
      expect(ran).toEqual([]);

      (inRow('1', '[data-testid="row-more-1"]') as HTMLElement).click();
      host.detectChanges();
      await host.whenStable();
      (q('row-menu-archive-1') as HTMLElement).click();
      host.detectChanges();
      await host.whenStable();
      (document.querySelector('[data-testid="confirm-run"]') as HTMLElement).click();
      host.detectChanges();
      await host.whenStable();
      expect(ran).toEqual(['archive:1']);
    });

    it('runs an action with no question straight away', async () => {
      const call = inRow('1', '[data-testid="row-action-call-1"]') as HTMLElement;
      call.click();
      expect(ran).toEqual(['call:1']);
      expect(document.querySelector('[data-testid="confirm-message"]')).toBeNull();
    });

    it('keeps a row control from opening the record', async () => {
      // The row is a link only on its name; a button inside the row must not navigate as well.
      const call = inRow('1', '[data-testid="row-action-call-1"]') as HTMLElement;
      expect(call.closest('a')).toBeNull();
    });
  });
  describe('on a phone, where a table cannot be read', () => {
    let host: ComponentFixture<DeclaredHost>;

    beforeEach(async () => {
      ran.length = 0;
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        imports: [DeclaredHost],
        providers: [
          provideTranslateService({
            lang: 'en',
            loader: provideTranslateLoader(() => new StaticLoader()),
          }),
          provideRouter([]),
          {
            provide: ActivatedRoute,
            useValue: { snapshot: { queryParamMap: convertToParamMap({}) } },
          },
          { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
          { provide: SettingsFacade, useClass: BrowserStorageSettings },
          { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
          { provide: Session, useValue: { me: () => ({ user: { id: 'u1' } }) } },
          { provide: WINDOW_CLASS, useValue: signal<WindowClass>('compact') },
        ],
      });
      host = TestBed.createComponent(DeclaredHost);
      host.detectChanges();
      await host.whenStable();
      host.detectChanges();
    });

    const card = (id: string): HTMLElement | null =>
      host.nativeElement.querySelector(`[data-testid="customer-${id}"]`);

    it('lays each row out as a card instead of a table row', () => {
      // A table 696 px wide in a 390 px window is read by scrolling sideways, which is how the columns that matter
      // end up off screen. Below 600 px the same rows are cards, one under the other, and nothing scrolls sideways.
      expect(host.nativeElement.querySelector('table')).toBeNull();
      expect(host.nativeElement.querySelector('[data-testid="list-cards"]')).not.toBeNull();
      expect(card('1')?.tagName).not.toBe('TR');
    });

    it('keeps the row test id, its link and its actions exactly as the table had them', async () => {
      // Every screen's own specs and scenarios find a row by this id; a phone must not be a second vocabulary.
      expect(card('1')).not.toBeNull();
      const link = card('1')!.querySelector('a[data-testid="list-link-1"]') as HTMLElement | null;
      expect(link?.getAttribute('href')).toBe('/customers/1');

      (card('2')!.querySelector('[data-testid="row-action-call-2"]') as HTMLElement).click();
      expect(ran).toEqual(['call:2']);
      expect(card('2')!.querySelector('[data-testid="row-more-2"]')).not.toBeNull();
    });

    it('names every value it shows, since a card has no header row to read it from', () => {
      // A cell under a column header needs no label; the same cell in a card does, or "Sfax" says nothing.
      const pairs = card('1')!.querySelectorAll('[data-testid^="list-card-value-"]');
      expect(pairs.length).toBeGreaterThan(0);
      for (const value of Array.from(pairs)) {
        const id = value.getAttribute('data-testid')!.replace('list-card-value-', '');
        expect(card('1')!.querySelector(`[data-testid="list-card-label-${id}"]`)).not.toBeNull();
      }
    });

    it('leads with the column that names the record, and does not repeat it below', () => {
      expect(card('1')?.querySelector('[data-testid="list-card-title-1"]')?.textContent).toContain(
        'Customer 01',
      );
      expect(card('1')?.querySelector('[data-testid="list-card-value-name"]')).toBeNull();
    });
  });
});

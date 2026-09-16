// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
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
import { DataList, DataListCell, DataListRowActions } from './data-list';
import type { ListDescriptor, ListPreferences, ListView } from './list-types';
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
  imports: [DataList, DataListCell, DataListRowActions],
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
      <ng-template appDataListRowActions let-row>
        <button type="button" [attr.data-testid]="'open-' + row.id">open</button>
      </ng-template>
    </app-data-list>
  `,
})
class Host {
  readonly descriptor = descriptor;
  readonly rows = signal<Customer[]>(all);
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
        none: 'No customers.',
        active: 'Active',
        archived: 'Archived',
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

  async function mount(saved?: ListPreferences, savedViews?: ListView[]): Promise<void> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: storage },
        { provide: Session, useValue: { me: () => ({ user: { id: 'u1' } }) } },
      ],
    });
    if (saved) TestBed.inject(SettingsFacade).set(listPreferencesSetting('customers'), saved);
    if (savedViews) TestBed.inject(SettingsFacade).set(listViewsSetting('customers'), savedViews);
    fixture = TestBed.createComponent(Host);
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
  });

  it('renders a cell through the template the screen gave, and the row actions after the columns', () => {
    expect(q('customer-1')?.querySelector('[data-testid="status-cell"]')?.textContent?.trim()).toBe(
      'ACTIVE',
    );
    expect(q('open-1')).not.toBeNull();
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
    // name, city and status declare no width (160 each), balance declares 120, the actions column is 96.
    expect(q('customers-table')!.style.minWidth).toBe('696px');
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
});

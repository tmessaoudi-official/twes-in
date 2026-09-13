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
import { AuthFacade } from '../../auth/auth-facade';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { listPreferencesSetting } from '../settings/settings-registry';
import { DataList, DataListCell, DataListRowActions } from './data-list';
import type { ListDescriptor, ListPreferences } from './list-types';
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
      },
      list: {
        filter: 'Filter',
        filter_clear: 'Clear',
        columns: 'Columns',
        columns_reset: 'Reset columns',
        move_column: 'Move {{column}}',
        resize_column: 'Resize {{column}}',
        no_match: 'Nothing matches {{query}}',
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

  async function mount(saved?: ListPreferences): Promise<void> {
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
        { provide: AuthFacade, useValue: { me: () => ({ user: { id: 'u1' } }) } },
      ],
    });
    if (saved) TestBed.inject(SettingsFacade).set(listPreferencesSetting('customers'), saved);
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
});

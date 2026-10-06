// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  type TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { DataList } from './data-list';
import type { ListDescriptor, ListPickSource, ListQuery } from './list-types';

interface Doc {
  id: string;
  status: 'draft' | 'overdue' | 'paid';
  kind: 'invoice' | 'credit';
}

const docs: Doc[] = [
  { id: '1', status: 'draft', kind: 'invoice' },
  { id: '2', status: 'overdue', kind: 'invoice' },
  { id: '3', status: 'paid', kind: 'invoice' },
  { id: '4', status: 'overdue', kind: 'credit' },
  { id: '5', status: 'draft', kind: 'credit' },
];

const descriptor: ListDescriptor<Doc> = {
  id: 'docs',
  rowId: (row) => row.id,
  pageSizes: [10, 25],
  columns: [
    { id: 'id', label: 'd.id', value: (row) => row.id, hideable: false },
    { id: 'status', label: 'd.status', value: (row) => row.status },
  ],
  filters: [
    {
      id: 'status',
      label: 'd.status',
      multiple: true,
      value: (row) => row.status,
      options: [
        { value: 'draft', label: 'd.draft' },
        { value: 'overdue', label: 'd.overdue' },
        { value: 'paid', label: 'd.paid' },
      ],
    },
    {
      id: 'kind',
      label: 'd.kind',
      multiple: true,
      value: (row) => row.kind,
      options: [
        { value: 'invoice', label: 'd.invoice' },
        { value: 'credit', label: 'd.credit' },
      ],
    },
  ],
  ranges: [
    { id: 'issueDate', kind: 'day', label: 'd.issued' },
    { id: 'totalGross', kind: 'amount', label: 'd.total' },
  ],
  picks: [{ id: 'customer', label: 'd.customer' }],
};

@Component({
  imports: [DataList],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-data-list
      [descriptor]="descriptor"
      [rows]="rows()"
      [total]="total()"
      [pickSources]="sources"
      (queryChange)="queries.push($event)"
      testId="docs-table"
      [rowTestId]="rowTestId"
      emptyKey="d.none"
    />
  `,
})
class PagedHost {
  readonly descriptor = descriptor;
  readonly rows = signal<Doc[]>(docs);
  readonly total = signal<number | null>(5);
  readonly queries: ListQuery[] = [];
  readonly asked: string[][] = [];
  readonly sources: Record<string, ListPickSource> = {
    customer: {
      search: async () => [{ id: 'k1', code: 'C-1', name: 'Carthage SARL' }],
      byIds: async (ids) => {
        this.asked.push([...ids]);
        return ids.map((id) => ({ id, code: `C-${id}`, name: `Customer ${id}` }));
      },
    },
  };
  readonly rowTestId = (row: Doc) => `doc-${row.id}`;
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      d: {
        id: 'Id',
        status: 'Status',
        kind: 'Kind',
        draft: 'Draft',
        overdue: 'Overdue',
        paid: 'Paid',
        invoice: 'Invoice',
        credit: 'Credit note',
        issued: 'Issued on',
        total: 'Total',
        customer: 'Customer',
        none: 'None',
      },
      form: { none_found: 'None found' },
      select: {
        placeholder: 'Choose',
        more: '+{{count}}',
        select_all: 'All',
        clear_all: 'Clear',
        count: '{{count}}',
      },
      list: {
        filter: 'Filter',
        filter_clear: 'Clear',
        filters: 'Filters',
        filters_active: '{{count}} active',
        filters_clear_all: 'Clear all',
        filter_chips: 'Active filters',
        chip_remove: 'Remove {{label}}: {{value}}',
        chip_range: '{{from}} to {{to}}',
        chip_from: 'from {{from}}',
        chip_to: 'up to {{to}}',
        chip_min: 'at least {{min}}',
        chip_max: 'at most {{max}}',
        chip_amounts: '{{min}} to {{max}}',
        pick_hint: 'Type to search',
        range: { from: 'From', to: 'To', min: 'At least', max: 'At most' },
        columns: 'Columns',
        columns_reset: 'Reset',
        views: 'Views',
        views_none: 'None',
        view_name: 'Name',
        view_save: 'Save',
        no_match: 'Nothing',
        no_match_filters: 'Nothing',
        filter_any: 'All',
        actions: 'Actions',
        more_actions: 'More',
        new_row: '{{count}} new',
        new_rows: '{{count}} new',
      },
    });
  }
}

describe('DataList filters that combine', () => {
  let fixture: ComponentFixture<PagedHost>;

  const q = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`);
  const rowIds = (): string[] =>
    (
      Array.from(fixture.nativeElement.querySelectorAll('[data-testid^="doc-"]')) as HTMLElement[]
    ).map((row) => row.getAttribute('data-testid')!.replace('doc-', ''));
  const chipKeys = (): string[] =>
    (
      Array.from(document.body.querySelectorAll('[data-testid^="list-chip-"]')) as HTMLElement[]
    ).map((chip) => chip.getAttribute('data-testid')!.replace('list-chip-', ''));
  const last = (): ListQuery => fixture.componentInstance.queries.at(-1)!;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function mount(queryParams: Record<string, string> = {}, paged = true): Promise<void> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [PagedHost],
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
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: Session, useValue: { me: () => ({ user: { id: 'u1' } }) } },
      ],
    });
    fixture = TestBed.createComponent(PagedHost);
    if (!paged) fixture.componentInstance.total.set(null);
    await settle();
  }

  /** Opens a facet's Select and toggles each option in turn, as a person does. */
  async function toggle(filter: string, ...values: string[]): Promise<void> {
    for (const value of values) {
      q(`list-facet-${filter}`)!.click();
      await settle();
      q(`list-facet-${filter}-${value}`)!.click();
      await settle();
      // A multiple Select stays open after a pick; close it so the next opening is a clean one.
      if (q(`list-facet-${filter}-${value}`)) {
        q(`list-facet-${filter}`)!.click();
        await settle();
      }
    }
  }

  async function type(testId: string, value: string): Promise<void> {
    const field = q(testId) as HTMLInputElement;
    field.value = value;
    field.dispatchEvent(new Event('input'));
    field.dispatchEvent(new Event('blur'));
    await settle();
  }

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('lists the rows holding ANY of several options of one filter, and narrows by every filter together', async () => {
    await mount({}, false);
    // Nothing chosen reads « All », as a single-choice filter does, never the form's « Choose » (audit V-11).
    expect(q('list-facet-status')?.textContent).toContain('All');
    expect(q('list-facet-status')?.textContent).not.toContain('Choose');

    await toggle('status', 'draft', 'overdue');
    expect(rowIds()).toEqual(['1', '2', '4', '5']);

    await toggle('kind', 'credit');
    expect(rowIds()).toEqual(['4', '5']);
    expect(chipKeys()).toEqual(['status:draft', 'status:overdue', 'kind:credit']);
  });

  it('takes a chip off, or every one at once', async () => {
    await mount({}, false);
    await toggle('status', 'draft', 'overdue');
    await toggle('kind', 'credit');

    (q('list-chip-status:draft')!.querySelector('button') as HTMLButtonElement).click();
    await settle();
    expect(chipKeys()).toEqual(['status:overdue', 'kind:credit']);
    expect(rowIds()).toEqual(['4']);

    q('list-chips-clear')!.click();
    await settle();
    expect(chipKeys()).toEqual([]);
    expect(rowIds()).toEqual(['1', '2', '3', '4', '5']);
  });

  it('asks the API for every chosen option, as one comma list a filter keeps', async () => {
    await mount();
    await toggle('status', 'draft', 'overdue');

    expect(last().filters).toEqual({ status: 'draft,overdue' });
  });

  it('opens on the options, intervals and records an address names, leaving out what it cannot read', async () => {
    await mount({
      status: 'overdue,nonsense,paid',
      kind: 'credit',
      'issueDate.from': '2026-01-15',
      'issueDate.to': '2026-02-30',
      'totalGross.max': '1e3',
      customer: 'k1',
    });

    expect(last().filters).toEqual({
      status: 'overdue,paid',
      kind: 'credit',
      'issueDate.from': '2026-01-15',
      customer: 'k1',
    });
    // The record the address names is asked for once, by id, to be called by its name on the chip.
    expect(fixture.componentInstance.asked).toEqual([['k1']]);
    expect(q('list-chip-customer:k1')?.textContent).toContain('C-k1 · Customer k1');
  });

  it('keeps an interval end once it is a day or an amount, and not while it is half typed', async () => {
    await mount();
    q('list-filters')!.click();
    await settle();

    await type('docs-table-filter-panel-issueDate-from', '2026-0');
    expect(last().filters).toEqual({});

    await type('docs-table-filter-panel-totalGross-min', '1500.5');
    expect(last().filters).toEqual({ 'totalGross.min': '1500.5' });
    // A sum reads as a sum, written as the screen writes amounts (audit 2026-10-06, B-4).
    expect(q('list-chip-totalGross:range')?.textContent?.replace(/\s/g, ' ')).toContain(
      'at least 1 500,5',
    );

    await type('docs-table-filter-panel-totalGross-max', '4000');
    expect(q('list-chip-totalGross:range')?.textContent?.replace(/\s/g, ' ')).toContain(
      '1 500,5 to 4 000',
    );
    await type('docs-table-filter-panel-totalGross-min', '');
    expect(q('list-chip-totalGross:range')?.textContent?.replace(/\s/g, ' ')).toContain(
      'at most 4 000',
    );
    expect(q('list-filters-count')?.textContent).toContain('1');

    (q('list-chip-totalGross:range')!.querySelector('button') as HTMLButtonElement).click();
    await settle();
    expect(last().filters).toEqual({});
  });

  it('stops filtering by an end edited into something that is not a day, and leaves what is typed', async () => {
    // Otherwise the list kept the earlier day while the field showed another text (audit 2026-10-06, T-1).
    await mount();
    q('list-filters')!.click();
    await settle();
    await type('docs-table-filter-panel-issueDate-from', '2026-01-15');
    expect(last().filters).toEqual({ 'issueDate.from': '2026-01-15' });

    await type('docs-table-filter-panel-issueDate-from', '2026-0');
    expect(last().filters).toEqual({});
    expect(q('list-chip-issueDate:range')).toBeNull();
    expect((q('docs-table-filter-panel-issueDate-from') as HTMLInputElement).value).toBe('2026-0');
  });

  it('says an interval whose end comes before its start is not applied', async () => {
    await mount();
    q('list-filters')!.click();
    await settle();
    await type('docs-table-filter-panel-issueDate-from', '2026-03-15');
    expect(q('docs-table-filter-panel-issueDate-inverted')).toBeNull();

    await type('docs-table-filter-panel-issueDate-to', '2026-03-01');
    expect(q('docs-table-filter-panel-issueDate-inverted')?.textContent).toContain(
      'list.range.inverted',
    );
    await type('docs-table-filter-panel-issueDate-to', '2026-03-31');
    expect(q('docs-table-filter-panel-issueDate-inverted')).toBeNull();
  });

  it("puts a day's calendar button at the end of its field, beside what is typed", async () => {
    await mount();
    q('list-filters')!.click();
    await settle();

    const field = q('docs-table-filter-panel-issueDate-from')!.closest('mat-form-field')!;
    const calendar = field.querySelector('app-day-calendar-button');
    expect(calendar).not.toBeNull();
    expect(calendar!.closest('.mat-mdc-form-field-icon-suffix')).not.toBeNull();
  });
});

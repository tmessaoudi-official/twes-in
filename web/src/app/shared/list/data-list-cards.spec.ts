// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { WINDOW_CLASS, type WindowClass } from '../ui/window-class';
import { DataList, DataListCell } from './data-list';
import type { EmptyState, ListDescriptor } from './list-types';

interface Doc {
  id: string;
  number: string;
  customer: string;
  day: string;
  due: string;
  total: string;
  status: 'draft' | 'issued';
}

const rows: Doc[] = [
  {
    id: '1',
    number: 'FA-0412',
    customer: 'Atelier Ben Salah',
    day: '14/09/2026',
    due: '1 693,180',
    total: '2 000,000',
    status: 'issued',
  },
  {
    id: '2',
    number: '',
    customer: 'Quincaillerie du Port',
    day: '15/09/2026',
    due: '',
    total: '120,500',
    status: 'draft',
  },
];

/** A list whose link column is a code, declaring what its phone card leads with. */
const slotted: ListDescriptor<Doc> = {
  id: 'docs',
  rowId: (row) => row.id,
  pageSizes: [25],
  link: (row) => ['/docs', row.id],
  cardTitle: 'customer',
  cardSubtitle: ['number', 'day'],
  cardAmount: ['due', 'total'],
  cardStatus: 'status',
  columns: [
    { id: 'number', label: 'd.number', value: (row) => row.number, hideable: false },
    { id: 'customer', label: 'd.customer', value: (row) => row.customer, hideable: false },
    { id: 'day', label: 'd.day', value: (row) => row.day },
    { id: 'reference', label: 'd.reference', value: (row) => `REF-${row.id}` },
    { id: 'due', label: 'd.due', value: (row) => row.due, align: 'end' },
    { id: 'total', label: 'd.total', value: (row) => row.total, align: 'end' },
    { id: 'status', label: 'd.status', value: (row) => row.status },
  ],
  filters: [
    {
      id: 'status',
      label: 'd.status',
      value: (row) => row.status,
      options: [
        { value: 'draft', label: 'd.draft' },
        { value: 'issued', label: 'd.issued' },
      ],
    },
  ],
};

const EMPTY: EmptyState = { icon: 'receipt_long', title: 'd.none' };

@Component({
  imports: [DataList, DataListCell],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-data-list
      [descriptor]="descriptor()"
      [rows]="rows"
      [loading]="false"
      [failed]="false"
      testId="docs-table"
      [rowTestId]="rowTestId"
      [empty]="empty"
      emptyTestId="docs-empty"
    >
      <ng-template appDataListCell="status" let-row>
        <b data-testid="status-pill">{{ row.status }}</b>
      </ng-template>
    </app-data-list>
  `,
})
class CardsHost {
  readonly descriptor = signal<ListDescriptor<Doc>>(slotted);
  readonly rows = rows;
  readonly empty = EMPTY;
  readonly rowTestId = (row: Doc) => `doc-${row.id}`;
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      d: {
        number: 'Number',
        customer: 'Customer',
        day: 'Issued',
        reference: 'Reference',
        due: 'Due',
        total: 'Total',
        status: 'Status',
        draft: 'Draft',
        issued: 'Issued',
        none: 'No documents.',
      },
      list: {
        filter: 'Filter',
        filters: 'Filters',
        filters_active: '{{count}} active',
        filter_any: 'All',
        filter_chips: 'Active filters',
        chip_remove: 'Remove the filter {{label}}: {{value}}',
        filters_clear_all: 'Clear all',
        columns: 'Columns',
        views: 'Views',
      },
    });
  }
}

async function mount(windowClass: WindowClass): Promise<ComponentFixture<CardsHost>> {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [CardsHost],
    providers: [
      provideTranslateService({
        lang: 'en',
        loader: provideTranslateLoader(() => new StaticLoader()),
      }),
      provideRouter([]),
      { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap({}) } } },
      { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      { provide: SettingsFacade, useClass: BrowserStorageSettings },
      { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      { provide: Session, useValue: { me: () => ({ user: { id: 'u1' } }) } },
      { provide: WINDOW_CLASS, useValue: signal<WindowClass>(windowClass) },
    ],
  });
  const host = TestBed.createComponent(CardsHost);
  host.detectChanges();
  await host.whenStable();
  host.detectChanges();
  return host;
}

describe('DataList phone card slots', () => {
  let host: ComponentFixture<CardsHost>;
  const q = (id: string): HTMLElement | null =>
    host.nativeElement.querySelector(`[data-testid="${id}"]`);
  const card = (id: string): HTMLElement => q(`doc-${id}`)!;

  beforeEach(async () => {
    host = await mount('compact');
  });

  it('leads with the name the card slot names, not the code the table links on', () => {
    const title = q('list-card-title-1')!;
    expect(title.textContent).toContain('Atelier Ben Salah');
    expect(title.textContent).not.toContain('FA-0412');
    // A typed name cut short keeps its start in either direction.
    expect(title.querySelector('.twes-value[dir="auto"]')).not.toBeNull();
  });

  it('makes the whole card the link to the record, the title its words', () => {
    const link = card('1').querySelector('a[data-testid="list-link-1"]') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('/docs/1');
    expect(link.classList).toContain('twes-list-card-link');
    expect(link.closest('[data-testid="list-card-title-1"]')).not.toBeNull();
    expect(card('1').classList).toContain('twes-list-card-slotted');
  });

  it('says the subtitle as separate parts, each isolated, the separator outside them', () => {
    const subtitle = q('list-card-subtitle-1')!;
    const parts = Array.from(subtitle.querySelectorAll('bdi')).map((part) =>
      part.textContent?.trim(),
    );
    expect(parts).toEqual(['FA-0412', '14/09/2026']);
    const separators = subtitle.querySelectorAll('[aria-hidden="true"]');
    expect(separators).toHaveLength(1);
    expect(separators[0].textContent).toContain('·');
    expect(separators[0].closest('bdi')).toBeNull();
  });

  it('leaves out a subtitle part the row has nothing in, and its separator with it', () => {
    const subtitle = q('list-card-subtitle-2')!;
    const parts = Array.from(subtitle.querySelectorAll('bdi')).map((part) =>
      part.textContent?.trim(),
    );
    expect(parts).toEqual(['15/09/2026']);
    expect(subtitle.querySelectorAll('[aria-hidden="true"]')).toHaveLength(0);
  });

  it('shows the first amount the row has, at the end of the second row, isolated', () => {
    expect(q('list-card-amount-1')?.textContent?.trim()).toBe('1 693,180');
    expect(q('list-card-amount-1')?.querySelector('bdi')).not.toBeNull();
    // A draft has nothing due: its total stands in.
    expect(q('list-card-amount-2')?.textContent?.trim()).toBe('120,500');
  });

  it("draws the status through the column's own cell, in the card's head", () => {
    const status = q('list-card-status-1')!;
    expect(status.querySelector('[data-testid="status-pill"]')?.textContent).toBe('issued');
    expect(status.closest('.twes-list-card-head')).not.toBeNull();
  });

  it('adds at most one more named pair, never one a slot already shows', () => {
    const values = Array.from(card('1').querySelectorAll('[data-testid^="list-card-value-"]')).map(
      (value) => value.getAttribute('data-testid'),
    );
    expect(values).toEqual(['list-card-value-reference']);
    expect(q('list-card-label-reference')?.textContent).toContain('Reference');
  });

  it('names the further pair or the next step, never both (proposal § 7.3 row 3)', async () => {
    host.componentInstance.descriptor.set({
      ...slotted,
      actions: [
        {
          id: 'pay',
          label: 'd.issued',
          icon: 'payments',
          run: () => undefined,
          next: (row) => row.id === '1',
        },
      ],
    });
    host.detectChanges();
    await host.whenStable();
    host.detectChanges();
    expect(card('1').querySelector('[data-testid="list-card-next-1"]')).not.toBeNull();
    expect(card('1').querySelector('[data-testid^="list-card-value-"]')).toBeNull();
    // A row with no next step keeps its pair.
    expect(card('2').querySelector('[data-testid="list-card-value-reference"]')).not.toBeNull();
  });

  it('keeps today’s card for a list that names no slot', async () => {
    const plain: ListDescriptor<Doc> = { ...slotted, cardTitle: undefined };
    host.componentInstance.descriptor.set(plain);
    host.detectChanges();
    await host.whenStable();
    host.detectChanges();
    expect(card('1').classList).not.toContain('twes-list-card-slotted');
    expect(q('list-card-title-1')?.textContent).toContain('FA-0412');
    expect(q('list-card-subtitle-1')).toBeNull();
    expect(q('list-card-value-customer')).not.toBeNull();
  });

  it('folds the filters behind « Filters (n) » on a phone', async () => {
    expect(q('list-facet-status')).toBeNull();
    const button = q('list-filters') as HTMLButtonElement;
    expect(button).not.toBeNull();
    expect(button.getAttribute('aria-expanded')).toBe('false');
    button.click();
    host.detectChanges();
    await host.whenStable();
    host.detectChanges();
    expect(button.getAttribute('aria-expanded')).toBe('true');
    const fold = host.nativeElement.querySelector('#docs-table-filters') as HTMLElement;
    expect(fold).not.toBeNull();
    expect(fold.querySelector('[data-testid="list-facet-status"]')).not.toBeNull();
  });
});

describe('DataList filters on a wide window', () => {
  it('keeps the filters in the toolbar, with no « Filters » button when nothing else waits behind it', async () => {
    const host = await mount('expanded');
    const q = (id: string): HTMLElement | null =>
      host.nativeElement.querySelector(`[data-testid="${id}"]`);
    expect(q('list-facet-status')).not.toBeNull();
    expect(q('list-filters')).toBeNull();
  });
});

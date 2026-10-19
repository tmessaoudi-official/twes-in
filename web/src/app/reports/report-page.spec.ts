// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter, Router, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { EstablishmentsFacade } from '../company/establishments-facade';
import type { EstablishmentRow } from '../company/company-types';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { StepUp } from '../shared/step-up/step-up';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { ReportDocumentsDialog } from './report-documents-dialog';
import { ReportPage } from './report-page';
import { ReportsFacade, type ReportState } from './reports-facade';
import type { ReportEntry, ReportQuery, ReportResult } from './reports-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      reports: {
        total: 'Total',
        previous: 'Période comparée',
        unnamed: 'Non renseigné',
        empty: 'Rien sur cette période.',
        period: { this_month: 'Ce mois-ci', last_month: 'Le mois dernier' },
        errors: { gone: 'Pas pour vous.', refused: 'Refusé.' },
        columns: {
          category: 'Catégorie',
          gross: 'TTC',
          expenses: 'Dépenses',
          late: '{{from}} à {{to}} jours',
        },
        items: { 'expenses-by-category': { title: 'Dépenses par catégorie' } },
      },
    });
  }
}

const BY_CATEGORY: ReportEntry = {
  key: 'expenses-by-category',
  byEstablishment: false,
  periodic: true,
  columns: [],
};
const DEAD_STOCK: ReportEntry = {
  key: 'dead-stock',
  byEstablishment: true,
  periodic: false,
  columns: [],
};

const SEPTEMBER: ReportResult = {
  key: 'expenses-by-category',
  from: '2026-09-01',
  to: '2026-09-30',
  comparisonFrom: '2026-08-01',
  comparisonTo: '2026-08-31',
  columns: [
    { key: 'category', kind: 'label', params: {} },
    { key: 'gross', kind: 'amount', params: {} },
    { key: 'expenses', kind: 'count', params: {} },
  ],
  rows: [
    {
      key: 'energy',
      cells: { category: 'Énergie', gross: '169.000', expenses: 2 },
      previous: { category: 'Énergie', gross: '40.000', expenses: 1 },
      drill: { list: 'expenses', filters: { category: 'energy', status: ['recorded', 'paid'] } },
    },
    {
      key: 'none',
      cells: { category: null, gross: '10.000', expenses: 1 },
      previous: null,
      drill: { list: null, filters: {} },
    },
  ],
  totals: { gross: '179.000', expenses: 3 },
  previousTotals: { gross: '40.000', expenses: 1 },
};

const ESTABLISHMENTS = [
  { id: 'e1', code: '000', name: 'Boutique' },
  { id: 'e2', code: '001', name: 'Dépôt' },
] as EstablishmentRow[];

// Each report with its period, its comparison and its establishment, every row
// opening the documents behind its figures.
describe('ReportPage', () => {
  const catalogue = signal<readonly ReportEntry[] | null>(null);
  const report = signal<ReportState>({ status: 'loading' });
  const establishments = signal<readonly EstablishmentRow[]>([]);
  const facade = {
    catalogue: catalogue.asReadonly(),
    catalogueFailed: signal(false).asReadonly(),
    report: report.asReadonly(),
    loadCatalogue: vi.fn(),
    loadReport: vi.fn(),
    forgetReport: vi.fn(),
    exportAddress: vi.fn(),
  };
  const live = { reloadOn: vi.fn() };
  const dialog = { open: vi.fn() };

  async function open(url: string): Promise<{ harness: RouterTestingHarness; el: HTMLElement }> {
    TestBed.configureTestingModule({
      providers: [
        provideRouter(
          [{ path: 'reports/:key', component: ReportPage }],
          withComponentInputBinding(),
        ),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        // What the export button needs to be drawn: it fetches nothing until a format is picked.
        provideHttpClient(),
        provideHttpClientTesting(),
        provideQuietFeedback(),
        { provide: StepUp, useValue: { request: vi.fn() } },
        { provide: ReportsFacade, useValue: facade },
        {
          provide: EstablishmentsFacade,
          useValue: { establishments: establishments.asReadonly(), loadEstablishments: vi.fn() },
        },
        { provide: LiveChanges, useValue: live },
        { provide: MatDialog, useValue: dialog },
        {
          provide: AuthFacade,
          useValue: { me: () => ({ company: { id: 'k1', timezone: 'Africa/Tunis' } }) },
        },
        {
          provide: FormatFacade,
          useValue: {
            amount: (v: string) => v,
            day: (v: string) => v,
            locale: () => 'fr',
            dateFormat: () => 'auto',
          },
        },
      ],
    });
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url);
    harness.detectChanges();
    await harness.fixture.whenStable();
    harness.detectChanges();
    return { harness, el: harness.routeNativeElement as HTMLElement };
  }

  /** The question the report was last asked. */
  const lastAsked = (): ReportQuery => facade.loadReport.mock.calls.at(-1)![2] as ReportQuery;
  const cells = (row: Element | null) =>
    Array.from(row?.querySelectorAll('th, td') ?? []).map((cell) =>
      cell.textContent?.replace(/\s+/g, ' ').trim(),
    );

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    // Half past midnight on 1 October in Tunis, still September in UTC: the company's month is October.
    vi.setSystemTime(new Date('2026-09-30T23:30:00Z'));
    catalogue.set([BY_CATEGORY, DEAD_STOCK]);
    report.set({ status: 'ready', result: SEPTEMBER });
    establishments.set([]);
    for (const mock of [
      facade.loadCatalogue,
      facade.loadReport,
      facade.forgetReport,
      live.reloadOn,
      dialog.open,
    ])
      mock.mockReset();
    facade.loadCatalogue.mockResolvedValue(undefined);
    facade.loadReport.mockResolvedValue(undefined);
    facade.exportAddress.mockImplementation(
      (_c: string, key: string, _q: ReportQuery, format: string) =>
        `/reports/${key}/export.${format}`,
    );
  });

  afterEach(() => {
    vi.useRealTimers();
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('opens on the company’s month against the month before, and writes that in the address', async () => {
    const { harness } = await open('/reports/expenses-by-category');

    expect(facade.forgetReport).toHaveBeenCalled();
    expect(facade.loadReport).toHaveBeenCalledWith('k1', 'expenses-by-category', {
      from: '2026-10-01',
      to: '2026-10-31',
      compare: 'previous',
      establishment: null,
    });
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe(
      '/reports/expenses-by-category?from=2026-10-01&to=2026-10-31&compare=previous',
    );
  });

  it('reads the period and the comparison the address names, as a report come back to from a list', async () => {
    await open('/reports/expenses-by-category?from=2026-09-01&to=2026-09-30&compare=year');

    expect(lastAsked()).toEqual({
      from: '2026-09-01',
      to: '2026-09-30',
      compare: 'year',
      establishment: null,
    });
  });

  it('draws each row and the totals, the period compared under every counted figure and never under a name', async () => {
    const { el } = await open('/reports/expenses-by-category');

    expect(cells(el.querySelector('thead tr'))).toEqual(['Catégorie', 'TTC', 'Dépenses']);
    expect(cells(el.querySelector('[data-row="energy"]'))).toEqual([
      'Énergie',
      '169.000 Période comparée 40.000',
      '2 Période comparée 1',
    ]);
    // A row the compared period did not have says it had none under its figures, never a zero.
    expect(cells(el.querySelector('[data-row="none"]'))).toEqual([
      'Non renseigné',
      '10.000 Période comparée —',
      '1 Période comparée —',
    ]);
    expect(cells(el.querySelector('[data-testid="report-totals"]'))).toEqual([
      'Total',
      '179.000 Période comparée 40.000',
      '3 Période comparée 1',
    ]);
  });

  it('says « — » for a figure the period on screen had none of, so the period compared never reads as this one', async () => {
    report.set({
      status: 'ready',
      result: {
        ...SEPTEMBER,
        rows: [
          {
            key: 'rent',
            cells: { category: 'Loyer', gross: null, expenses: null },
            previous: { category: 'Loyer', gross: '300.000', expenses: 1 },
            drill: { list: null, filters: {} },
          },
        ],
      },
    });
    const { el } = await open('/reports/expenses-by-category');

    expect(cells(el.querySelector('[data-row="rent"]'))).toEqual([
      'Loyer',
      '— Période comparée 300.000',
      '— Période comparée 1',
    ]);
  });

  it('opens a row in its list, narrowed by the list’s own filters', async () => {
    const { el } = await open('/reports/expenses-by-category');

    const link = el.querySelector('[data-testid="report-drill-energy"]') as HTMLAnchorElement;
    expect(decodeURIComponent(link.getAttribute('href')!)).toBe(
      '/expenses?category=energy&status=recorded,paid',
    );
  });

  it('opens the documents of a row no list can show, under the period on screen', async () => {
    const { el } = await open(
      '/reports/expenses-by-category?from=2026-09-01&to=2026-09-30&compare=none',
    );

    (el.querySelector('[data-testid="report-documents-none"]') as HTMLButtonElement).click();

    const [component, config] = dialog.open.mock.calls[0]!;
    expect(component).toBe(ReportDocumentsDialog);
    expect(config.data).toEqual({
      companyId: 'k1',
      key: 'expenses-by-category',
      query: { from: '2026-09-01', to: '2026-09-30', compare: 'none', establishment: null },
      row: 'none',
      label: 'Non renseigné',
    });
  });

  it('asks the report again for a preset picked, the comparison kept', async () => {
    const { harness } = await open('/reports/expenses-by-category');
    const page = harness.routeDebugElement!.componentInstance as {
      pickPreset(preset: string): void;
    };

    page.pickPreset('last_month');
    harness.detectChanges();
    await harness.fixture.whenStable();

    expect(lastAsked()).toEqual({
      from: '2026-09-01',
      to: '2026-09-30',
      compare: 'previous',
      establishment: null,
    });
  });

  it('says so when the catalogue does not offer the report, and asks nothing', async () => {
    const { el } = await open('/reports/takings');

    expect(el.querySelector('[data-testid="report-gone"]')?.textContent).toContain(
      'Pas pour vous.',
    );
    expect(facade.loadReport).not.toHaveBeenCalled();
  });

  it('asks a report of today no period and no comparison, and offers the establishments only where there are several', async () => {
    report.set({ status: 'loading' });
    const { el } = await open('/reports/dead-stock?from=2026-09-01&to=2026-09-30&compare=year');

    expect(lastAsked()).toEqual({ from: null, to: null, compare: 'none', establishment: null });
    expect(el.querySelector('[data-testid="report-preset"]')).toBeNull();
    expect(el.querySelector('[data-testid="report-compare"]')).toBeNull();
    expect(el.querySelector('[data-testid="report-establishment"]')).toBeNull();
  });

  it('offers the establishments of a company that has several, for a report that can be narrowed', async () => {
    establishments.set(ESTABLISHMENTS);
    const { el } = await open('/reports/dead-stock?establishment=e2');

    expect(el.querySelector('[data-testid="report-establishment"]')).not.toBeNull();
    expect(lastAsked().establishment).toBe('e2');
  });

  it('says a question the API refused', async () => {
    report.set({ status: 'refused' });
    const { el } = await open('/reports/expenses-by-category');

    expect(el.querySelector('[data-testid="report-refused"]')?.textContent).toContain('Refusé.');
    expect(el.querySelector('[data-testid="report-table"]')).toBeNull();
  });

  it('reads the report again when what it counts changes', async () => {
    await open('/reports/expenses-by-category');

    const [kinds, reload] = live.reloadOn.mock.calls[0]!;
    expect(kinds).toEqual(
      expect.arrayContaining(['invoice', 'payment', 'stock', 'expense', 'module']),
    );
    const before = facade.loadReport.mock.calls.length;
    await reload();
    expect(facade.loadReport.mock.calls.length).toBe(before + 1);
  });

  it('hands the file over under the period on screen', async () => {
    const { harness, el } = await open(
      '/reports/expenses-by-category?from=2026-09-01&to=2026-09-30&compare=none',
    );

    (el.querySelector('[data-testid="report-export"]') as HTMLButtonElement).click();
    harness.detectChanges();

    const entry = document.body.querySelector('[data-testid="report-export-xlsx"]');
    expect(entry?.getAttribute('data-address')).toBe('/reports/expenses-by-category/export.xlsx');
    expect(facade.exportAddress).toHaveBeenCalledWith(
      'k1',
      'expenses-by-category',
      { from: '2026-09-01', to: '2026-09-30', compare: 'none', establishment: null },
      'xlsx',
    );
  });
});

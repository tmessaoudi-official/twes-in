// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  type OnInit,
  signal,
  untracked,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { EstablishmentsFacade } from '../company/establishments-facade';
import { DayCalendarButton } from '../shared/form/day-calendar-button';
import { DayInput } from '../shared/form/day-input';
import { FormatFacade } from '../shared/i18n/format-facade';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { todayIn } from '../shared/i18n/format';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import { validRangeValue } from '../shared/list/list-filters';
import { LiveChanges } from '../shared/realtime/live-changes';
import { ReportDocumentsDialog, type ReportDocumentsData } from './report-documents-dialog';
import {
  addressOf,
  columnLabel,
  drillLink,
  PERIOD_PRESETS,
  presetOf,
  presetPeriod,
  queryOf,
  type PeriodPreset,
} from './report-view';
import { ReportsFacade } from './reports-facade';
import {
  REPORT_COMPARISONS,
  type ReportCell,
  type ReportColumn,
  type ReportComparison,
  type ReportQuery,
  type ReportRow,
} from './reports-types';

/** What a change may move in a report: the documents, the stock, the expenses and who may read what. */
export const REPORT_KINDS = [
  'invoice',
  'payment',
  'delivery_note',
  'stock',
  'product',
  'customer',
  'expense',
  'vendor',
  'module',
  'role',
] as const;

/**
 * One report: its period, the period it is compared with, the establishment it is
 * narrowed to, and its figures, each row opening the list of its documents or the documents themselves. What is asked
 * stays in the address, so a report come back to from a list reads as it was left. The figures are the API's own
 * decimal strings, shown through the pipes, never summed here.
 */
@Component({
  selector: 'app-report-page',
  imports: [
    AmountPipe,
    DayCalendarButton,
    DayInput,
    DayPipe,
    FormsModule,
    ListExport,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    NgTemplateOutlet,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './report-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ReportPage implements OnInit {
  /** The report's key, from the address. */
  readonly key = input.required<string>();

  private readonly facade = inject(ReportsFacade);
  private readonly auth = inject(AuthFacade);
  private readonly establishmentsFacade = inject(EstablishmentsFacade);
  private readonly format = inject(FormatFacade);
  private readonly translate = inject(TranslateService);
  private readonly live = inject(LiveChanges);
  private readonly dialog = inject(MatDialog);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly presets = PERIOD_PRESETS;
  protected readonly comparisons = REPORT_COMPARISONS;
  /** The kinds of figure the period compared has beside them: what a row counts, never its name or its day. */
  protected readonly countedKinds: ReadonlySet<ReportColumn['kind']> = new Set([
    'amount',
    'quantity',
    'count',
    'days',
  ]);
  protected readonly state = this.facade.report;
  protected readonly catalogueFailed = this.facade.catalogueFailed;
  private readonly company = computed(() => this.auth.me()?.company ?? null);
  /** The company's today, which a preset's days and a report of today are counted from. */
  private readonly today = computed(() => todayIn(this.company()?.timezone));
  /** This report as the catalogue offers it; null while unread, undefined when it is not offered here. */
  protected readonly entry = computed(() => {
    const catalogue = this.facade.catalogue();
    return catalogue === null ? null : catalogue.find((each) => each.key === this.key());
  });
  /** The establishments it can be narrowed to: none to choose from in a company of one. */
  protected readonly establishments = computed(() => {
    const all = this.establishmentsFacade.establishments();
    return this.entry()?.byEstablishment && all.length > 1 ? all : [];
  });
  /** What is asked, and of which report: the component stays when the address moves to another report. */
  private readonly asked = signal<{ readonly key: string; readonly query: ReportQuery } | null>(
    null,
  );
  protected readonly query = computed(() => {
    const asked = this.asked();
    return asked !== null && asked.key === this.key() ? asked.query : null;
  });
  /** Whether the person is typing days of their own rather than picking a preset. */
  private readonly custom = signal(false);
  protected readonly preset = computed<PeriodPreset | 'custom'>(() => {
    const query = this.query();
    if (this.custom() || query?.from == null || query.to == null) return 'custom';
    return presetOf({ from: query.from, to: query.to }, this.today());
  });
  protected readonly result = computed(() => {
    const state = this.state();
    return state.status === 'ready' && state.result.key === this.key() ? state.result : null;
  });
  protected readonly compared = computed(() => this.result()?.comparisonFrom != null);
  protected readonly headings = computed(() =>
    (this.result()?.columns ?? []).map((column) => ({ column, label: columnLabel(column) })),
  );
  /** The file of the report on screen, under what it was read with; none until it has been read. */
  protected readonly exportAddress = computed(() => {
    const companyId = this.company()?.id;
    const query = this.query();
    if (!companyId || query === null || this.result() === null) return null;
    return (format: ExportFormat) =>
      this.facade.exportAddress(companyId, this.key(), query, format);
  });
  /** The file's name, as the API names it: the report and its days. */
  protected readonly fileName = computed(() => {
    const result = this.result();
    if (result === null) return null;
    const days = result.from === result.to ? result.from : `${result.from}_${result.to}`;
    return (address: string) =>
      `${result.key}_${days}.${address.split('?')[0].split('.').at(-1) ?? 'csv'}`;
  });

  constructor() {
    // The question is read from the address once the report is known, then follows what the person picks.
    effect(() => {
      const entry = this.entry();
      if (!entry) return;
      untracked(() => {
        if (this.query() !== null) return;
        this.custom.set(false);
        this.asked.set({
          key: entry.key,
          query: queryOf(this.route.snapshot.queryParamMap, entry, this.today()),
        });
      });
    });
    effect(() => {
      const query = this.query();
      const companyId = this.company()?.id;
      if (query === null || !companyId) return;
      untracked(() => {
        void this.router.navigate([], {
          relativeTo: this.route,
          queryParams: addressOf(query),
          queryParamsHandling: 'merge',
          replaceUrl: true,
        });
        void this.facade.loadReport(companyId, this.key(), query);
      });
    });
  }

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.facade.forgetReport();
    this.live.reloadOn(REPORT_KINDS, () => this.reload(companyId), this.destroyRef);
    await Promise.all([
      this.facade.loadCatalogue(companyId),
      this.establishmentsFacade.loadEstablishments(companyId),
    ]);
  }

  protected pickPreset(preset: PeriodPreset | 'custom'): void {
    if (preset === 'custom') {
      this.custom.set(true);
      return;
    }
    this.custom.set(false);
    this.change({ ...presetPeriod(preset, this.today()) });
  }

  /** A day once it is one and the period still runs forwards; what is still being typed waits. */
  protected pickDay(end: 'from' | 'to', day: string): void {
    const query = this.query();
    if (query === null || !validRangeValue('day', day)) return;
    const next = { ...query, [end]: day };
    if (next.from !== null && next.to !== null && next.from <= next.to) this.change(next);
  }

  protected pickComparison(compare: ReportComparison): void {
    this.change({ compare });
  }

  protected pickEstablishment(establishment: string | null): void {
    this.change({ establishment });
  }

  private change(changes: Partial<ReportQuery>): void {
    this.asked.update((asked) =>
      asked === null ? null : { key: asked.key, query: { ...asked.query, ...changes } },
    );
  }

  protected link(row: ReportRow) {
    return row.drill === null ? null : drillLink(row.drill);
  }

  /** A row whose documents are the report's own: the dialog lists them. */
  protected hasDocuments(row: ReportRow): boolean {
    return row.drill !== null && row.drill.list === null;
  }

  protected openDocuments(row: ReportRow): void {
    const companyId = this.company()?.id;
    const query = this.query();
    const first = this.result()?.columns[0];
    if (!companyId || query === null || first === undefined) return;
    const data: ReportDocumentsData = {
      companyId,
      key: this.key(),
      query,
      row: row.key,
      label: this.shown(first, row.cells[first.key] ?? null),
    };
    this.dialog.open<ReportDocumentsDialog, ReportDocumentsData>(ReportDocumentsDialog, {
      data,
      autoFocus: 'dialog',
      width: '40rem',
    });
  }

  /** A cell as words, where words are needed whole: the row's name in the dialog's title. */
  private shown(column: ReportColumn, value: ReportCell): string {
    if (value === null) return this.translate.instant('reports.unnamed') as string;
    return column.kind === 'day' ? this.format.day(String(value)) : String(value);
  }

  protected text(value: ReportCell | undefined): string | null {
    return value === null || value === undefined ? null : String(value);
  }

  private async reload(companyId: string): Promise<void> {
    const query = this.query();
    await Promise.all([
      this.facade.loadCatalogue(companyId),
      query === null ? Promise.resolve() : this.facade.loadReport(companyId, this.key(), query),
    ]);
  }
}

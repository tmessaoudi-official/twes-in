// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatRadioModule } from '@angular/material/radio';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { StatusBadge } from '../shared/ui/status-badge';
import { ImportApi } from './import-api';
import { ImportFacade } from './import-facade';
import {
  TEXT_ENCODINGS,
  type ImportColumn,
  type ImportKeptRecord,
  type ImportMode,
  type ImportNote,
  type ImportPairing,
  type ImportRefusal,
  type ImportRejection,
  type ImportRunSummary,
  type TextEncoding,
} from './import-types';
import { Label } from '../shared/a11y/label';
import { Select, type SelectOption } from '../shared/form/select';
import { FileDrop } from '../shared/form/file-drop';
import { IMPORT_MAX_BYTES } from '../shared/form/file-limits';
import { WINDOW_CLASS } from '../shared/ui/window-class';
import { FormatFacade } from '../shared/i18n/format-facade';
import { MomentPipe } from '../shared/i18n/format-pipes';
import type { ActionConfirm } from '../shared/actions/screen-action';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { firstValueFrom } from 'rxjs';
import { tableCounts } from '../shared/records/record-tables';
import { refusalParams } from '../shared/form/refusal-words';

/** How many of a preview's changes the table lists; the count by column says them all. */
export const CHANGES_SHOWN = 200;

/** The parameters of a note that are quantities, shown with the screen's decimal separator. */
const QUANTITY_PARAMS = new Set(['before', 'after']);
/** The prices a refusal names: a price taxes included no net price comes to, and the nearest two. */
const PRICE_PARAMS = new Set(['gross', 'below', 'above']);
/**
 * What a heading's Select holds for a column set aside, which the API reads as `''`: a Select holding `''` shows
 * nothing chosen, and no column key starts with an asterisk.
 */
const SET_ASIDE = '*set-aside';

/** A heading as the file writes it, compared as the API compares it: trimmed, whatever its case. */
const sameHeading = (one: string, other: string): boolean =>
  one.trim().toLowerCase() === other.trim().toLowerCase();

/** One of the file's headings on the screen: where it goes, what it may go to, and whether a pairing holds it. */
interface PairingRow {
  heading: string;
  /** The Select's value: a column key, `SET_ASIDE`, or `''` while nothing is chosen. */
  value: string;
  options: readonly SelectOption[];
  kept: boolean;
}

/**
 * One screen for every subject a module declares as importable (docs/SPEC.md § 8 row 59). It is driven entirely by
 * the guide the API answers for this company: the columns, what a row is found again by, and the row cap all come
 * from there, so a module that declares a new subject gets this screen without a line of code here.
 *
 * The order it asks for is the order the work is done in: take the empty file, fill it in, PREVIEW it, then import.
 * A preview is the same run rolled back, so what it says is exactly what an import would do — which is why the
 * import button only lights up once a preview has come back with nothing rejected.
 */
@Component({
  selector: 'app-import-page',
  imports: [
    FileDrop,
    FormsModule,
    Label,
    Select,
    TranslatePipe,
    MatButtonModule,
    MatCheckboxModule,
    MatIconModule,
    MatRadioModule,
    MomentPipe,
    StatusBadge,
  ],
  templateUrl: './import-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImportPage {
  protected readonly maxBytes = IMPORT_MAX_BYTES;
  /** Bound from the route parameter by withComponentInputBinding(): `customers`, `products`, `opening-stock`… */
  readonly subject = input.required<string>();

  private readonly facade = inject(ImportFacade);
  private readonly api = inject(ImportApi);
  private readonly auth = inject(AuthFacade);
  private readonly translate = inject(TranslateService);
  private readonly windowClass = inject(WINDOW_CLASS);
  private readonly format = inject(FormatFacade);
  private readonly dialog = inject(MatDialog);

  protected readonly guide = this.facade.guide;
  protected readonly report = this.facade.report;
  protected readonly refusal = this.facade.refusal;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly recent = this.facade.recent;
  protected readonly undone = this.facade.undone;

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  /** The chosen file, if any: its name is shown back so a person knows which one is about to run. */
  protected readonly file = signal<File | null>(null);
  protected readonly mode = signal<ImportMode>('create');
  /** What a CSV is read as, when the person chose it; null lets the API find it on the file. */
  protected readonly encoding = signal<TextEncoding | null>(null);
  protected readonly encodings = TEXT_ENCODINGS;
  /** Only a CSV has an encoding to choose: a workbook's text is UTF-8 whatever wrote it. */
  protected readonly isCsv = computed(
    () => this.file()?.name.toLowerCase().endsWith('.csv') === true,
  );
  /** The switches of the file ticked for this run, by key; each is off until ticked. */
  protected readonly ticked = signal<readonly string[]>([]);
  /** The answer chosen for each switch that is a choice, by key; one not chosen is its first. */
  protected readonly chosen = signal<Readonly<Record<string, string>>>({});
  /** A preview that came back with nothing rejected: only then is importing more than a guess. */
  protected readonly previewed = signal(false);
  /**
   * Where the file's own headings go, sent with every run. It starts as the last import kept it, so a file written
   * the same way reads the same way; the list is shown from the start, before any preview, because a preview of new
   * rows shows no cell, and a heading set aside by an old choice would leave its column out unseen.
   */
  protected readonly pairing = signal<readonly ImportPairing[]>([]);
  /** The pairing is still the one the last import kept, untouched. */
  protected readonly pairingFromLast = signal(false);
  /**
   * The headings a refusal asked about. Kept apart from the refusal, which a choice clears: the person pairs one
   * heading, and the others the refusal named stay on the screen to pair.
   */
  private readonly asked = signal<readonly string[]>([]);
  /** Re-reads the columns' headings when the language changes. */
  private readonly language = toSignal(this.translate.onLangChange, { initialValue: null });
  /** The headings asked about first, in the file's order, then the ones a pairing holds. */
  protected readonly pairingRows = computed<readonly PairingRow[]>(() => {
    this.language();
    const guide = this.guide();
    if (guide === null) return [];
    const pairing = this.pairing();
    const headings = [...this.asked()];
    for (const pair of pairing) {
      if (!headings.some((heading) => sameHeading(heading, pair.heading))) {
        headings.push(pair.heading);
      }
    }
    return headings.map((heading, index) => {
      const pair = pairing.find((candidate) => sameHeading(candidate.heading, heading));
      return {
        heading,
        value: pair === undefined ? '' : pair.column === '' ? SET_ASIDE : pair.column,
        options: this.pairingOptions(guide.columns, `import-pairing-${index}`),
        kept: pair !== undefined,
      };
    });
  });

  /**
   * The subject's words. The URL says `opening-stock` and the catalogue says `opening_stock`, because one is a path
   * and the other a key; this is the one place that knows both spellings.
   */
  protected readonly subjectKey = computed(() => this.subject().replace(/-/g, '_'));
  protected readonly compact = computed(() => this.windowClass() === 'compact');
  /** Each column a preview changes, with how many lines change it, in the order the changes come. */
  protected readonly changedColumns = computed(() => {
    const lines = new Map<string, Set<number>>();
    for (const change of this.report()?.changes ?? []) {
      lines.set(change.column, (lines.get(change.column) ?? new Set()).add(change.line));
    }
    return [...lines].map(([column, of]) => ({ column, count: of.size }));
  });
  protected readonly shownChanges = computed(() =>
    (this.report()?.changes ?? []).slice(0, CHANGES_SHOWN),
  );
  protected readonly changesShown = CHANGES_SHOWN;
  /** The rows refused: a row says every fault it holds, and the count beside « créés » counts rows. */
  protected readonly rejectedRows = computed(
    () => new Set(this.report()?.rejected.map((rejection) => rejection.line)).size,
  );

  /**
   * The columns a row is found again by, each named as its heading reads with its key beside it, as the file writes
   * it. A method, read at each check like `reasonOf`, so a change of language renames them.
   */
  protected identity(): string {
    const guide = this.guide();
    if (guide === null) return '';
    return guide.identity
      .map((key) =>
        this.named(
          key,
          guide.columns.find((candidate) => candidate.key === key),
        ),
      )
      .join(', ');
  }

  /** A column as its heading reads, its key beside it as the file writes it. */
  private named(key: string, column: ImportColumn | undefined): string {
    const heading =
      column === undefined
        ? null
        : column.headingKey !== null
          ? (this.translate.instant(column.headingKey) as string)
          : column.label;
    return heading === null || heading === '' || heading === key ? key : `${heading} (${key})`;
  }

  /** What a heading may go to: every column of the subject, then nowhere. */
  private pairingOptions(columns: readonly ImportColumn[], testId: string): SelectOption[] {
    return [
      ...columns.map((column) => ({
        value: column.key,
        label: this.named(column.key, column),
        testId: `${testId}-option-${column.key}`,
      })),
      {
        value: SET_ASIDE,
        label: this.translate.instant('import.pairing.ignored') as string,
        testId: `${testId}-option-ignored`,
      },
    ];
  }

  constructor() {
    effect(() => {
      const companyId = this.company()?.id;
      const subject = this.subject();
      if (companyId === undefined) return;
      this.file.set(null);
      this.previewed.set(false);
      this.ticked.set([]);
      this.chosen.set({});
      void this.facade.load(companyId, subject);
      void this.facade.loadRecent(companyId);
    });
    // Each subject's guide brings the pairing its last import kept.
    effect(() => {
      const kept = this.guide()?.lastPairing ?? [];
      this.pairing.set(kept);
      this.pairingFromLast.set(kept.length > 0);
      this.asked.set([]);
    });
  }

  /**
   * A note in the person's words, its quantities written as the screen writes a decimal; one whose code this screen
   * does not know yet reads as nothing more than its code.
   */
  protected noteOf(note: ImportNote): string {
    // A cell set aside says why in the words its refusal would have used: the format, an example, what to do.
    if (note.code === 'set_aside') {
      const { value, reason, ...rest } = note.params;
      const why = this.reasonOf({
        line: note.line,
        column: note.column,
        code: String(reason),
        params: rest,
        message: String(reason),
      });

      return this.translate.instant('import.notes.set_aside', { value, why }) as string;
    }
    const key = `import.notes.${note.code}`;
    const params = Object.fromEntries(
      Object.entries(note.params).map(([name, value]) => [
        name,
        QUANTITY_PARAMS.has(name) && typeof value === 'string' ? this.format.decimal(value) : value,
      ]),
    );
    const said = this.translate.instant(key, params) as string;

    return said === key ? note.code : said;
  }

  /**
   * Why a row was refused, in the person's words, its prices written as the screen writes a decimal. A code this
   * screen does not know yet falls back to the API's own English sentence, which is a poor reading but never a blank
   * cell — and better than showing the raw code.
   */
  protected reasonOf(rejection: ImportRejection): string {
    const key = `import.rejections.${rejection.code}`;
    const params = refusalParams(
      this.translate,
      Object.fromEntries(
        Object.entries(rejection.params).map(([name, value]) => [
          name,
          PRICE_PARAMS.has(name) && typeof value === 'string' ? this.format.decimal(value) : value,
        ]),
      ),
    );
    const said = this.translate.instant(key, params) as string;

    return said === key ? rejection.message : said;
  }

  protected templateUrl(format: 'csv' | 'xlsx'): string {
    const companyId = this.company()?.id;

    return companyId === undefined ? '' : this.api.templateUrl(companyId, this.subject(), format);
  }

  /** A subject's words, from the path an import names it by. */
  protected subjectOf(run: ImportRunSummary): string {
    return this.translate.instant(`import.subjects.${run.subject.replace(/-/g, '_')}`) as string;
  }

  /** « 2 lignes de devis et 1 facture », as the bin's refusals count what names a record. */
  protected usesOf(record: ImportKeptRecord): string {
    const language =
      this.translate.getCurrentLang() ?? this.translate.getFallbackLang() ?? undefined;
    return new Intl.ListFormat(language, { type: 'conjunction' }).format(
      tableCounts(record.uses).map(
        ({ key, count }) => this.translate.instant(key, { count }) as string,
      ),
    );
  }

  /** A quantity the undo took back, as the screen writes a decimal. */
  protected quantity(value: string): string {
    return this.format.decimal(value);
  }

  /**
   * « Annuler cet import », once its consequences are read: what went is put back from « Récemment supprimés » for a
   * day, but what the file changed stays changed and an opening stock's undo makes no delete to put back, so the
   * person is told before rather than offered an « Annuler » that cannot cover it all.
   */
  protected async undo(run: string): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId === undefined) return;
    const confirm: ActionConfirm = {
      kind: 'corrigeable',
      title: 'import.undo.confirm_title',
      message: 'import.undo.confirm_message',
      confirmLabel: 'import.undo.confirm',
      keepLabel: 'import.undo.keep',
    };
    const answer = await firstValueFrom(
      this.dialog
        .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
          data: confirm,
          autoFocus: 'dialog',
        })
        .afterClosed(),
    );
    if (answer !== true) return;
    if (await this.facade.undo(companyId, run)) {
      this.file.set(null);
      this.previewed.set(false);
    }
  }

  protected chooseFile(file: File): void {
    this.file.set(file);
    // The pairing is how the shop writes its files, so it stays; the headings asked about were this file's.
    this.asked.set([]);
    // Another file may be written in another encoding: it is found on that file until chosen again.
    this.encoding.set(null);
    this.previewed.set(false);
    this.facade.forget();
  }

  protected chooseMode(mode: ImportMode): void {
    this.mode.set(mode);
    this.previewed.set(false);
    this.facade.forget();
  }

  /** Read in another encoding, the file says other things: the preview before no longer stands. */
  protected chooseEncoding(encoding: TextEncoding | null): void {
    this.encoding.set(encoding);
    this.previewed.set(false);
    this.facade.forget();
  }

  /** A switch changes what the run does, so the preview before it no longer says what an import would do. */
  protected tick(key: string, on: boolean): void {
    this.ticked.update((keys) =>
      on ? [...keys.filter((other) => other !== key), key] : keys.filter((other) => other !== key),
    );
    this.previewed.set(false);
    this.facade.forget();
  }

  /** Another answer changes what the run does, as a switch does. */
  protected choose(key: string, answer: string): void {
    this.chosen.update((chosen) => ({ ...chosen, [key]: answer }));
    this.previewed.set(false);
    this.facade.forget();
  }

  /** A heading paired anew: the preview before it no longer says what an import would do. */
  protected choosePairing(heading: string, value: string | string[] | null): void {
    if (typeof value !== 'string' || value === '') return;
    const column = value === SET_ASIDE ? '' : value;
    this.pairing.update((pairs) => {
      const at = pairs.findIndex((pair) => sameHeading(pair.heading, heading));
      return at === -1
        ? [...pairs, { heading, column }]
        : pairs.map((pair, index) => (index === at ? { heading: pair.heading, column } : pair));
    });
    this.pairingFromLast.set(false);
    this.previewed.set(false);
    this.facade.forget();
  }

  /** The heading is read as the file writes it again, as a column's own key or not at all. */
  protected forgetPairing(heading: string): void {
    this.pairing.update((pairs) => pairs.filter((pair) => !sameHeading(pair.heading, heading)));
    this.asked.update((headings) => headings.filter((other) => !sameHeading(other, heading)));
    this.pairingFromLast.set(false);
    this.previewed.set(false);
    this.facade.forget();
  }

  /**
   * The headings a refusal names are asked about. A heading whose pairing names a column the subject no longer has
   * loses that pairing, so it is asked about as though it were new.
   */
  private askAbout(refusal: ImportRefusal | null): void {
    if (refusal?.reason !== 'unknown_columns' && refusal?.reason !== 'invalid_pairing') return;
    const named = refusal.columns;
    if (refusal.reason === 'invalid_pairing') {
      this.pairing.update((pairs) =>
        pairs.filter((pair) => !named.some((heading) => sameHeading(heading, pair.heading))),
      );
    }
    this.asked.update((headings) => [
      ...headings,
      ...named.filter((heading) => !headings.some((other) => sameHeading(other, heading))),
    ]);
  }

  protected async preview(): Promise<void> {
    await this.run(true);
    const report = this.report();
    this.previewed.set(report !== null && report.rejected.length === 0);
  }

  protected async store(): Promise<void> {
    if (!(await this.changesConfirmed())) return;
    await this.run(false);
    const report = this.report();
    if (report?.committed === true) {
      this.file.set(null);
      this.previewed.set(false);
    }
  }

  /**
   * An upsert that changes records says how many and which columns before it runs: the values it replaces are kept
   * nowhere, and « Annuler cet import » leaves what it changed as changed. A file that only adds asks nothing.
   */
  private async changesConfirmed(): Promise<boolean> {
    const report = this.report();
    // Only an upsert finds records again, so only an upsert's preview holds changes.
    if (report === null || report.changes.length === 0) return true;
    const confirm: ActionConfirm = {
      kind: 'corrigeable',
      title: 'import.confirm_changes.title',
      message: 'import.confirm_changes.message',
      messageParams: {
        records: String(report.updated.length),
        fields: this.changedColumns()
          .map((column) => `${column.column} (${column.count})`)
          .join(', '),
      },
      confirmLabel: 'import.confirm_changes.store',
      keepLabel: 'import.confirm_changes.keep',
    };
    const answer = await firstValueFrom(
      this.dialog
        .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
          data: confirm,
          autoFocus: 'dialog',
        })
        .afterClosed(),
    );
    return answer === true;
  }

  private async run(dryRun: boolean): Promise<void> {
    const companyId = this.company()?.id;
    const file = this.file();
    if (companyId === undefined || file === null) return;
    await this.facade.run(
      companyId,
      this.subject(),
      file,
      this.mode(),
      dryRun,
      this.ticked(),
      this.encoding(),
      this.pairing(),
      this.chosen(),
    );
    this.askAbout(this.refusal());
  }
}

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
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatIconModule } from '@angular/material/icon';
import { MatRadioModule } from '@angular/material/radio';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { StatusBadge } from '../shared/ui/status-badge';
import { ImportApi } from './import-api';
import { ImportFacade } from './import-facade';
import type { ImportMode, ImportNote, ImportRejection } from './import-types';
import { FileDrop } from '../shared/form/file-drop';
import { IMPORT_MAX_BYTES } from '../shared/form/file-limits';
import { WINDOW_CLASS } from '../shared/ui/window-class';
import { FormatFacade } from '../shared/i18n/format-facade';
import { MomentPipe } from '../shared/i18n/format-pipes';

/** The parameters of a note that are quantities, shown with the screen's decimal separator. */
const QUANTITY_PARAMS = new Set(['before', 'after']);

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

  protected readonly guide = this.facade.guide;
  protected readonly report = this.facade.report;
  protected readonly refusal = this.facade.refusal;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  /** The chosen file, if any: its name is shown back so a person knows which one is about to run. */
  protected readonly file = signal<File | null>(null);
  protected readonly mode = signal<ImportMode>('create');
  /** The switches of the file ticked for this run, by key; each is off until ticked. */
  protected readonly ticked = signal<readonly string[]>([]);
  /** A preview that came back with nothing rejected: only then is importing more than a guess. */
  protected readonly previewed = signal(false);

  /**
   * The subject's words. The URL says `opening-stock` and the catalogue says `opening_stock`, because one is a path
   * and the other a key; this is the one place that knows both spellings.
   */
  protected readonly subjectKey = computed(() => this.subject().replace(/-/g, '_'));
  protected readonly compact = computed(() => this.windowClass() === 'compact');

  /**
   * The columns a row is found again by, each named as its heading reads with its key beside it, as the file writes
   * it. A method, read at each check like `reasonOf`, so a change of language renames them.
   */
  protected identity(): string {
    const guide = this.guide();
    if (guide === null) return '';
    return guide.identity
      .map((key) => {
        const column = guide.columns.find((candidate) => candidate.key === key);
        const heading =
          column === undefined
            ? null
            : column.headingKey !== null
              ? (this.translate.instant(column.headingKey) as string)
              : column.label;
        return heading === null || heading === '' || heading === key ? key : `${heading} (${key})`;
      })
      .join(', ');
  }

  constructor() {
    effect(() => {
      const companyId = this.company()?.id;
      const subject = this.subject();
      if (companyId === undefined) return;
      this.file.set(null);
      this.previewed.set(false);
      this.ticked.set([]);
      void this.facade.load(companyId, subject);
    });
  }

  /**
   * A note in the person's words, its quantities written as the screen writes a decimal; one whose code this screen
   * does not know yet reads as nothing more than its code.
   */
  protected noteOf(note: ImportNote): string {
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
   * Why a row was refused, in the person's words. A code this screen does not know yet falls back to the API's own
   * English sentence, which is a poor reading but never a blank cell — and better than showing the raw code.
   */
  protected reasonOf(rejection: ImportRejection): string {
    const key = `import.rejections.${rejection.code}`;
    const said = this.translate.instant(key, rejection.params) as string;

    return said === key ? rejection.message : said;
  }

  protected templateUrl(format: 'csv' | 'xlsx'): string {
    const companyId = this.company()?.id;

    return companyId === undefined ? '' : this.api.templateUrl(companyId, this.subject(), format);
  }

  protected chooseFile(file: File): void {
    this.file.set(file);
    this.previewed.set(false);
    this.facade.forget();
  }

  protected chooseMode(mode: ImportMode): void {
    this.mode.set(mode);
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

  protected async preview(): Promise<void> {
    await this.run(true);
    const report = this.report();
    this.previewed.set(report !== null && report.rejected.length === 0);
  }

  protected async store(): Promise<void> {
    await this.run(false);
    const report = this.report();
    if (report?.committed === true) {
      this.file.set(null);
      this.previewed.set(false);
    }
  }

  private async run(dryRun: boolean): Promise<void> {
    const companyId = this.company()?.id;
    const file = this.file();
    if (companyId === undefined || file === null) return;
    await this.facade.run(companyId, this.subject(), file, this.mode(), dryRun, this.ticked());
  }
}

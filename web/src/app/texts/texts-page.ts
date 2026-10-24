// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  type OnInit,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { Select } from '../shared/form/select';
import { languageChoices } from '../shared/i18n/language-options';
import { DEFAULT_LANGUAGE, INTERFACE_LANGUAGES } from '../shared/i18n/languages';
import { DataList } from '../shared/list/data-list';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { TextDialog, type TextDialogData } from './text-dialog';
import { TextsFacade } from './texts-facade';
import {
  marksOf,
  placeOf,
  type SavedText,
  type TextLevel,
  TEXT_PLACES,
  type TextRow,
} from './texts-types';

/** A text as the list shows it: where it is read, in the screen's words, beside its wording at each level. */
interface TextView {
  readonly id: string;
  readonly where: string;
  readonly key: string;
  readonly original: string;
  readonly installation: string | null;
  readonly company: string | null;
  readonly row: TextRow;
}

/** The kind of live change a text worded otherwise is told by (the API's `OverrideText::ENTITY_TYPE`). */
const TEXT_CHANGES = ['text'];

/**
 * « Textes »: every text of the application a level may word otherwise, found by what it says or by its key, each with
 * its original and the wording of each level. The installation's page words them for every company; a company's words
 * them for itself over the installation's. A change and a text taken back are said in a toast that offers « Annuler »;
 * a text whose original changed since it was worded is offered « Garder le mien » and « Prendre l'original ».
 */
@Component({
  selector: 'app-texts-page',
  imports: [DataList, FormsModule, MatIconModule, RouterLink, Select, TranslatePipe],
  templateUrl: './texts-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TextsPage implements OnInit {
  private readonly facade = inject(TextsFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly dialog = inject(MatDialog);
  private readonly translate = inject(TranslateService);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  /** Read inside `views`, so the places are said again when the screen's language changes. */
  private readonly screen = toSignal(this.translate.onLangChange, { initialValue: null });

  /** Whose wording this page writes, from the route: the installation's under /platform, else the company's. */
  readonly level = input<'company' | 'installation'>('company');
  protected readonly installation = computed(() => this.level() === 'installation');

  /** The language whose texts are listed: the screen's, when the interface speaks it. */
  protected readonly language = signal(
    INTERFACE_LANGUAGES.find((tag) => tag === this.translate.getCurrentLang()) ?? DEFAULT_LANGUAGE,
  );
  protected readonly languageOptions = languageChoices(INTERFACE_LANGUAGES, 'texts-language');

  protected readonly loading = this.facade.busy;
  protected readonly failed = this.facade.failed;
  protected readonly total = this.facade.total;
  protected readonly views = computed(() => {
    this.screen();
    return this.facade.rows().map((row): TextView => ({
      id: `${row.domain}|${row.key}`,
      where: this.translate.instant(`texts.where.${placeOf(row.domain) ?? 'other'}`),
      key: row.key,
      original: row.original,
      installation: row.installation,
      company: row.company,
      row,
    }));
  });
  protected readonly rowTestId = (view: TextView): string => `text-${view.row.domain}-${view.key}`;

  protected readonly list = computed<ListDescriptor<TextView>>(() => ({
    id: this.installation() ? 'platform-texts' : 'company-texts',
    rowId: (view) => view.id,
    pageSizes: [25, 50, 100, 200],
    columns: [
      { id: 'where', label: 'texts.columns.where', value: (view) => view.where, width: 140 },
      {
        id: 'original',
        label: 'texts.columns.original',
        value: (view) => view.original,
        hideable: false,
        wraps: true,
        width: 240,
      },
      {
        id: 'installation',
        label: this.installation() ? 'texts.columns.yours' : 'texts.columns.installation',
        value: (view) => view.installation,
        wraps: true,
        width: 240,
        priority: this.installation() ? 'high' : 'normal',
      },
      ...(this.installation()
        ? []
        : [
            {
              id: 'company',
              label: 'texts.columns.yours',
              value: (view: TextView) => view.company,
              wraps: true,
              width: 240,
              priority: 'high' as const,
            },
          ]),
      {
        id: 'key',
        label: 'texts.columns.key',
        value: (view) => view.key,
        defaultHidden: true,
        priority: 'low',
      },
    ],
    filters: [
      {
        id: 'place',
        label: 'texts.filters.place',
        value: (view) => placeOf(view.row.domain),
        options: TEXT_PLACES.map((place) => ({ value: place, label: `texts.where.${place}` })),
      },
      {
        id: 'changed',
        label: 'texts.filters.changed',
        value: (view) => (view.row.originalChanged ? 'changed' : null),
        options: [{ value: 'changed', label: 'texts.filters.original_changed' }],
      },
    ],
    actions: [
      {
        id: 'edit',
        label: 'texts.actions.edit',
        icon: 'edit',
        run: (view) => void this.edit(view.row),
        next: () => true,
        disabled: () => this.loading(),
      },
      {
        id: 'keep',
        label: 'texts.actions.keep_mine',
        icon: 'check',
        run: (view) => void this.keep(view.row),
        shown: (view) => view.row.originalChanged && this.own(view.row) !== null,
        disabled: () => this.loading(),
      },
      {
        id: 'reset',
        label: 'texts.actions.reset',
        labelOf: (view) =>
          view.row.originalChanged ? 'texts.actions.take_original' : 'texts.actions.reset',
        icon: 'undo',
        run: (view) => void this.reset(view.row),
        shown: (view) => this.own(view.row) !== null,
        rare: (view) => !view.row.originalChanged,
        undoable: true,
        disabled: () => this.loading(),
      },
    ],
  }));

  /** The page the list last asked for; a change heard, or another language, reads it again. */
  private last: ListQuery | null = null;

  ngOnInit(): void {
    this.live.reloadOn(TEXT_CHANGES, () => this.reload(), this.destroyRef);
  }

  protected onQuery(query: ListQuery): void {
    this.last = query;
    void this.reload();
  }

  protected choose(language: string | null): void {
    const chosen = INTERFACE_LANGUAGES.find((tag) => tag === language);
    if (chosen === undefined || chosen === this.language()) return;
    this.language.set(chosen);
    void this.reload();
  }

  private async reload(): Promise<void> {
    const level = this.levelNow();
    const last = this.last;
    if (level === null || last === null) return;
    await this.facade.load(level, this.language(), {
      words: last.query,
      place: TEXT_PLACES.find((place) => place === last.filters['place']) ?? null,
      changed: last.filters['changed'] === 'changed',
      page: last.pageIndex + 1,
      itemsPerPage: last.pageSize,
    });
  }

  private levelNow(): TextLevel | null {
    if (this.installation()) return { kind: 'installation' };
    const companyId = this.auth.me()?.company?.id;
    return companyId ? { kind: 'company', companyId } : null;
  }

  /** This level's own wording of the text, or null where it words nothing. */
  private own(row: TextRow): string | null {
    return this.installation() ? row.installation : row.company;
  }

  private async edit(row: TextRow): Promise<void> {
    const level = this.levelNow();
    if (level === null) return;
    const language = this.language();
    const before = this.own(row);
    const data: TextDialogData = {
      language,
      original: row.original,
      installation: this.installation() ? null : row.installation,
      value: before ?? row.installation ?? row.original,
      marks: marksOf(row.original, row.domain),
      save: (value) => this.facade.write(level, language, row, value, row.version),
    };
    const ref = this.dialog.open<TextDialog, TextDialogData, SavedText | null>(TextDialog, {
      data,
      autoFocus: 'first-tabbable',
      width: '40rem',
    });
    const saved = await firstValueFrom(ref.afterClosed());
    if (saved === null || saved === undefined) return;
    this.feedback.effect('texts.saved', {}, 'annulable', {
      key: 'texts.undo',
      run: () => void this.putBack(level, language, row, before, saved.version),
    });
  }

  /** « Garder le mien »: the same words written again over today's original, which the flag then leaves. */
  private async keep(row: TextRow): Promise<void> {
    const level = this.levelNow();
    const mine = this.own(row);
    if (level === null || mine === null) return;
    const outcome = await this.facade.write(level, this.language(), row, mine, row.version);
    if (outcome.kept) this.feedback.success('texts.kept');
    else this.refused(outcome.refusal);
  }

  /** Takes this level's wording back: the text reads as the level under it, or as shipped. */
  private async reset(row: TextRow): Promise<void> {
    const level = this.levelNow();
    const before = this.own(row);
    if (level === null || before === null) return;
    const language = this.language();
    const outcome = await this.facade.reset(level, language, row);
    if (!outcome.kept) {
      this.refused(outcome.refusal);
      return;
    }
    this.feedback.effect('texts.reset_done', {}, 'annulable', {
      key: 'texts.undo',
      run: () => void this.putBack(level, language, row, before, 0),
    });
  }

  /** « Annuler »: the wording the level had before, written again over the version the change left, or taken away. */
  private async putBack(
    level: TextLevel,
    language: string,
    row: TextRow,
    before: string | null,
    version: number,
  ): Promise<void> {
    const outcome =
      before === null
        ? await this.facade.reset(level, language, row)
        : await this.facade.write(level, language, row, before, version);
    if (outcome.kept) this.feedback.success('texts.undone');
    else this.refused(outcome.refusal);
  }

  private refused(refusal: string | null): void {
    if (refusal !== null) this.feedback.failure(`texts.refusals.${refusal}`);
  }
}

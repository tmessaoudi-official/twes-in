// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatRadioModule } from '@angular/material/radio';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { RemovedItem, TableCount } from './record-removals-api';

/**
 * A record offered for a merge, with what names it that a merge moves to the record kept, by table, and what names it
 * and stays, for a subject whose records stay as aliases.
 */
export interface MergeCandidate extends RemovedItem {
  readonly moves: readonly TableCount[];
  readonly stays?: readonly TableCount[];
}

export interface RecordMergeDialogData {
  /** The subject's table, which names the records in the title. */
  readonly subject: string;
  /** In the order they were ticked; the first is kept unless the person picks another. */
  readonly candidates: readonly MergeCandidate[];
  /** Whether the records carry defaults of their own, which move too unless the record kept sets them. */
  readonly settings: boolean;
  /** Whether a record merged away stays, switched off and pointing at the one kept, rather than going. */
  readonly aliased?: boolean;
}

/**
 * Asks which of the records ticked is kept, and says before anything runs what the merge does to each of the others
 * (a bulk change previews what it does): it goes, and what names it moves to the one kept. The dialog answers with the
 * record kept, or null to merge nothing; the merge itself is undone from its toast or « Récemment supprimés ».
 */
@Component({
  selector: 'app-record-merge-dialog',
  imports: [MatButtonModule, MatDialogModule, MatRadioModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="record-merge-title">
      {{ 'records.merge.title' | translate: { what: what } }}
    </h2>
    <mat-dialog-content>
      <div class="flex flex-col gap-4">
        <mat-radio-group
          [value]="keptId()"
          (change)="keptId.set($event.value)"
          [attr.aria-label]="'records.merge.keep' | translate"
          class="flex flex-col"
          data-testid="record-merge-kept"
        >
          <span class="font-medium">{{ 'records.merge.keep' | translate }}</span>
          @for (candidate of data.candidates; track candidate.id) {
            <mat-radio-button
              [value]="candidate.id"
              [attr.data-testid]="'record-merge-keep-' + candidate.id"
            >
              <bdi>{{ candidate.label }}</bdi>
            </mat-radio-button>
          }
        </mat-radio-group>
        <ul class="m-0 flex list-none flex-col gap-1 p-0" data-testid="record-merge-preview">
          @for (line of preview(); track line.id) {
            <!-- value-direction: a text in the screen's language, which reads in the screen's direction -->
            <li>{{ line.text }}</li>
          }
        </ul>
        @if (data.settings) {
          <p class="m-0">{{ 'records.merge.settings' | translate }}</p>
        }
        <p class="m-0 text-sm text-on-surface-variant">
          {{
            (data.aliased ? 'records.merge.undo_hint_alias' : 'records.merge.undo_hint') | translate
          }}
        </p>
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="record-merge-cancel">
        {{ 'records.merge.cancel' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        (click)="ref.close(kept())"
        data-testid="record-merge-confirm"
      >
        {{ 'records.merge.confirm' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class RecordMergeDialog {
  protected readonly ref =
    inject<MatDialogRef<RecordMergeDialog, RemovedItem | null>>(MatDialogRef);
  protected readonly data = inject<RecordMergeDialogData>(MAT_DIALOG_DATA);
  private readonly translate = inject(TranslateService);

  protected readonly what: string = this.translate.instant(
    `records.recent.subjects.${this.data.subject}`,
    { count: this.data.candidates.length },
  );
  protected readonly keptId = signal(this.data.candidates[0].id);
  protected readonly kept = computed(() => {
    const kept = this.data.candidates.find((candidate) => candidate.id === this.keptId());
    return kept === undefined ? null : { id: kept.id, label: kept.label };
  });

  /** What happens to each record but the one kept, in words. */
  protected readonly preview = computed(() =>
    this.data.candidates
      .filter((candidate) => candidate.id !== this.keptId())
      .map((candidate) => ({ id: candidate.id, text: this.said(candidate) })),
  );

  /**
   * One record's fate: it goes, or it stays switched off as an alias of the one kept; what names it moves; and, for an
   * alias, what names it stays as the books and the stock recorded it.
   */
  private said(candidate: MergeCandidate): string {
    const name = candidate.label;
    const moves = this.counted(candidate.moves);
    const [moved, still] = this.data.aliased ? ['alias', 'alias_empty'] : ['goes', 'goes_empty'];
    const fate =
      moves === null
        ? this.translate.instant(`records.merge.${still}`, { name })
        : this.translate.instant(`records.merge.${moved}`, { name, moves });
    const stays = this.data.aliased ? this.counted(candidate.stays ?? []) : null;
    return stays === null
      ? fate
      : `${fate} ${this.translate.instant('records.merge.alias_stays', { name, stays })}`;
  }

  /** « 2 produits et 1 sous-catégorie », or null when no table counts a row. */
  private counted(tables: readonly TableCount[]): string | null {
    const counted = tables.filter((table) => table.count > 0);
    return counted.length === 0
      ? null
      : this.list(
          counted.map((table) =>
            this.translate.instant(`records.tables.${table.table}`, { count: table.count }),
          ),
        );
  }

  /** « a, b et c » as the screen's language joins a list. */
  private list(parts: readonly string[]): string {
    const language =
      this.translate.getCurrentLang() ?? this.translate.getFallbackLang() ?? undefined;
    return new Intl.ListFormat(language, { type: 'conjunction' }).format(parts);
  }
}

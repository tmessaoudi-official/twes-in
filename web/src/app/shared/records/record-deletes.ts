// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { Feedback } from '../feedback/feedback';
import { TextsParts } from '../i18n/texts-part';
import { StepUp } from '../step-up/step-up';
import {
  RecordRemovalsApi,
  RemovalRefused,
  type RecordInUse,
  type RecordRemoval,
  type MergePreview,
  type RemovedItem,
  type TableCount,
} from './record-removals-api';
import type { RecordMergeDialog, RecordMergeDialogData } from './record-merge-dialog';
import { tableCounts } from './record-tables';
import { RECORDS_TEXTS } from './records-texts';
import { refusedFor } from '../errors/said-already';

/** The subjects the API lets a person delete, each also the kind its live changes carry. */
export const DELETABLE_SUBJECTS = [
  'vendor',
  'product',
  'product_category',
  'expense_category',
  'customer',
  'invoice',
  'quote',
  'delivery_note',
] as const;

/** The columns a merge refusal names in words, each saying how the records differ; any other gets the plain refusal. */
const MISMATCH_COLUMNS = ['identifiers'] as const;

/**
 * What a module refuses a merge for, each said in words: a receipt waiting for its cost, goods only whoever reads
 * costs carries, a serial number the record kept already holds, a lot of one code dated otherwise.
 */
const MERGE_REFUSALS = [
  'cost_pending',
  'costs_unseen',
  'serial_held',
  'lot_dated_otherwise',
] as const;

/**
 * Deleting or merging records and putting them back, the same on every screen that does either: nothing asks first, since the toast
 * offers « Annuler » and « Récemment supprimés » keeps the delete a day (undo first, preview the rest); a record still
 * in use refuses the whole delete, the toast naming what uses each. The tab hears its own change, which the API tells
 * every other tab.
 */
@Injectable({ providedIn: 'root' })
export class RecordDeletes {
  private readonly api = inject(RecordRemovalsApi);
  private readonly feedback = inject(Feedback);
  private readonly translate = inject(TranslateService);
  private readonly stepUp = inject(StepUp);
  private readonly dialog = inject(MatDialog);
  private readonly texts = inject(TextsParts);

  /** Deletes the records, all of them or none; answers whether they went. */
  async remove(
    companyId: string,
    subject: string,
    items: readonly RemovedItem[],
  ): Promise<boolean> {
    try {
      await this.words();
      const removal = await this.api.remove(
        companyId,
        subject,
        items.map((item) => item.id),
      );
      // Each key written out in full: the outcome gate reads them in the call.
      const one = this.only(removal);
      this.feedback.effect(
        one === null ? 'records.deleted_many' : 'records.deleted_one',
        this.params(removal),
        'annulable',
        {
          key: 'records.undo',
          run: () => void this.restore(companyId, removal),
        },
      );
      return true;
    } catch (error) {
      this.refused(error);
      return false;
    }
  }

  /**
   * Asks which of the records is kept, saying what the merge does to each of the others, then merges them into it;
   * answers whether they went, false too when the person merged nothing.
   */
  async offerMerge(companyId: string, data: RecordMergeDialogData): Promise<boolean> {
    try {
      await this.words();
    } catch (error) {
      this.refused(error);
      return false;
    }
    // Loaded when first asked, as a dialog no page opens on arrival.
    const component = (await import('./record-merge-dialog')).RecordMergeDialog;
    const ref = this.dialog.open<RecordMergeDialog, RecordMergeDialogData, RemovedItem | null>(
      component,
      { data, autoFocus: 'first-tabbable' },
    );
    const kept = await firstValueFrom(ref.afterClosed());
    if (kept === null || kept === undefined) return false;
    return this.merge(
      companyId,
      data.subject,
      kept,
      data.candidates.filter((candidate) => candidate.id !== kept.id),
    );
  }

  /**
   * Asks the API what merging these records would move and leave, then offers the merge as `offerMerge` does; answers
   * whether they went. A subject whose screen does not already know what names each record uses this one.
   */
  async offerMergeOf(
    companyId: string,
    subject: string,
    ids: readonly string[],
    shape: { readonly settings: boolean; readonly aliased: boolean },
  ): Promise<boolean> {
    let candidates: MergePreview[];
    try {
      candidates = await this.api.previewMerge(companyId, subject, ids);
    } catch (error) {
      this.refused(error);
      return false;
    }
    return this.offerMerge(companyId, { subject, candidates, ...shape });
  }

  /**
   * Merges the records into the one kept, all of them or none, once who is at the screen is proved; answers whether
   * they went. The toast offers « Annuler », which undoes the merge as « Récemment supprimés » does.
   */
  async merge(
    companyId: string,
    subject: string,
    kept: RemovedItem,
    items: readonly RemovedItem[],
  ): Promise<boolean> {
    try {
      await this.words();
      const merge = await this.proved(() =>
        this.api.merge(
          companyId,
          subject,
          kept.id,
          items.map((item) => item.id),
        ),
      );
      const one = this.only(merge);
      this.feedback.effect(
        one === null ? 'records.merged_many' : 'records.merged_one',
        { ...this.params(merge), kept: kept.label },
        'annulable',
        {
          key: 'records.undo',
          run: () => void this.restore(companyId, merge),
        },
      );
      return true;
    } catch (error) {
      this.refused(error);
      return false;
    }
  }

  /**
   * Puts back what a delete took, as it was, undoes a merge, or stands an import taken back again; answers whether it
   * came back.
   */
  async restore(companyId: string, removal: Pick<RecordRemoval, 'id'>): Promise<boolean> {
    try {
      await this.words();
      const restored = await this.proved(() => this.api.restore(companyId, removal.id));
      if (restored.kind === 'merge') {
        this.feedback.success('records.merge_undone', { kept: restored.kept.label });
        return true;
      }
      if (restored.kind === 'import') {
        this.feedback.success('records.import_restored');
        return true;
      }
      this.feedback.success(
        this.only(restored) === null ? 'records.restored_many' : 'records.restored_one',
        this.params(restored),
      );
      return true;
    } catch (error) {
      this.refused(error, true);
      return false;
    }
  }

  /**
   * Opens the words a delete, a merge and their undo are said in, before anything is asked of the API: the toast is
   * drawn from the root and outlives the page, and a screen whose route did not open them would say keys. Words that
   * cannot be fetched stop the action, which the network would refuse as well, rather than run it unsaid.
   */
  private words(): Promise<void> {
    return this.texts.open(RECORDS_TEXTS);
  }

  /**
   * Runs a call the API may hold back until the person proves who they are (a merge and its undo, a change of many
   * links at once), asking once; a delete is never held back.
   */
  private proved<T>(call: () => Promise<T>): Promise<T> {
    return this.stepUp.retrying(
      call,
      (error) => error instanceof RemovalRefused && error.code === 'step_up_required',
      'step_up.intro_change',
    );
  }

  /** The one record a delete took or a merge folded, named by the toast; several are counted instead. */
  private only(removal: RecordRemoval): RemovedItem | null {
    const [first] = removal.items;
    return removal.items.length === 1 && first !== undefined ? first : null;
  }

  private params(removal: RecordRemoval): Record<string, unknown> {
    const one = this.only(removal);
    return one === null ? { count: removal.items.length } : { name: one.label };
  }

  /** Says why the API refused; `undoing` when it refused to put back what a delete or a merge took. */
  private refused(error: unknown, undoing = false): void {
    const refusal =
      error instanceof RemovalRefused ? error : refusedFor(error, new RemovalRefused('network'));
    const column = MISMATCH_COLUMNS.find((known) => known === refusal.column);
    if (refusal.code === 'record_used' && refusal.records.length > 0) {
      this.feedback.failure('records.in_use', {
        records: this.list(refusal.records.map((record) => this.usedBy(record))),
      });
    } else if (refusal.code === 'merge_mismatch' && column !== undefined) {
      this.feedback.failure(`records.mismatch.${column}`);
    } else if (refusal.code === 'merge_transacted' && refusal.uses.length > 0) {
      this.feedback.failure('records.traded_since', { uses: this.counted(refusal.uses) });
    } else if (refusal.code === 'merge_alias' && undoing) {
      this.feedback.failure('records.errors.merge_alias_undo');
    } else if (
      refusal.code === 'merge_refused' &&
      MERGE_REFUSALS.some((known) => known === refusal.reason)
    ) {
      this.feedback.failure(`records.merge_refused.${refusal.reason}`, { ...refusal.params });
    } else {
      this.feedback.failure(`records.errors.${refusal.code}`);
    }
  }

  private usedBy(record: RecordInUse): string {
    return this.translate.instant('records.used_by', {
      name: record.label,
      uses: this.counted(record.uses),
    });
  }

  /** « 2 mouvements de stock et 1 commande fournisseur », a table the screens do not name counted as other records. */
  private counted(uses: readonly TableCount[]): string {
    return this.list(
      tableCounts(uses).map(({ key, count }) => this.translate.instant(key, { count })),
    );
  }

  /** « a, b et c » as the screen's language joins a list. */
  private list(parts: readonly string[]): string {
    const language =
      this.translate.getCurrentLang() ?? this.translate.getFallbackLang() ?? undefined;
    return new Intl.ListFormat(language, { type: 'conjunction' }).format(parts);
  }
}

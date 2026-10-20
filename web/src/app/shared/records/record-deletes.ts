// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { Feedback } from '../feedback/feedback';
import { LiveChanges } from '../realtime/live-changes';
import { StepUp } from '../step-up/step-up';
import {
  RecordRemovalsApi,
  RemovalRefused,
  type RecordInUse,
  type RecordRemoval,
  type RemovedItem,
} from './record-removals-api';
import type { RecordMergeDialog, RecordMergeDialogData } from './record-merge-dialog';

/** The subjects the API lets a person delete, each also the kind its live changes carry. */
export const DELETABLE_SUBJECTS = [
  'vendor',
  'product',
  'product_category',
  'expense_category',
] as const;

/** The tables a refusal says in words; any other is counted as « other records ». */
const NAMED_TABLES = [
  'product',
  'product_category',
  'expense_category',
  'vendor',
  'stock_movement',
  'purchase_order',
  'expense',
  'product_vendor',
  'product_barcode',
  'invoice_line',
  'quote_line',
  'delivery_note_line',
  'purchase_order_line',
  'stock_lot',
  'price_list_item',
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
  private readonly live = inject(LiveChanges);
  private readonly stepUp = inject(StepUp);
  private readonly dialog = inject(MatDialog);

  /** Deletes the records, all of them or none; answers whether they went. */
  async remove(
    companyId: string,
    subject: string,
    items: readonly RemovedItem[],
  ): Promise<boolean> {
    try {
      const removal = await this.api.remove(
        companyId,
        subject,
        items.map((item) => item.id),
      );
      this.live.changedHere([subject, 'record_removal'], `${subject}.deleted`);
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
      const merge = await this.proved(() =>
        this.api.merge(
          companyId,
          subject,
          kept.id,
          items.map((item) => item.id),
        ),
      );
      this.live.changedHere([subject, 'record_removal'], `${subject}.merged`);
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

  /** Puts back what a delete took, as it was, or undoes a merge; answers whether it came back. */
  async restore(companyId: string, removal: RecordRemoval): Promise<boolean> {
    try {
      const restored = await this.proved(() => this.api.restore(companyId, removal.id));
      if (restored.kind === 'merge') {
        this.live.changedHere(
          [restored.subject, 'record_removal'],
          `${restored.subject}.merge_undone`,
        );
        this.feedback.success('records.merge_undone', { kept: restored.kept.label });
        return true;
      }
      this.live.changedHere([restored.subject, 'record_removal'], `${restored.subject}.restored`);
      this.feedback.success(
        this.only(restored) === null ? 'records.restored_many' : 'records.restored_one',
        this.params(restored),
      );
      return true;
    } catch (error) {
      this.refused(error);
      return false;
    }
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

  private refused(error: unknown): void {
    const refusal = error instanceof RemovalRefused ? error : new RemovalRefused('network');
    if (refusal.code === 'record_used' && refusal.records.length > 0) {
      this.feedback.failure('records.in_use', {
        records: this.list(refusal.records.map((record) => this.usedBy(record))),
      });
    } else {
      this.feedback.failure(`records.errors.${refusal.code}`);
    }
  }

  private usedBy(record: RecordInUse): string {
    const counts = new Map<string, number>();
    for (const use of record.uses) {
      const table = NAMED_TABLES.find((named) => named === use.table) ?? 'other';
      counts.set(table, (counts.get(table) ?? 0) + use.count);
    }
    return this.translate.instant('records.used_by', {
      name: record.label,
      uses: this.list(
        [...counts].map(([table, count]) =>
          this.translate.instant(`records.tables.${table}`, { count }),
        ),
      ),
    });
  }

  /** « a, b et c » as the screen's language joins a list. */
  private list(parts: readonly string[]): string {
    const language =
      this.translate.getCurrentLang() ?? this.translate.getFallbackLang() ?? undefined;
    return new Intl.ListFormat(language, { type: 'conjunction' }).format(parts);
  }
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { Feedback } from '../shared/feedback/feedback';
import { RecordDeletes } from '../shared/records/record-deletes';
import { StepUp } from '../shared/step-up/step-up';
import { ImportApi, ImportNotKept, ImportRefused } from './import-api';
import type {
  ImportError,
  ImportGuide,
  ImportMode,
  ImportPairing,
  ImportRefusal,
  ImportReport,
  ImportRunSummary,
  ImportUndone,
  TextEncoding,
} from './import-types';
import { QuietReads } from '../shared/feedback/quiet-reads';

/**
 * What the import screen knows: the guide for the subject it is on, and the answer to the last run.
 *
 * A file that was not kept is not an error — it is the answer the person came for, so the refusal and the report are
 * held apart from `error`, which is for a request that never ran.
 */
@Injectable({ providedIn: 'root' })
export class ImportFacade {
  private readonly api = inject(ImportApi);
  private readonly quietReads = inject(QuietReads);
  private readonly feedback = inject(Feedback);
  private readonly stepUp = inject(StepUp);
  private readonly records = inject(RecordDeletes);

  private readonly guideSignal = signal<ImportGuide | null>(null);
  private readonly reportSignal = signal<ImportReport | null>(null);
  private readonly refusalSignal = signal<ImportRefusal | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ImportError | null>(null);
  private readonly recentSignal = signal<readonly ImportRunSummary[]>([]);
  private readonly undoneSignal = signal<ImportUndone | null>(null);

  readonly guide = this.guideSignal.asReadonly();
  readonly report = this.reportSignal.asReadonly();
  readonly refusal = this.refusalSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  /** « Imports récents »: empty until read, and when the person may write none of the subjects imported. */
  readonly recent = this.recentSignal.asReadonly();
  /** What the last « Annuler cet import » did, until another file is chosen. */
  readonly undone = this.undoneSignal.asReadonly();

  async load(companyId: string, subject: string): Promise<void> {
    this.forget();
    this.guideSignal.set(null);
    // A reload nobody asked for leaves busy as it is, so no action turns disabled under a pointer (QuietReads).
    const marked = !this.quietReads.quiet();
    if (marked) this.busySignal.set(true);
    try {
      this.guideSignal.set(await this.api.guide(companyId, subject));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      if (marked) this.busySignal.set(false);
    }
  }

  /** Previews the file, or imports it. Says so with a toast only when something was actually stored. */
  async run(
    companyId: string,
    subject: string,
    file: File,
    mode: ImportMode,
    dryRun: boolean,
    switches: readonly string[] = [],
    encoding: TextEncoding | null = null,
    pairing: readonly ImportPairing[] = [],
  ): Promise<void> {
    this.forget();
    this.busySignal.set(true);
    try {
      // A file changing a vendor's bank details waits for the person to prove who they are, then is sent again whole;
      // a preview never asks (docs/SPEC.md § 7).
      const report = await this.stepUp.retrying(
        () => this.api.run(companyId, subject, file, mode, dryRun, switches, encoding, pairing),
        (error) => error instanceof ImportRefused && error.code === 'step_up_required',
        'step_up.intro_sensitive',
      );
      this.reportSignal.set(report);
      this.errorSignal.set(null);
      if (report.committed) {
        this.feedback.success('import.stored', {
          created: report.created.length,
          updated: report.updated.length,
        });
        await this.loadRecent(companyId);
      }
    } catch (error) {
      if (error instanceof ImportNotKept) {
        this.reportSignal.set(error.report);
        this.refusalSignal.set(error.refusal);
        this.errorSignal.set(null);
      } else {
        this.errorSignal.set(codeOf(error));
      }
    } finally {
      this.busySignal.set(false);
    }
  }

  /** Reads « Imports récents » again; a list that cannot be read stays as it was, the page still working. */
  async loadRecent(companyId: string): Promise<void> {
    try {
      this.recentSignal.set(await this.api.recent(companyId));
    } catch {
      // The list is a convenience beside the import: the next import or undo reads it again.
    }
  }

  /**
   * « Annuler cet import », once who is at the screen is proved. What went to « Récemment supprimés » is put back whole
   * from the toast; an undo that sent nothing there (an opening stock) is corrected by importing the file again.
   * Answers whether the import was taken back.
   */
  async undo(companyId: string, runId: string): Promise<boolean> {
    this.busySignal.set(true);
    try {
      const undone = await this.stepUp.retrying(
        () => this.api.undo(companyId, runId),
        (error) => error instanceof ImportRefused && error.code === 'step_up_required',
        'step_up.intro_change',
      );
      this.reportSignal.set(null);
      this.refusalSignal.set(null);
      this.undoneSignal.set(undone);
      this.errorSignal.set(null);
      const removal = undone.removal;
      if (removal === null) {
        this.feedback.effect('import.undo.done', {}, 'corrigeable');
      } else {
        this.feedback.effect('import.undo.done', {}, 'annulable', {
          key: 'records.undo',
          run: () =>
            void this.records
              .restore(companyId, { id: removal })
              .then((back) => (back ? this.restored(companyId) : undefined)),
        });
      }
      await this.loadRecent(companyId);
      return true;
    } catch (error) {
      this.feedback.failure(`import.errors.${codeOf(error)}`);
      await this.loadRecent(companyId);
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }

  /** Drops the last answer, so a new file is never read beside the one before it. */
  forget(): void {
    this.reportSignal.set(null);
    this.refusalSignal.set(null);
    this.undoneSignal.set(null);
  }

  /** The import stands again: what its undo said no longer holds. */
  private async restored(companyId: string): Promise<void> {
    this.undoneSignal.set(null);
    await this.loadRecent(companyId);
  }
}

function codeOf(error: unknown): ImportError {
  return error instanceof ImportRefused ? error.code : 'network';
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { Feedback } from '../shared/feedback/feedback';
import { ImportApi, ImportNotKept, ImportRefused } from './import-api';
import type {
  ImportError,
  ImportGuide,
  ImportMode,
  ImportRefusal,
  ImportReport,
} from './import-types';

/**
 * What the import screen knows: the guide for the subject it is on, and the answer to the last run.
 *
 * A file that was not kept is not an error — it is the answer the person came for, so the refusal and the report are
 * held apart from `error`, which is for a request that never ran.
 */
@Injectable({ providedIn: 'root' })
export class ImportFacade {
  private readonly api = inject(ImportApi);
  private readonly feedback = inject(Feedback);

  private readonly guideSignal = signal<ImportGuide | null>(null);
  private readonly reportSignal = signal<ImportReport | null>(null);
  private readonly refusalSignal = signal<ImportRefusal | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ImportError | null>(null);

  readonly guide = this.guideSignal.asReadonly();
  readonly report = this.reportSignal.asReadonly();
  readonly refusal = this.refusalSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string, subject: string): Promise<void> {
    this.forget();
    this.guideSignal.set(null);
    this.busySignal.set(true);
    try {
      this.guideSignal.set(await this.api.guide(companyId, subject));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /** Previews the file, or imports it. Says so with a toast only when something was actually stored. */
  async run(
    companyId: string,
    subject: string,
    file: File,
    mode: ImportMode,
    dryRun: boolean,
  ): Promise<void> {
    this.forget();
    this.busySignal.set(true);
    try {
      const report = await this.api.run(companyId, subject, file, mode, dryRun);
      this.reportSignal.set(report);
      this.errorSignal.set(null);
      if (report.committed) {
        this.feedback.success('import.stored', {
          created: report.created.length,
          updated: report.updated.length,
        });
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

  /** Drops the last answer, so a new file is never read beside the one before it. */
  forget(): void {
    this.reportSignal.set(null);
    this.refusalSignal.set(null);
  }
}

function codeOf(error: unknown): ImportError {
  return error instanceof ImportRefused ? error.code : 'network';
}

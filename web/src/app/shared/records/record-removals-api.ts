// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

/** One record a delete took, named as it read when it went: the only thing « Récemment supprimés » shows of it. */
export interface RemovedItem {
  readonly id: string;
  readonly label: string;
}

/**
 * Records of one subject deleted, or merged into another, at once, restorable together until `effectiveAt`: a merge's
 * undo also points back at them whatever it moved.
 */
export type RecordRemoval = RemovalCommon &
  (
    | { readonly kind: 'delete'; readonly kept: null }
    /** `kept` is the record the others were merged into. */
    | { readonly kind: 'merge'; readonly kept: RemovedItem }
  );

interface RemovalCommon {
  readonly id: string;
  /** The subject's table, `vendor` first. */
  readonly subject: string;
  readonly items: readonly RemovedItem[];
  /** ISO moments. */
  readonly removedAt: string;
  readonly effectiveAt: string;
  readonly state: 'pending' | 'restored' | 'final';
}

/** What still names a record, by the table and column that names it. */
export interface RecordUse {
  readonly table: string;
  readonly column: string;
  readonly count: number;
}

export interface RecordInUse {
  readonly id: string;
  readonly label: string;
  readonly uses: readonly RecordUse[];
}

/** Rows of one table, counted. */
export interface TableCount {
  readonly table: string;
  readonly count: number;
}

/**
 * What a merge would do to one record, said before it runs: what names it and moves to the record kept, and what names
 * it and stays, as the books and the stock recorded it. Counts only.
 */
export interface MergePreview extends RemovedItem {
  readonly moves: readonly TableCount[];
  readonly stays: readonly TableCount[];
}

/** Why the API refused, as the screens translate it. */
export type RemovalError =
  | 'network'
  | 'not_found'
  | 'no_record'
  | 'record_used'
  | 'restore_conflict'
  | 'removal_final'
  | 'merge_into_itself'
  | 'merge_cycle'
  | 'merge_conflict'
  | 'merge_alias'
  | 'merge_mismatch'
  | 'merge_transacted'
  | 'step_up_required';

/**
 * Thrown when the API refuses. A record in use carries what names each record; a mismatch, the column the records
 * disagree on; an undo refused, what names the record kept since the merge.
 */
export class RemovalRefused extends Error {
  constructor(
    readonly code: RemovalError,
    readonly records: readonly RecordInUse[] = [],
    readonly column: string | null = null,
    readonly uses: readonly TableCount[] = [],
  ) {
    super(code);
  }
}

/**
 * The HTTP edge of deleting records and « Récemment supprimés », shared by every subject that can be deleted. Its
 * endpoints are plain controllers, outside the OpenAPI document, so the shapes are declared here.
 */
@Injectable({ providedIn: 'root' })
export class RecordRemovalsApi {
  private readonly http = inject(HttpClient);

  /** Deletes the records, all of them or none. */
  async remove(companyId: string, subject: string, ids: readonly string[]): Promise<RecordRemoval> {
    return this.call(() =>
      this.http.post<RecordRemoval>(`${base(companyId)}/record-removals`, { subject, ids }),
    );
  }

  /**
   * Merges the records into the one kept, all of them or none. The API holds it back as `step_up_required` until the
   * person proved who they are in the last few minutes.
   */
  async merge(
    companyId: string,
    subject: string,
    keptId: string,
    ids: readonly string[],
  ): Promise<RecordRemoval> {
    return this.call(() =>
      this.http.post<RecordRemoval>(`${base(companyId)}/record-merges`, { subject, keptId, ids }),
    );
  }

  /** What merging these records would move and leave, record by record, asked before the person picks the one kept. */
  async previewMerge(
    companyId: string,
    subject: string,
    ids: readonly string[],
  ): Promise<MergePreview[]> {
    let params = new HttpParams().set('subject', subject);
    for (const id of ids) params = params.append('ids[]', id);
    return this.call(() =>
      this.http.get<MergePreview[]>(`${base(companyId)}/record-merges/preview`, { params }),
    );
  }

  /** The deletes still restorable, of the subjects the person may write, the newest first. */
  async pending(companyId: string): Promise<RecordRemoval[]> {
    return this.call(() => this.http.get<RecordRemoval[]>(`${base(companyId)}/record-removals`));
  }

  /** Puts a delete back, or undoes a merge, which also takes a recent proof. */
  async restore(companyId: string, removalId: string): Promise<RecordRemoval> {
    return this.call(() =>
      this.http.post<RecordRemoval>(
        `${base(companyId)}/record-removals/${encodeURIComponent(removalId)}/restore`,
        null,
      ),
    );
  }

  private async call<T>(request: () => ReturnType<HttpClient['get']>): Promise<T> {
    try {
      return (await firstValueFrom(request())) as T;
    } catch (error) {
      throw refusal(error);
    }
  }
}

const base = (companyId: string): string => `/api/companies/${encodeURIComponent(companyId)}`;

const CODES: readonly RemovalError[] = [
  'no_record',
  'record_used',
  'restore_conflict',
  'removal_final',
  'merge_into_itself',
  'merge_cycle',
  'merge_conflict',
  'merge_alias',
  'merge_mismatch',
  'merge_transacted',
  'step_up_required',
];

function refusal(error: unknown): RemovalRefused {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return new RemovalRefused('network');
  }
  if (error.status === 404) return new RemovalRefused('not_found');
  const body: unknown = error.error;
  const fields = typeof body === 'object' && body !== null ? (body as Record<string, unknown>) : {};
  const known = CODES.find((candidate) => candidate === fields['error']);
  const records = Array.isArray(fields['records']) ? (fields['records'] as RecordInUse[]) : [];
  const column = typeof fields['column'] === 'string' ? fields['column'] : null;
  const uses = Array.isArray(fields['uses']) ? (fields['uses'] as TableCount[]) : [];
  return new RemovalRefused(
    known ?? 'network',
    known === 'record_used' ? records : [],
    known === 'merge_mismatch' ? column : null,
    known === 'merge_transacted' ? uses : [],
  );
}

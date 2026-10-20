// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

/** One record a delete took, named as it read when it went: the only thing « Récemment supprimés » shows of it. */
export interface RemovedItem {
  readonly id: string;
  readonly label: string;
}

/** Records of one subject deleted at once, restorable together until `effectiveAt`. */
export interface RecordRemoval {
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

/** Why the API refused, as the screens translate it. */
export type RemovalError =
  'network' | 'not_found' | 'no_record' | 'record_used' | 'restore_conflict' | 'removal_final';

/** Thrown when the API refuses; a record in use carries what names each record. */
export class RemovalRefused extends Error {
  constructor(
    readonly code: RemovalError,
    readonly records: readonly RecordInUse[] = [],
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

  /** The deletes still restorable, of the subjects the person may write, the newest first. */
  async pending(companyId: string): Promise<RecordRemoval[]> {
    return this.call(() => this.http.get<RecordRemoval[]>(`${base(companyId)}/record-removals`));
  }

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
  return new RemovalRefused(known ?? 'network', known === 'record_used' ? records : []);
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ImportGuideImportGuideRead,
  ImportRunsResponse,
  UndoImportResponse,
} from '../api/types.gen';
import { stepUpAsked } from '../shared/step-up/step-up-api';
import {
  TEXT_ENCODINGS,
  type ImportError,
  type ImportGuide,
  type ImportMode,
  type ImportPairing,
  type ImportRefusal,
  type ImportReport,
  type ImportRunSummary,
  type ImportUndone,
  type TextEncoding,
} from './import-types';
import { refusedFor } from '../shared/errors/said-already';

/** Thrown when the API refuses the request itself; carries the code the screen translates. */
export class ImportRefused extends Error {
  constructor(readonly code: ImportError) {
    super(code);
  }
}

/**
 * Thrown when the API read the request but would not keep the file: either the file refused WHOLE (`refusal`), or the
 * rows ran and some were rejected (`report`), in which case nothing was stored. Both arrive as 422 and both are what
 * the person must act on, so neither is an error code — they are the answer.
 */
export class ImportNotKept extends Error {
  constructor(
    readonly report: ImportReport | null,
    readonly refusal: ImportRefusal | null,
  ) {
    super('import_not_kept');
  }
}

/** The HTTP edge of the import screen: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class ImportApi {
  private readonly http = inject(HttpClient);

  /** What this company's file for this subject holds. 404 for a subject it cannot import, or may not write. */
  async guide(companyId: string, subject: string): Promise<ImportGuide> {
    return this.guard(async () =>
      toGuide(
        await firstValueFrom(
          this.http.get<ImportGuideImportGuideRead>(path(companyId, 'imports', subject)),
        ),
      ),
    );
  }

  /**
   * Runs the file, or previews it. A preview is the same run rolled back, so what it reports is exactly what an
   * import would do, rejections included.
   *
   * @throws ImportNotKept when the file was refused whole, or a row was rejected and the run was rolled back
   */
  async run(
    companyId: string,
    subject: string,
    file: File,
    mode: ImportMode,
    dryRun: boolean,
    switches: readonly string[] = [],
    encoding: TextEncoding | null = null,
    pairing: readonly ImportPairing[] = [],
    choices: Readonly<Record<string, string>> = {},
  ): Promise<ImportReport> {
    const body = new FormData();
    body.append('file', file, file.name);
    body.append('mode', mode);
    body.append('dryRun', dryRun ? '1' : '0');
    for (const key of switches) body.append('switches[]', key);
    // A choice not sent is its first answer.
    for (const [key, answer] of Object.entries(choices)) body.append(`choices[${key}]`, answer);
    // None chosen: the API finds it on the file.
    if (encoding !== null) body.append('encoding', encoding);
    // One object of headings to columns, which the API reads whole; none sent, each heading names its column itself.
    if (pairing.length > 0) {
      body.append(
        'pairing',
        JSON.stringify(Object.fromEntries(pairing.map((pair) => [pair.heading, pair.column]))),
      );
    }

    return this.guard(async () =>
      toReport(
        await firstValueFrom(this.http.post<unknown>(path(companyId, 'imports', subject), body)),
      ),
    );
  }

  /** « Imports récents »: the imports of the last day into the subjects the person may write, the latest first. */
  async recent(companyId: string): Promise<ImportRunSummary[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ImportRunsResponse>(`${companyPath(companyId)}/import-runs`),
        )
      ).map((run) => ({
        id: run.id,
        subject: run.subject,
        at: run.at,
        by: run.by,
        created: run.created,
        updated: run.updated,
        undoableUntil: run.undoableUntil,
        undoneAt: run.undoneAt,
      })),
    );
  }

  /**
   * « Annuler cet import ». The API holds it back as `step_up_required` until the person proved who they are in the
   * last few minutes, and refuses one taken back already (`import_undone`) or past its day (`import_final`).
   */
  async undo(companyId: string, runId: string): Promise<ImportUndone> {
    return this.guard(async () => {
      const answer = await firstValueFrom(
        this.http.post<UndoImportResponse>(
          `${companyPath(companyId)}/import-runs/${encodeURIComponent(runId)}/undo`,
          null,
        ),
      );
      return {
        removal: answer.removal,
        removed: answer.removed.map((item) => ({ id: item.id, label: item.label })),
        kept: answer.kept.map((item) => ({ id: item.id, label: item.label, uses: item.uses })),
        rowsKept: answer.rowsKept.map((row) => ({ label: row.label, reason: row.reason })),
        stockTakenBack: answer.stockTakenBack.map((stock) => ({
          reference: stock.reference,
          location: stock.location,
          quantity: stock.quantity,
        })),
        stockKept: answer.stockKept.map((stock) => ({
          reference: stock.reference,
          location: stock.location,
          reason: stock.reason,
        })),
        updated: answer.updated,
      };
    });
  }

  /** Where the browser opens the empty file to fill in: a same-origin address the session cookie reaches. */
  templateUrl(companyId: string, subject: string, format: 'csv' | 'xlsx'): string {
    return `${path(companyId, 'import-templates', subject)}.${format}`;
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw refusalOf(error);
    }
  }
}

function refusalOf(error: unknown): Error {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return refusedFor(error, new ImportRefused('network'));
  }
  // A file setting or changing what asks who is at the screen (a vendor's bank details) waits for a recent proof, and
  // kept nothing (docs/SPEC.md § 7).
  if (stepUpAsked(error)) return refusedFor(error, new ImportRefused('step_up_required'));
  switch (error.status) {
    case 404:
      return refusedFor(error, new ImportRefused('not_found'));
    case 409:
      return conflictOf(error);
    case 413:
      return refusedFor(error, new ImportRefused('file_too_large'));
    case 422:
      return notKept(error);
    default:
      return refusedFor(error, new ImportRefused('invalid'));
  }
}

/** An undo refused names why: taken back already, or past its day. */
function conflictOf(error: HttpErrorResponse): ImportRefused {
  const reason = (error.error as Record<string, unknown> | null)?.['error'];
  return refusedFor(
    error,
    new ImportRefused(reason === 'import_undone' || reason === 'import_final' ? reason : 'invalid'),
  );
}

/** A 422 carries either the report of a run that was rolled back, or the reason the file was refused whole. */
function notKept(error: HttpErrorResponse): Error {
  const answer = error.error as Record<string, unknown> | null;
  if (answer !== null && typeof answer === 'object' && 'rejected' in answer) {
    return new ImportNotKept(toReport(answer), null);
  }
  const reason = answer?.['error'];
  if (typeof reason !== 'string') {
    return refusedFor(error, new ImportRefused('invalid'));
  }

  return new ImportNotKept(null, {
    reason: reason as ImportRefusal['reason'],
    columns: Array.isArray(answer?.['columns']) ? (answer['columns'] as string[]) : [],
    limit: typeof answer?.['limit'] === 'number' ? answer['limit'] : null,
    encoding: encodingOf(answer?.['encoding']),
  });
}

function encodingOf(value: unknown): TextEncoding | null {
  return TEXT_ENCODINGS.find((encoding) => encoding === value) ?? null;
}

function toGuide(answer: ImportGuideImportGuideRead): ImportGuide {
  return {
    subject: answer.subject,
    identity: answer.identity,
    maxRows: answer.maxRows,
    columns: answer.columns.map((column) => ({
      key: column.key,
      required: column.required,
      headingKey: column.headingKey,
      label: column.label,
      example: column.example,
      noteKey: column.noteKey,
    })),
    switches: answer.switches.map((choice) => ({
      key: choice.key,
      labelKey: choice.labelKey,
      noteKey: choice.noteKey,
      choices: choice.choices.map((answer) => ({ key: answer.key, labelKey: answer.labelKey })),
    })),
    lastPairing: answer.lastPairing.map((pair) => ({ heading: pair.heading, column: pair.column })),
  };
}

function toReport(answer: unknown): ImportReport {
  const body = answer as Record<string, unknown>;
  const lines = (value: unknown): number[] =>
    Array.isArray(value) ? value.filter((line): line is number => typeof line === 'number') : [];

  return {
    committed: body['committed'] === true,
    run: typeof body['run'] === 'string' ? body['run'] : null,
    created: lines(body['created']),
    updated: lines(body['updated']),
    unchanged: lines(body['unchanged']),
    rejected: Array.isArray(body['rejected'])
      ? body['rejected'].map((row: Record<string, unknown>) => ({
          line: typeof row['line'] === 'number' ? row['line'] : 0,
          column: typeof row['column'] === 'string' ? row['column'] : null,
          code: typeof row['code'] === 'string' ? row['code'] : 'invalid_value',
          params: (row['params'] ?? {}) as Record<string, string | number>,
          message: typeof row['message'] === 'string' ? row['message'] : '',
        }))
      : [],
    changes: Array.isArray(body['changes'])
      ? body['changes'].map((row: Record<string, unknown>) => ({
          line: typeof row['line'] === 'number' ? row['line'] : 0,
          column: typeof row['column'] === 'string' ? row['column'] : '',
          before: typeof row['before'] === 'string' ? row['before'] : '',
          after: typeof row['after'] === 'string' ? row['after'] : '',
        }))
      : [],
    notes: Array.isArray(body['notes'])
      ? body['notes'].map((row: Record<string, unknown>) => ({
          line: typeof row['line'] === 'number' ? row['line'] : 0,
          column: typeof row['column'] === 'string' ? row['column'] : null,
          code: typeof row['code'] === 'string' ? row['code'] : '',
          params: (row['params'] ?? {}) as Record<string, string | number>,
        }))
      : [],
    alreadyImportedAt:
      typeof body['alreadyImportedAt'] === 'string' ? body['alreadyImportedAt'] : null,
    encoding: encodingOf(body['encoding']),
  };
}

const companyPath = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}`;

const path = (companyId: string, collection: string, subject: string): string =>
  `${companyPath(companyId)}/${collection}/${encodeURIComponent(subject)}`;

// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { ImportGuideImportGuideRead } from '../api/types.gen';
import type {
  ImportError,
  ImportGuide,
  ImportMode,
  ImportRefusal,
  ImportReport,
} from './import-types';

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
  ): Promise<ImportReport> {
    const body = new FormData();
    body.append('file', file, file.name);
    body.append('mode', mode);
    body.append('dryRun', dryRun ? '1' : '0');

    return this.guard(async () =>
      toReport(
        await firstValueFrom(this.http.post<unknown>(path(companyId, 'imports', subject), body)),
      ),
    );
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
    return new ImportRefused('network');
  }
  switch (error.status) {
    case 404:
      return new ImportRefused('not_found');
    case 413:
      return new ImportRefused('file_too_large');
    case 422:
      return notKept(error.error);
    default:
      return new ImportRefused('invalid');
  }
}

/** A 422 carries either the report of a run that was rolled back, or the reason the file was refused whole. */
function notKept(body: unknown): Error {
  const answer = body as Record<string, unknown> | null;
  if (answer !== null && typeof answer === 'object' && 'rejected' in answer) {
    return new ImportNotKept(toReport(answer), null);
  }
  const reason = answer?.['error'];
  if (typeof reason !== 'string') {
    return new ImportRefused('invalid');
  }

  return new ImportNotKept(null, {
    reason: reason as ImportRefusal['reason'],
    columns: Array.isArray(answer?.['columns']) ? (answer['columns'] as string[]) : [],
    limit: typeof answer?.['limit'] === 'number' ? answer['limit'] : null,
  });
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
  };
}

function toReport(answer: unknown): ImportReport {
  const body = answer as Record<string, unknown>;
  const lines = (value: unknown): number[] =>
    Array.isArray(value) ? value.filter((line): line is number => typeof line === 'number') : [];

  return {
    committed: body['committed'] === true,
    created: lines(body['created']),
    updated: lines(body['updated']),
    rejected: Array.isArray(body['rejected'])
      ? body['rejected'].map((row: Record<string, unknown>) => ({
          line: typeof row['line'] === 'number' ? row['line'] : 0,
          column: typeof row['column'] === 'string' ? row['column'] : null,
          code: typeof row['code'] === 'string' ? row['code'] : 'invalid_value',
          params: (row['params'] ?? {}) as Record<string, string | number>,
          message: typeof row['message'] === 'string' ? row['message'] : '',
        }))
      : [],
  };
}

const path = (companyId: string, collection: string, subject: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/${collection}/${encodeURIComponent(subject)}`;

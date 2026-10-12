// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, ErrorHandler, inject, Injectable, InjectionToken } from '@angular/core';
import { BuildInfo } from '../build/build-info';

/** What the API is told of an error in the browser: what broke, where and in which build. */
export interface ClientErrorReport {
  message: string;
  stack: string;
  page: string;
  build: string | null;
}

/** A page that breaks in a loop says so a few times, not once per turn of the loop. */
export const MAX_REPORTS_PER_PAGE = 5;

/** Sends one report. The sender loads only when a first error happens, so the first page carries none of it. */
export const REPORT_CLIENT_ERROR = new InjectionToken<(report: ClientErrorReport) => Promise<void>>(
  'REPORT_CLIENT_ERROR',
  {
    providedIn: 'root',
    factory: () => async (report) => (await import('./send-client-error')).sendClientError(report),
  },
);

/**
 * Angular's own handler, which writes the error to the console, plus a report to the API (docs/START.md § 15), which
 * carries it on to the error tracker scrubbed. An answer from the API is left out: the API logged it already.
 */
@Injectable()
export class ReportingErrorHandler extends ErrorHandler {
  private readonly report = inject(REPORT_CLIENT_ERROR);
  private readonly document = inject(DOCUMENT);
  private readonly build = inject(BuildInfo);
  private sent = 0;

  override handleError(error: unknown): void {
    super.handleError(error);
    if (isApiAnswer(error) || this.sent >= MAX_REPORTS_PER_PAGE) return;
    this.sent++;
    // A report that cannot be sent is let go: raising it would only be one more error to report.
    this.report({
      message: error instanceof Error ? `${error.name}: ${error.message}` : String(error),
      stack: error instanceof Error ? (error.stack ?? '') : '',
      page: this.document.location.pathname,
      build: this.build.web()?.version ?? null,
    }).catch(() => undefined);
  }
}

/** Angular's HttpErrorResponse, known by the name it carries: only an API adapter may import Angular's HTTP layer. */
function isApiAnswer(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    (error as { name?: unknown }).name === 'HttpErrorResponse'
  );
}

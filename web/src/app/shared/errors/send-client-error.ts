// SPDX-License-Identifier: AGPL-3.0-or-later

import { CSRF_HEADER, csrfToken } from '../session/csrf-token';
import type { ClientErrorReport } from './reporting-error-handler';

/** What the API keeps of a report (`app.client_errors.*`, told by `/api/health`). */
export interface ClientErrorBounds {
  /** The body's size in bytes, past which the API refuses the report unread. */
  bytes: number;
  /** The characters of the message it keeps. */
  messageLength: number;
  /** The characters of the stack it keeps. */
  stackLength: number;
}

/**
 * Posts one report to the API, which carries it on to the error tracker. Plain fetch rather than HttpClient: a report
 * is no request of the person's, so it shows no activity bar and passes none of the interceptors that could fail in
 * turn. Kept alive, so a report of an error just before leaving the page still arrives.
 */
export async function sendClientError(
  report: ClientErrorReport,
  bounds: ClientErrorBounds,
): Promise<void> {
  await fetch('/api/client-errors', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', [CSRF_HEADER]: csrfToken() },
    body: JSON.stringify(fitted(report, bounds)),
    keepalive: true,
  });
}

/**
 * The report cut to what the API keeps: a body past its size is refused whole and the error lost, so the stack gives way
 * first, then the message, each kept as long as the body still fits (a character escaped in JSON takes up to six bytes).
 */
export function fitted(report: ClientErrorReport, bounds: ClientErrorBounds): ClientErrorReport {
  const message = Array.from(report.message).slice(0, bounds.messageLength);
  const stack = Array.from(report.stack).slice(0, bounds.stackLength);
  const encoder = new TextEncoder();
  const fits = (kept: number, ofStack: boolean): boolean =>
    encoder.encode(
      JSON.stringify({
        ...report,
        message: (ofStack ? message : message.slice(0, kept)).join(''),
        stack: (ofStack ? stack.slice(0, kept) : []).join(''),
      }),
    ).length <= bounds.bytes;
  const stackKept = longest(stack.length, (kept) => fits(kept, true));
  const messageKept =
    stackKept > 0 || fits(0, true)
      ? message.length
      : longest(message.length, (kept) => fits(kept, false));
  return {
    ...report,
    message: message.slice(0, messageKept).join(''),
    stack: stack.slice(0, stackKept).join(''),
  };
}

/** The largest count from 0 to `most` that `fits`, which holds for every count below one that holds. */
function longest(most: number, fits: (kept: number) => boolean): number {
  let low = 0;
  let high = most;
  while (low < high) {
    const middle = Math.ceil((low + high) / 2);
    if (fits(middle)) low = middle;
    else high = middle - 1;
  }
  return low;
}

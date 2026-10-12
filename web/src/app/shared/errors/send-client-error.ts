// SPDX-License-Identifier: AGPL-3.0-or-later

import { CSRF_HEADER, csrfToken } from '../session/csrf-token';
import type { ClientErrorReport } from './reporting-error-handler';

/**
 * Posts one report to the API, which carries it on to the error tracker. Plain fetch rather than HttpClient: a report
 * is no request of the person's, so it shows no activity bar and passes none of the interceptors that could fail in
 * turn. Kept alive, so a report of an error just before leaving the page still arrives.
 */
export async function sendClientError(report: ClientErrorReport): Promise<void> {
  await fetch('/api/client-errors', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', [CSRF_HEADER]: csrfToken() },
    body: JSON.stringify(report),
    keepalive: true,
  });
}

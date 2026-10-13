// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';

/**
 * The step-up's HTTP edge: whether the API held a change back until the person proves who they are again (docs/SPEC.md
 * § 7, the step-up list).
 * It says so in one of two shapes: API Platform leads a 403's `detail` with the token, a plain controller or the
 * platform's wall answers it as the `error` code. A file download's refusal arrives as a Blob, which `export-api` reads
 * for itself.
 */
export function stepUpAsked(error: unknown): boolean {
  if (!(error instanceof HttpErrorResponse) || error.status !== 403) return false;
  const body: unknown = error.error;
  if (typeof body !== 'object' || body === null) return false;
  const { detail, error: code } = body as { detail?: unknown; error?: unknown };
  return (
    code === 'step_up_required' ||
    (typeof detail === 'string' && detail.startsWith('step_up_required:'))
  );
}

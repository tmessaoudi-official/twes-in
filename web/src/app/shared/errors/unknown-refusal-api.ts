// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { refusalsIn } from '../form/field-refusals';
import type { UnknownRefusal } from './unknown-refusal';

/**
 * No answer at all, or a gateway answering for an API that is restarting: a lost connection, never a refusal, which
 * every reader of an unknown answer and the phone's pairing read alike.
 */
const UNREACHABLE: ReadonlySet<number> = new Set([0, 502, 503, 504]);

export function isUnreachable(error: unknown): boolean {
  return !(error instanceof HttpErrorResponse) || UNREACHABLE.has(error.status);
}

/** What an answer named, for a reader that does not know it: its `{error}`, else its first refusal's reason, else its status. */
export function unknownRefusal(error: HttpErrorResponse): UnknownRefusal {
  const body: unknown = error.error;
  const code =
    typeof body === 'object' && body !== null ? (body as Record<string, unknown>)['error'] : null;
  if (typeof code === 'string' && code !== '') return { unknown: code };
  return { unknown: refusalsIn(body)[0]?.reason ?? String(error.status) };
}

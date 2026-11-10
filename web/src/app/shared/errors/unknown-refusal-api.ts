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

/** What was sent, refused: the statuses a reader's own word for a refused value stands for. */
const SENT_REFUSED: ReadonlySet<number> = new Set([400, 422]);

/**
 * A status the reader names no case for, after its own: a value it sent refused is said in the reader's word
 * (`invalid`, or the `refused` of a flow that tells nothing more), any other answer by the code it named, never as
 * that word, which would tell a person to correct what they typed for a refusal of another kind.
 */
export function unnamedRefusal<W extends string = 'invalid'>(
  error: HttpErrorResponse,
  word: W = 'invalid' as W,
): W | UnknownRefusal {
  return SENT_REFUSED.has(error.status) ? word : unknownRefusal(error);
}

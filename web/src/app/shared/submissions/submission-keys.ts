// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';

/**
 * The header naming one attempt at a write (docs/SPEC.md § 3 « One submission, one write »). The API makes a write that
 * adds (a payment, goods taken in, a file's rows) once per key and answers a key sent again with what it answered first.
 */
export const SUBMISSION_KEY_HEADER = 'Idempotency-Key';

/** The same key while the write it names is still being made on the API. */
export const SUBMISSION_IN_PROGRESS = '/problems/submission-in-progress';

/** The same key for a write the API made but whose answer was lost: it is not made again, and is read again instead. */
export const SUBMISSION_UNANSWERED = '/problems/submission-unanswered';

/**
 * How long a write whose answer never came keeps its key for being sent again. A person tries again within moments of
 * a lost answer; past this, the same values sent again are a new write. The API keeps a key far longer (a day).
 */
export const SUBMISSION_KEY_KEPT_MS = 15 * 60 * 1000;

/**
 * The key of each write still waiting for a definite answer, by what it sends. The same write sent again after a lost
 * answer, a dropped connection or a server error carries the same key, so the API answers it as the first instead of
 * making it twice; once the API has answered (made, or refused for a reason that stands), the next sending is a new
 * attempt with a key of its own. One tab's memory: another tab's sending is another person's attempt.
 */
@Injectable({ providedIn: 'root' })
export class SubmissionKeys {
  private readonly waiting = new Map<string, { readonly key: string; readonly since: number }>();

  /** The key for this write: the one it was sent with before, while that sending is unanswered and recent. */
  keyFor(write: string, now: number = Date.now()): string {
    for (const [sent, held] of this.waiting) {
      if (now - held.since > SUBMISSION_KEY_KEPT_MS) this.waiting.delete(sent);
    }
    const held = this.waiting.get(write);
    if (held !== undefined) return held.key;
    const key = crypto.randomUUID();
    this.waiting.set(write, { key, since: now });
    return key;
  }

  /** The API answered this write for good: sending the same again is a new write. */
  answered(write: string): void {
    this.waiting.delete(write);
  }
}

/**
 * What a write sends, as one string: its address and its body. A file is read by its name, size and last change,
 * which stay the same when the person picks the same file again; a body of any other kind, a bare Blob included, is
 * never the same twice.
 */
export function writeOf(url: string, body: unknown): string {
  return `${url}\n${bodyOf(body)}`;
}

function bodyOf(body: unknown): string {
  if (body === null || body === undefined) return '';
  if (typeof body === 'string') return body;
  if (body instanceof FormData) {
    return JSON.stringify(
      [...body.entries()].map(([name, value]) => [
        name,
        typeof value === 'string' ? value : fileOf(value),
      ]),
    );
  }
  if (body instanceof File) return fileOf(body);
  if (
    typeof body === 'object' &&
    (Array.isArray(body) || Object.getPrototypeOf(body) === Object.prototype)
  ) {
    return JSON.stringify(body);
  }
  return crypto.randomUUID();
}

function fileOf(value: File | Blob): string {
  return value instanceof File
    ? `file:${value.name}:${value.size}:${value.lastModified}`
    : crypto.randomUUID();
}

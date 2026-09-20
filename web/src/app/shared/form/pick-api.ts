// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpParams } from '@angular/common/http';

/**
 * The HTTP edge of a picker's contract — named for the `*-api.ts` family because that is the only place HTTP may be
 * spoken here (eslint.config.js), and the picker's query is the same on every consumer.
 *
 * What a picker asks its API for: words to search by, or the ids of the records a document already names
 * (docs/SPEC.md § 7, 2026-09-17, ruling 3). The two are exclusive — given ids, the API ignores the words — and that
 * is why this is a union rather than two optional fields nobody could tell apart.
 */
export type PickAsked = { words: string } | { ids: readonly string[] };

/** The query a picker sends. Asked on no words at all, it sends nothing, so the API answers its own first few. */
export function pickParams(asked: PickAsked): HttpParams {
  if ('ids' in asked) {
    let params = new HttpParams();
    for (const id of asked.ids) params = params.append('ids[]', id);
    return params;
  }
  const words = asked.words.trim();
  return words === '' ? new HttpParams() : new HttpParams().set('q', words);
}

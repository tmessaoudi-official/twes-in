// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import type { Injector } from '@angular/core';
import { type FieldRefusal, refusalsIn } from './field-refusals';

/**
 * The texts a form's refusal is said in beyond the screens' own file (`import.rejections.*` only a setup form answers:
 * an establishment's code, a numbering format, a price list's prices), kept apart so the first page does not carry them.
 */
export const FORM_REFUSAL_TEXTS = 'form-refusals';

/**
 * The fields a save was refused on, read from a 422's body only, with their texts opened into the root before the form
 * says them. Texts that could not be fetched (the network gone between the answer and the fetch) neither hide the
 * refusal nor fail the save: a field whose reason has no words yet says « Valeur refusée. », and the next refusal
 * fetches them again (`TextsParts` keeps no failed fetch).
 */
export async function formRefusalsIn(
  injector: Injector,
  error: unknown,
): Promise<readonly FieldRefusal[]> {
  const refusals =
    error instanceof HttpErrorResponse && error.status === 422 ? refusalsIn(error.error) : [];
  if (refusals.length > 0) {
    const { TextsParts } = await import('../i18n/texts-part');
    await injector
      .get(TextsParts)
      .open(FORM_REFUSAL_TEXTS)
      .catch(() => undefined);
  }
  return refusals;
}

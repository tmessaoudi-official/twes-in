// SPDX-License-Identifier: AGPL-3.0-or-later

import type { TranslateService } from '@ngx-translate/core';
import type { Feedback } from '../feedback/feedback';
import type { FieldRefusal } from '../form/field-refusals';
import type { RefusalFigures } from '../form/refusal-figures';
import { refusedError } from '../form/refusal-words';

/**
 * The « Annuler » of a « Qui a reçu » correction has no form to hold a refusal: the contact named before may be gone, or
 * « Reçu par » switched off or made required since. What the API refused it for is said as a failure in the refusal's
 * own words, so nobody takes the name for put back.
 */
export function sayReceiverUndoRefused(
  feedback: Feedback,
  translate: TranslateService,
  figures: RefusalFigures,
  refusals: readonly FieldRefusal[],
): void {
  const [first] = refusals;
  const why = first === undefined ? null : refusedError(translate, first, first.params, figures);
  feedback.failure('receivers.undo_refused', {
    why: why === null ? '' : translate.instant(why.key, why.params),
  });
}

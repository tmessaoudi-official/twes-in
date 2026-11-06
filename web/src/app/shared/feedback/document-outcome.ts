// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormatFacade } from '../i18n/format-facade';

/** A document as the API answers once it was issued, validated or sent: it carries its number by then. */
export interface OutcomeDocument {
  readonly number: string | null;
}

/**
 * What the toast after issuing, validating or sending a document names: the number it was just given, who it is for
 * and what it comes to, the amount written from the API's decimal string through the one canon, so a person sees which
 * document left without opening it. The party is the name the document recorded as it left, where it records one; the
 * number and that name are typed nullable only because a draft shares the type, and the API sets both by then.
 */
export function documentOutcome(
  format: FormatFacade,
  document: OutcomeDocument,
  party: string,
  total: { readonly amount: string; readonly scale: number; readonly currency: string },
): Record<string, string> {
  return {
    number: document.number ?? '',
    party,
    total: format.amount(total.amount, total.scale),
    currency: total.currency,
  };
}

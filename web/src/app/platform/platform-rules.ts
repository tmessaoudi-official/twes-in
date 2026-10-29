// SPDX-License-Identifier: AGPL-3.0-or-later

import { emailTaken } from '../shared/form/email-rule';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { typedText } from '../shared/form/typed-text';

/**
 * What the API holds a new company's name (`CompanyResource`) and an owner's address (`PlatformOwnerInvitationResource`)
 * to. Every platform write waits for the operator to prove who is at the screen, and the API asks that proof before it
 * reads what was sent, so a value the screen knows to be wrong is said here, under its field, before any proof is
 * asked. `PlatformFormRulesTest` holds these to the API's own.
 */
export const COMPANY_NAME_MAX = 160;
export const OWNER_EMAIL_MAX = 254;

/** Why a new company's name would be refused, as the API words it; null when it would be taken. */
export function companyNameRefusal(name: string): FieldRefusal | null {
  if (name === '') return refusal('name', 'value_required');
  if (name.length > COMPANY_NAME_MAX) {
    return refusal('name', 'invalid_text', { max: COMPANY_NAME_MAX });
  }
  return null;
}

/**
 * Why an owner's address would be refused, as the API words it; null when it would be taken. Its shape is the one
 * e-mail rule every address field of the screens and the API is held to.
 */
export function ownerEmailRefusal(email: string): FieldRefusal | null {
  const typed = typedText(email);
  if (typed === '') return refusal('email', 'value_required');
  if (typed.length > OWNER_EMAIL_MAX) {
    return refusal('email', 'invalid_text', { max: OWNER_EMAIL_MAX });
  }
  if (!emailTaken(typed)) return refusal('email', 'invalid_email');
  return null;
}

function refusal(
  field: string,
  reason: string,
  params: Readonly<Record<string, number>> = {},
): FieldRefusal {
  return { field, reason, params };
}

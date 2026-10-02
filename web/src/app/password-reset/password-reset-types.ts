// SPDX-License-Identifier: AGPL-3.0-or-later

/** Why a link could not be asked for or used; the page says each in its own words. */
export type PasswordResetError =
  | 'invalid_email'
  | 'link_not_usable'
  | 'too_short'
  | 'breached'
  | 'too_many_attempts'
  | 'network'
  | 'refused';

const KNOWN: readonly PasswordResetError[] = [
  'invalid_email',
  'link_not_usable',
  'too_short',
  'breached',
  'too_many_attempts',
  'network',
];

/** The API's error code as one the pages know; anything else is a plain refusal. */
export function resetErrorOf(code: string | undefined): PasswordResetError {
  return KNOWN.find((known) => known === code) ?? 'refused';
}

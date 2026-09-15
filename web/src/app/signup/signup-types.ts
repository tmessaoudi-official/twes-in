// SPDX-License-Identifier: AGPL-3.0-or-later

/** Whether anyone may sign up, and the countries a company may be opened in (those with a fiscal preset). */
export interface SignupAvailability {
  readonly enabled: boolean;
  readonly countries: readonly string[];
}

/** What the far end of a signup link asks for. */
export interface SignupDetails {
  readonly displayName: string;
  readonly password: string;
  readonly companyName: string;
  readonly countryCode: string;
  readonly timezone: string;
}

export interface SignupCompleted {
  readonly companyName: string;
  /** "pending" while an operator's approval is required, "active" otherwise. */
  readonly companyStatus: string;
}

/**
 * Why a signup call was refused. "not_usable" covers closed, unknown, malformed, expired, used and taken: the API
 * answers those identically on purpose. "refused" is a form the API would not honour (an address that is not one, a
 * company name already taken, a password known from a breach); "too_many" is the per-client budget.
 */
export type SignupError = 'not_usable' | 'refused' | 'too_many' | 'network';

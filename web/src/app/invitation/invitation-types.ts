// SPDX-License-Identifier: AGPL-3.0-or-later

/** What a valid link tells the person holding it, before they have signed in. */
export interface InvitationOffer {
  readonly email: string;
  readonly companyName: string;
  readonly roleName: string;
  readonly expiresAt: string;
  /** The address already has an account: accepting then asks for nothing, and never sets a password. */
  readonly hasAccount: boolean;
  /** The platform refused the company before it was ever opened: said before the form, as signing in would say it. */
  readonly companyRefused: boolean;
  /** The platform suspended the company once open: said before the form too. */
  readonly companySuspended: boolean;
  /** The platform's reason for either, null when it gave none. */
  readonly companyReason: string | null;
}

/**
 * Why the link or the form was refused. "not_usable" covers unknown, malformed, expired and already used:
 * the API answers those identically on purpose, and the page must not pretend to tell them apart.
 */
export type InvitationError =
  'not_usable' | 'password_refused' | 'too_many' | 'invalid' | 'network';

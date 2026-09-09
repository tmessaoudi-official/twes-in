// SPDX-License-Identifier: AGPL-3.0-or-later

/** What a valid link tells the person holding it, before they have signed in. */
export interface InvitationOffer {
  readonly email: string;
  readonly companyName: string;
  readonly roleName: string;
  readonly expiresAt: string;
}

/**
 * Why the link or the form was refused. "not_usable" covers unknown, malformed, expired and already used:
 * the API answers those identically on purpose, and the page must not pretend to tell them apart.
 */
export type InvitationError = 'not_usable' | 'password_refused' | 'invalid' | 'network';

// SPDX-License-Identifier: AGPL-3.0-or-later

/** The three built-in roles; the API refuses anything else. */
export type MemberRole = 'owner' | 'admin' | 'member';

/** One company the signed-in user may work in, as the switcher lists it. */
export interface CompanyOption {
  readonly id: string;
  readonly name: string;
  readonly status: string;
  readonly role: string;
}

/** One person in the company being administered. */
export interface MemberRow {
  readonly userId: string;
  readonly email: string;
  readonly displayName: string;
  readonly role: string;
  readonly joinedAt: string;
  /** Whether that address became a member, or was sent an invitation because it has no account yet. */
  readonly status: 'joined' | 'invited';
}

/**
 * Why the API refused. "not_found" covers both a company that does not exist and one the caller has nothing
 * to do with: the API answers those identically on purpose, and the UI must not pretend to tell them apart.
 */
export type CompanyError =
  'unknown_user' | 'already_member' | 'last_owner' | 'not_found' | 'invalid' | 'network';

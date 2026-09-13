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
/** A registration number the company's fiscal preset asks for, labelled by the API in the reader's language. */
export interface IdentifierField {
  readonly key: string;
  readonly label: string;
  /** A regular expression the whole value matches. */
  readonly pattern: string;
  readonly required: boolean;
}

/** A VAT regime the preset offers companies, labelled by the API. */
export interface VatRegimeOption {
  readonly code: string;
  readonly label: string;
}

/** What the company's documents say about it, as the profile page edits it. */
export interface CompanyProfileChanges {
  readonly legalName: string | null;
  readonly legalForm: string | null;
  readonly identifiers: Readonly<Record<string, string>>;
  readonly addressLine1: string | null;
  readonly addressLine2: string | null;
  readonly postalCode: string | null;
  readonly city: string | null;
  readonly email: string | null;
  readonly phone: string | null;
  readonly website: string | null;
  readonly iban: string | null;
  readonly bic: string | null;
  readonly vatRegime: string;
  readonly invoiceFooterText: string | null;
  readonly latePenaltyText: string | null;
}

/** The profile with what the preset asks for and whether the caller may revise it. */
export interface CompanyProfile extends CompanyProfileChanges {
  readonly name: string;
  readonly countryCode: string;
  readonly writable: boolean;
  readonly identifierFields: readonly IdentifierField[];
  readonly vatRegimes: readonly VatRegimeOption[];
}

export type CompanyError =
  'unknown_user' | 'already_member' | 'last_owner' | 'not_found' | 'invalid' | 'network';

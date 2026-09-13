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
  | 'unknown_user'
  | 'already_member'
  | 'last_owner'
  | 'code_taken'
  | 'not_found'
  | 'invalid'
  | 'network';

/** One place the company issues documents from, as the establishments page lists it. */
export interface EstablishmentRow {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly addressLine1: string | null;
  readonly addressLine2: string | null;
  readonly postalCode: string | null;
  readonly city: string | null;
  readonly phone: string | null;
  readonly email: string | null;
  readonly isDefault: boolean;
  /** A regular expression the whole code matches: the shape the company's fiscal preset gives a code. */
  readonly codePattern: string;
}

/** What a person writes about an establishment; setting isDefault hands the role over from the current default. */
export type EstablishmentInput = Omit<EstablishmentRow, 'id' | 'codePattern'>;

export type ResetPeriod = 'yearly' | 'monthly' | 'never';

export const RESET_PERIODS: readonly ResetPeriod[] = ['yearly', 'monthly', 'never'];

/** How one establishment numbers one document type, with the number the next document would get today. */
export interface NumberingSeriesRow {
  readonly id: string;
  readonly establishmentId: string;
  readonly establishmentCode: string;
  readonly documentType: string;
  readonly format: string;
  readonly nextNumber: number;
  readonly resetPeriod: ResetPeriod;
  readonly isDefault: boolean;
  readonly preview: string;
}

export interface NumberingChanges {
  readonly format: string;
  readonly nextNumber: number;
  readonly resetPeriod: ResetPeriod;
}

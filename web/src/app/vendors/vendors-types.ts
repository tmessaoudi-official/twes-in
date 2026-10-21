// SPDX-License-Identifier: AGPL-3.0-or-later

/** Why the API refused, as the vendors screens translate it. */
export type VendorsError =
  'network' | 'not_found' | 'number_taken' | 'step_up_required' | 'invalid' | 'record_changed';

/** What the API sorts vendors by. */
export type VendorSortKey = 'number' | 'name' | 'city' | 'paymentTermsDays' | 'isActive';

/** One page of the vendors list as the API searches, narrows and sorts it (docs/SPEC.md § 7, lists at scale). */
export interface VendorSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in the number, name, legal name, email, address or registration numbers; empty finds all. */
  q: string;
  isActive: boolean | null;
  /** Marked or lacking something (true), neither (false), or either (null). */
  toCheck: boolean | null;
  /**
   * The ends of the intervals (row 197): `paymentTermsDays.min` and `.max` in whole days, `createdAt.from` and `.to`
   * in the company's own calendar, each already valid.
   */
  intervals: Readonly<Record<string, string>>;
  order: { key: VendorSortKey; direction: 'asc' | 'desc' } | null;
}

export interface VendorAddress {
  line1: string | null;
  line2: string | null;
  postalCode: string | null;
  city: string | null;
  countryCode: string | null;
}

export interface VendorRow {
  id: string;
  /** The version read, which a revision sends back (a later one saved elsewhere refuses it). */
  version: number;
  number: string;
  name: string;
  legalName: string | null;
  identifiers: Record<string, string>;
  email: string | null;
  phone: string | null;
  website: string | null;
  address: VendorAddress;
  /** Uppercase, without spaces. */
  iban: string | null;
  bic: string | null;
  /** Days after the vendor's invoice its payment is due. */
  paymentTermsDays: number | null;
  notes: string | null;
  isActive: boolean;
  /** The expense category this vendor's expenses usually go to, archived since or not. */
  defaultExpenseCategoryId: string | null;
  /** The vendor kept when this one was merged into it: this one then stays only for what already names it. */
  mergedInto: VendorMergedInto | null;
  /** « À vérifier » as a person set it, with why. */
  toCheck: boolean;
  checkNote: string | null;
  /** The name as read from a file, not yet confirmed by a person; confirming it is unticking this. */
  nameDoubtful: boolean;
  /** What the vendor lacks, as the API says it; never written. */
  checkReasons: VendorCheckReason[];
}

/** What « À vérifier » says a vendor lacks, in the API's order. */
export type VendorCheckReason = 'name_doubtful';
export const VENDOR_CHECK_REASONS: readonly VendorCheckReason[] = ['name_doubtful'];

/** The vendor a vendor merged away points at, which lists show in its place. */
export interface VendorMergedInto {
  id: string;
  number: string;
  name: string;
}

/** What a form writes: never the merge, which only « Fusionner… » does. */
export type VendorInput = Omit<VendorRow, 'id' | 'version' | 'mergedInto' | 'checkReasons'>;

export interface VendorIdentifierOption {
  key: string;
  label: string;
  /** Anchored by the preset, without delimiters. */
  pattern: string;
}

/** One of the company's expense categories, as the vendor form names it. */
export interface VendorExpenseCategory {
  id: string;
  name: string;
  active: boolean;
}

/** What the vendor form offers: the company's country and the registration numbers its preset knows. */
export interface VendorOptions {
  countryCode: string;
  identifiers: VendorIdentifierOption[];
  /** Null while the expenses module is off: the form then has no category field. */
  expenseCategories: VendorExpenseCategory[] | null;
}

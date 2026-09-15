// SPDX-License-Identifier: AGPL-3.0-or-later

/** Why the API refused, as the vendors screens translate it. */
export type VendorsError = 'network' | 'not_found' | 'number_taken' | 'invalid';

export interface VendorAddress {
  line1: string | null;
  line2: string | null;
  postalCode: string | null;
  city: string | null;
  countryCode: string | null;
}

export interface VendorRow {
  id: string;
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
}

export type VendorInput = Omit<VendorRow, 'id'>;

export interface VendorIdentifierOption {
  key: string;
  label: string;
  /** Anchored by the preset, without delimiters. */
  pattern: string;
}

/** What the vendor form offers: the company's country and the registration numbers its preset knows. */
export interface VendorOptions {
  countryCode: string;
  identifiers: VendorIdentifierOption[];
}

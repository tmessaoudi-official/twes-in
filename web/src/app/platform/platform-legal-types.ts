// SPDX-License-Identifier: AGPL-3.0-or-later

import type { LegalLanguage } from '../shared/legal/legal-api';
import type { LegalPage } from '../shared/legal/legal-pages';

/** Where one legal page stands in one language: written or not, and its latest version's date and validation. */
export interface LegalStatusRow {
  readonly page: LegalPage;
  readonly language: LegalLanguage;
  readonly written: boolean;
  readonly publishedOn: string | null;
  readonly validated: boolean;
}

/** One version of a page in a language, as the operator's history lists it. */
export interface LegalVersionRow {
  readonly id: string;
  readonly body: string;
  readonly publishedOn: string;
  readonly validated: boolean;
  readonly validatedAt: string | null;
  /** The address of whoever validated it. */
  readonly validatedBy: string | null;
  readonly createdAt: string;
  /** The address of whoever wrote it; null for a draft the platform shipped. */
  readonly createdBy: string | null;
}

/** The publisher's and host's identity by placeholder, and the version of each one's row, 0 where none is stored. */
export interface LegalIdentity {
  readonly values: Readonly<Record<string, string>>;
  readonly versions: Readonly<Record<string, number>>;
  /** The platform's own country, which the identity's phones start on; null where the API declares none. */
  readonly country: string | null;
}

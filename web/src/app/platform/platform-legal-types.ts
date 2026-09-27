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

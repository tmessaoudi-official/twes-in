// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import type { PickOption } from '../shared/form/pick-field';
import type { AddToDraft } from '../shared/documents/add-to-dialog';
import { customerOf, draftOf, draftSearch } from '../shared/documents/add-to-reading';
import type { AddToReader } from '../shared/documents/add-to-sources';
import { QuotesApi } from './quotes-api';

/** The open quote drafts goods may go on. */
@Injectable({ providedIn: 'root' })
export class QuotesAddTo implements AddToReader {
  private readonly api = inject(QuotesApi);

  async drafts(companyId: string, customerId: string | null): Promise<readonly AddToDraft[]> {
    return (await this.api.quotes(companyId, draftSearch(customerId))).rows.map(draftOf);
  }

  async customers(companyId: string, words: string): Promise<readonly PickOption[]> {
    return (await this.api.pickCustomers(companyId, { words })).map(customerOf);
  }
}

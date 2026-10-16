// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import type { PickOption } from '../shared/form/pick-field';
import type { AddToDraft } from '../shared/documents/add-to-dialog';
import { customerOf, draftOf, draftSearch } from '../shared/documents/add-to-reading';
import type { AddToReader } from '../shared/documents/add-to-sources';
import { InvoicesApi } from './invoices-api';

/** The open invoice drafts goods may go on; a credit note is corrected, never added to. */
@Injectable({ providedIn: 'root' })
export class InvoicesAddTo implements AddToReader {
  private readonly api = inject(InvoicesApi);

  async drafts(companyId: string, customerId: string | null): Promise<readonly AddToDraft[]> {
    const page = await this.api.invoices(companyId, {
      ...draftSearch(customerId),
      documentType: ['invoice'],
    });
    return page.rows.map(draftOf);
  }

  async customers(companyId: string, words: string): Promise<readonly PickOption[]> {
    return (await this.api.pickCustomers(companyId, { words })).map(customerOf);
  }
}

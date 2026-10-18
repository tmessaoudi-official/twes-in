// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import type { AddToDraft } from '../shared/documents/add-to-dialog';
import { DRAFTS_SHOWN } from '../shared/documents/add-to-reading';
import type { AddToReader } from '../shared/documents/add-to-sources';
import type { PickOption } from '../shared/form/pick-field';
import { PurchasesApi } from './purchases-api';

/** The open orders to vendors « Ajouter à… » offers, found by their vendor rather than a customer. */
@Injectable({ providedIn: 'root' })
export class PurchasesAddTo implements AddToReader {
  private readonly api = inject(PurchasesApi);

  async drafts(companyId: string, vendorId: string | null): Promise<readonly AddToDraft[]> {
    const page = await this.api.orders(companyId, {
      page: 1,
      itemsPerPage: DRAFTS_SHOWN,
      q: '',
      status: ['draft'],
      vendorIds: vendorId === null ? [] : [vendorId],
      intervals: {},
      order: null,
    });
    return page.rows.map((row) => ({
      id: row.id,
      customerName: row.vendorName,
      customerReference: null,
      total: row.totalNet,
      lineCount: row.lines.length,
    }));
  }

  async customers(companyId: string, words: string): Promise<readonly PickOption[]> {
    return (await this.api.pickVendors(companyId, { words })).map((vendor) => ({
      id: vendor.id,
      code: vendor.number,
      name: vendor.name,
    }));
  }
}

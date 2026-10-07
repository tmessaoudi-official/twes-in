// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import type { PickAsked } from '../shared/form/pick-api';
import { InvoicesFacade } from './invoices-facade';
import type { ProductOption, ResolvedPrice } from './invoices-types';

/**
 * Where a document's lines find their products and the price each starts at. The invoice's own facade unless the
 * screen provides another: a quote's lines ask under the quote's permission and module, not the invoice's.
 */
@Injectable({ providedIn: 'root', useFactory: () => inject(InvoicesFacade) })
export abstract class LineCatalogue {
  abstract pickProducts(companyId: string, asked: PickAsked): Promise<ProductOption[]>;

  /** What the product sells at for that customer and quantity; null where the price cannot be read. */
  abstract productPrice(
    companyId: string,
    productId: string,
    customerId: string | null,
    quantity: string,
  ): Promise<ResolvedPrice | null>;
}

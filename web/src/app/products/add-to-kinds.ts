// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Provider } from '@angular/core';
import { DELIVERY_NOTE_ADD_TO } from '../delivery-notes/delivery-notes-add-to-source';
import { INVOICE_ADD_TO } from '../invoices/invoices-add-to-source';
import { QUOTE_ADD_TO } from '../quotes/quotes-add-to-source';
import { AddToDocuments } from '../shared/documents/add-to-documents';
import { ADD_TO_SOURCES } from '../shared/documents/add-to-sources';

/**
 * « Ajouter à… » as the products screens offer it: the documents products may go on, in the order offered. Provided by
 * those screens rather than at the root, which keeps the declarations off the first page.
 */
export const PRODUCTS_ADD_TO: Provider[] = [
  AddToDocuments,
  { provide: ADD_TO_SOURCES, useValue: [INVOICE_ADD_TO, QUOTE_ADD_TO, DELIVERY_NOTE_ADD_TO] },
];

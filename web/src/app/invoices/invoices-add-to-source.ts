// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AddToSource } from '../shared/documents/add-to-sources';
import { INVOICES_MODULE } from './invoices-nav';

/** « Ajouter à… »: the goods chosen go on a new or open invoice draft, for whoever may write one. */
export const INVOICE_ADD_TO: AddToSource = {
  kind: 'invoice',
  module: INVOICES_MODULE,
  permission: 'invoice.write',
  path: '/invoices',
  reader: () => import('./invoices-add-to').then((feature) => feature.InvoicesAddTo),
};

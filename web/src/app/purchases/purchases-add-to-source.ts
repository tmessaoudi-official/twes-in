// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AddToSource } from '../shared/documents/add-to-sources';
import { PURCHASES_MODULE } from './purchases-nav';

/** « Ajouter à… »: the goods chosen go on a new or open order to a vendor, for whoever may draft one. */
export const PURCHASE_ORDER_ADD_TO: AddToSource = {
  kind: 'purchase_order',
  party: 'vendor',
  module: PURCHASES_MODULE,
  permission: 'purchase.write',
  path: '/purchases',
  reader: () => import('./purchases-add-to').then((feature) => feature.PurchasesAddTo),
};

// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AddToSource } from '../shared/documents/add-to-sources';
import { QUOTES_MODULE } from './quotes-nav';

/** « Ajouter à… »: the goods chosen go on a new or open quote draft, for whoever may write one. */
export const QUOTE_ADD_TO: AddToSource = {
  kind: 'quote',
  party: 'customer',
  module: QUOTES_MODULE,
  permission: 'quote.write',
  path: '/quotes',
  reader: () => import('./quotes-add-to').then((feature) => feature.QuotesAddTo),
};

// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AddToSource } from '../shared/documents/add-to-sources';
import { DELIVERY_NOTES_MODULE } from './delivery-notes-nav';

/** « Ajouter à… »: the goods chosen go on a new or open delivery note draft, for whoever may write one. */
export const DELIVERY_NOTE_ADD_TO: AddToSource = {
  kind: 'delivery_note',
  party: 'customer',
  module: DELIVERY_NOTES_MODULE,
  permission: 'delivery_note.write',
  path: '/delivery-notes',
  reader: () => import('./delivery-notes-add-to').then((feature) => feature.DeliveryNotesAddTo),
};

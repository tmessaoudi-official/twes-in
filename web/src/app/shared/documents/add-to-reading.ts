// SPDX-License-Identifier: AGPL-3.0-or-later

import type { PickOption } from '../form/pick-field';
import type { AddToDraft } from './add-to-dialog';

/** How many open drafts the picker shows at once: picking their customer finds the others. */
const DRAFTS_SHOWN = 20;

/** The list search that reads one customer's open drafts, or every customer's. */
export function draftSearch(customerId: string | null) {
  return {
    page: 1,
    itemsPerPage: DRAFTS_SHOWN,
    q: '',
    status: ['draft' as const],
    customerIds: customerId === null ? [] : [customerId],
    intervals: {},
    order: null,
  };
}

export function draftOf(row: {
  id: string;
  customerName: string | null;
  customerReference: string | null;
  total: string;
  lines: readonly unknown[];
}): AddToDraft {
  return {
    id: row.id,
    customerName: row.customerName,
    customerReference: row.customerReference,
    total: row.total,
    lineCount: row.lines.length,
  };
}

export function customerOf(customer: { id: string; number: string; name: string }): PickOption {
  return { id: customer.id, code: customer.number, name: customer.name };
}

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The texts of delivering a document by notes, which a quote's page and a delivery note's page read: the actions, the
 * deliveries card, the quantities dialog and what a note says of the document it delivers. Opened into the root by those
 * routes, since the toast that says a note was drafted outlives the quote's page. A file of its own, so the routes that
 * open them do not carry a feature's code onto the first page.
 */
export const DELIVERIES_TEXTS = 'deliveries';

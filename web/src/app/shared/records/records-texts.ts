// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The texts deleting a record and « Récemment supprimés » read, opened into the root on every screen that deletes one,
 * since the toast that says a delete and offers « Annuler » outlives the page it was made on. A file of its own, so the
 * routes that open them do not carry the delete's code onto the first page.
 */
export const RECORDS_TEXTS = 'records';

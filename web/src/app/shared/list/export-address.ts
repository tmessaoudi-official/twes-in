// SPDX-License-Identifier: AGPL-3.0-or-later

export type ExportFormat = 'csv' | 'xlsx';

/** What the list's request carries, as an adapter builds it: Angular's `HttpParams` answers it. */
export interface SearchParams {
  delete(name: string): SearchParams;
  toString(): string;
}

/**
 * Where a list is downloaded as a file: the list's own words, choices and order over every page of it. A plain
 * address, so the browser saves it with the session it already holds. The page and its size are dropped, since a file
 * holds every row.
 */
export function exportAddress(
  companyId: string,
  list: string,
  search: SearchParams,
  format: ExportFormat,
): string {
  const query = search.delete('page').delete('itemsPerPage').toString();
  return `/api/companies/${encodeURIComponent(companyId)}/exports/${list}.${format}${query === '' ? '' : `?${query}`}`;
}

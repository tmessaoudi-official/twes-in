// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ExportFormat } from '../shared/list/export-address';

/** The four files an accountant takes for a period, in the order the page lists them. */
export const ACCOUNTING_FILES = [
  'sales-journal',
  'purchases-journal',
  'payments-journal',
  'vat-summary',
] as const;

export type AccountingFile = (typeof ACCOUNTING_FILES)[number];

/** Where a file of a period is downloaded: the export engine's address, with the period's two days. */
export function accountingFileAddress(
  companyId: string,
  file: AccountingFile,
  from: string,
  to: string,
  format: ExportFormat,
): string {
  const period = `from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
  return `/api/companies/${encodeURIComponent(companyId)}/exports/${file}.${format}?${period}`;
}

/** The month before the one a day falls in, as its first and last day: the period an accountant asks for most. */
export function lastMonth(today: string): { from: string; to: string } {
  const [year, month] = today.split('-').map(Number);
  const first = new Date(Date.UTC(year, month - 2, 1));
  const last = new Date(Date.UTC(year, month - 1, 0));
  return { from: first.toISOString().slice(0, 10), to: last.toISOString().slice(0, 10) };
}

/** The longest period the API takes, a leap year's days apart at most, as `JournalPeriod` says. */
const LONGEST = 366;

/** Whether two days make a period the API takes: both written, in order, less than a leap year's days apart. */
export function isPeriod(from: string, to: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to) || from > to) {
    return false;
  }
  const days = (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000;
  return Number.isFinite(days) && days < LONGEST;
}

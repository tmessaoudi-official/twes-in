// SPDX-License-Identifier: AGPL-3.0-or-later

import type { InstrumentKind, InstrumentStatus } from './instruments-types';

/**
 * One cheque or traite of the company's portfolio with the invoice it was received against (docs/SPEC.md § 7,
 * 2026-09-21 18:40). Receiving, depositing and cashing stay on the invoice, where the money is owed.
 */
export interface PortfolioRow {
  id: string;
  invoiceId: string;
  invoiceNumber: string | null;
  customerName: string;
  currency: string;
  kind: InstrumentKind;
  /** The API's decimal string. */
  amount: string;
  /** The day it falls due (ISO). */
  dueOn: string;
  bank: string | null;
  number: string | null;
  status: InstrumentStatus;
  settledOn: string | null;
}

/** What a chip narrows the portfolio to: what is still open, held and deposited together, or one status. */
export type PortfolioScope = InstrumentStatus | 'open';
export const PORTFOLIO_SCOPES: readonly PortfolioScope[] = [
  'open',
  'held',
  'deposited',
  'cashed',
  'unpaid',
];

export type PortfolioSortKey = 'dueOn' | 'amount' | 'status';

/** What the API is asked for the page of the portfolio the list shows. */
export interface PortfolioSearch {
  page: number;
  itemsPerPage: number;
  /** Null lists every status. */
  status: PortfolioScope | null;
  order: { key: PortfolioSortKey; direction: 'asc' | 'desc' } | null;
}

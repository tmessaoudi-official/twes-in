// SPDX-License-Identifier: AGPL-3.0-or-later

import type { StatusTone } from '../shared/theme/accent-theme';

/** Why the API refused, as the expenses screens translate it. */
export type ExpensesError =
  | 'network'
  | 'not_found'
  | 'not_draft'
  | 'name_taken'
  | 'invalid'
  | 'file_refused'
  | 'file_too_large';

export type ExpenseStatus = 'draft' | 'recorded' | 'paid';

export const EXPENSE_STATUSES: readonly ExpenseStatus[] = ['draft', 'recorded', 'paid'];

export const EXPENSE_STATUS_TONES: Readonly<Record<ExpenseStatus, StatusTone>> = {
  draft: 'neutral',
  recorded: 'info',
  paid: 'success',
};

export type PaymentMethod = 'transfer' | 'cash' | 'check' | 'card' | 'other';

/**
 * The sorts the API answers. The due day is not among them: it is the vendor's payment terms counted from the
 * expense's day, worked out when the expense is read and not a column the database can order by.
 */
export type ExpenseSortKey =
  'date' | 'description' | 'vendor' | 'category' | 'amountGross' | 'status';

/** One page of the expenses list as the API searches, narrows and sorts it (docs/SPEC.md § 7, lists at scale). */
export interface ExpenseSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in what the expense is for or in the vendor's reference on it; empty finds all. */
  q: string;
  status: ExpenseStatus | null;
  vendorId: string | null;
  categoryId: string | null;
  order: { key: ExpenseSortKey; direction: 'asc' | 'desc' } | null;
}

export interface ExpenseRow {
  id: string;
  status: ExpenseStatus;
  /** YYYY-MM-DD. */
  date: string;
  reference: string | null;
  description: string;
  vendorId: string | null;
  vendorName: string | null;
  categoryId: string | null;
  categoryName: string | null;
  /** Decimal strings at the currency's scale. */
  amountNet: string;
  taxComponentId: string | null;
  /** Percent, three decimals. */
  taxRate: string | null;
  taxAmount: string;
  amountGross: string;
  currency: string;
  dueDate: string | null;
  paymentMethod: PaymentMethod | null;
  paidOn: string | null;
  notes: string | null;
  attachmentCount: number;
}

export interface ExpenseInput {
  date: string;
  reference: string | null;
  description: string;
  vendorId: string | null;
  categoryId: string | null;
  amountNet: string;
  taxComponentId: string | null;
  notes: string | null;
}

export interface ExpensePayment {
  paymentMethod: PaymentMethod;
  /** YYYY-MM-DD, from the expense's day to today. */
  paidOn: string;
}

export interface ExpenseCategoryRow {
  id: string;
  name: string;
  parentId: string | null;
  isActive: boolean;
}

export type ExpenseCategoryInput = Omit<ExpenseCategoryRow, 'id'>;

/** One vendor as the expense form's picker answers it. */
export interface ExpenseVendorOption {
  id: string;
  number: string;
  name: string;
  paymentTermsDays: number | null;
  defaultExpenseCategoryId: string | null;
}

export interface ExpenseTaxOption {
  id: string;
  code: string;
  name: string;
  rate: string;
}

/**
 * What the expense form offers: the currency, the active categories, the rates on the net. The VENDORS are asked
 * for a few at a time through the picker instead (docs/SPEC.md § 7, 2026-09-17, ruling 3).
 */
export interface ExpenseOptions {
  currency: string;
  currencyScale: number;
  categories: Omit<ExpenseCategoryRow, 'isActive'>[];
  taxes: ExpenseTaxOption[];
  paymentMethods: PaymentMethod[];
}

export interface ExpenseAttachment {
  id: string;
  name: string;
  /** Read by the API from the file's bytes. */
  mime: string;
  size: number;
  createdAt: string;
}

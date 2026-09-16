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

/** What the expense form offers: the currency, the active vendors and categories, the rates on the net. */
export interface ExpenseOptions {
  currency: string;
  currencyScale: number;
  vendors: ExpenseVendorOption[];
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

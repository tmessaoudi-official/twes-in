// SPDX-License-Identifier: AGPL-3.0-or-later

import type { StatusTone } from '../shared/theme/accent-theme';
import { type LifecycleStage, tonesOf } from '../shared/theme/lifecycle-tones';

/** Why the API refused, as the expenses screens translate it. */
export type ExpensesError =
  | 'network'
  | 'not_found'
  | 'not_draft'
  | 'name_taken'
  | 'invalid'
  | 'file_refused'
  | 'files_full'
  | 'file_too_large'
  | 'period_closed'
  | 'record_changed';

export type ExpenseStatus = 'draft' | 'recorded' | 'paid';

export const EXPENSE_STATUSES: readonly ExpenseStatus[] = ['draft', 'recorded', 'paid'];

/** Where each status stands (design direction § 1.1); the badge's tone derives from it. */
export const EXPENSE_STATUS_STAGES: Readonly<Record<ExpenseStatus, LifecycleStage>> = {
  draft: 'not-started',
  recorded: 'under-way',
  paid: 'done',
};

export const EXPENSE_STATUS_TONES: Readonly<Record<ExpenseStatus, StatusTone>> =
  tonesOf(EXPENSE_STATUS_STAGES);

export type PaymentMethod = 'transfer' | 'cash' | 'check' | 'card' | 'other';
export const PAYMENT_METHODS: readonly PaymentMethod[] = [
  'transfer',
  'cash',
  'check',
  'card',
  'other',
];

/**
 * The sorts the API answers. The due day is not among them: it is the vendor's payment terms counted from the
 * expense's day, worked out when the expense is read and not a column the database can order by.
 */
export type ExpenseSortKey =
  'date' | 'description' | 'vendor' | 'category' | 'amountGross' | 'status';

/** One page of the expenses list as the API searches, narrows and sorts it (docs/SPEC.md § 7, lists at scale). */
/** What each status chip of the list would show under the same search, as the API counted it (docs/SPEC.md § 7, 2026-09-26). */
/** What the home shows of the expenses: recorded so far this month, taxes included, and over the same days of last month. */
export interface ExpenseSummary {
  readonly currency: string;
  readonly currencyScale: number;
  /** The company's own day, which names the month. */
  readonly today: string;
  readonly month: string;
  readonly lastMonth: string;
}

export interface ExpenseStatusCounts {
  all: number;
  statuses: Record<ExpenseStatus, number>;
}

export interface ExpenseSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in what the expense is for or in the vendor's reference on it; empty finds all. */
  q: string;
  /** Any of these; none is every status. Each filter's values are OR'd, the filters AND'd (row 197). */
  status: readonly ExpenseStatus[];
  paymentMethods: readonly PaymentMethod[];
  /** Whether something was withheld at the source when it was paid; null asks either. */
  withheld: 'yes' | 'no' | null;
  vendorIds: readonly string[];
  /** Each with every category under it, which the API adds. */
  categoryIds: readonly string[];
  /** The ends of the intervals, by `date.from`, `amountGross.max`: each already valid. */
  intervals: Readonly<Record<string, string>>;
  order: { key: ExpenseSortKey; direction: 'asc' | 'desc' } | null;
}

export interface ExpenseRow {
  id: string;
  /** The version read, which a revision sends back; one saved since is refused as `record_changed`. */
  version: number;
  status: ExpenseStatus;
  /** YYYY-MM-DD. */
  date: string;
  reference: string | null;
  description: string;
  vendorId: string | null;
  vendorName: string | null;
  /** Who was paid, in words, when no vendor record names them: a car park, a notary. Never beside a vendor. */
  payee: string | null;
  categoryId: string | null;
  categoryName: string | null;
  /** The supplier's bill as it prints it: its lines, the fixed charges it carries and the tax amounts it prints. */
  lines: ExpenseLine[];
  documentTaxComponentIds: string[];
  printedTaxes: PrintedTax[];
  /** What the bill charges of each tax, rates first in the order the bill shows them, then the fixed charges. */
  taxes: ExpenseTax[];
  /** Decimal strings at the currency's scale: the lines, the rated taxes, the fixed charges, and all of it. */
  amountNet: string;
  taxAmount: string;
  fixedAmount: string;
  amountGross: string;
  currency: string;
  dueDate: string | null;
  paymentMethod: PaymentMethod | null;
  paidOn: string | null;
  /** What the payment withheld from the supplier: percent with three decimals, and the amount; null when nothing was. */
  withholdingRate: string | null;
  withholdingAmount: string | null;
  /** The TEJ operation the withholding is declared under (Tunisia), as a person gave it; null when nobody did. */
  withholdingOperationCode: string | null;
  /** What the supplier is handed: the gross less what was withheld. */
  amountPaid: string;
  /** What paying this recorded expense would withhold unless told otherwise; null for none. */
  suggestedWithholdingRate: string | null;
  notes: string | null;
  attachmentCount: number;
  /** A draft a recurring expense of variable amount made: its amount is checked on the bill before it is recorded. */
  amountToCheck: boolean;
}

/** One block of a supplier's bill: a net and the rates on it. */
export interface ExpenseLine {
  amountNet: string;
  taxComponentIds: string[];
  /** The rates' codes as the expense recorded them; absent on what is sent. */
  taxCodes?: string[];
}

/** A tax amount a bill prints where it differs from the worked-out one by the supplier's rounding. */
export interface PrintedTax {
  code: string;
  amount: string;
}

export interface ExpenseTax {
  code: string;
  kind: 'percentage_line' | 'fixed_document';
  /** Percent, three decimals; null for a fixed charge, which has no base either. */
  rate: string | null;
  base: string | null;
  /** What twes-in works out, and what the bill charges. */
  workedOut: string;
  amount: string;
}

export interface ExpenseInput {
  date: string;
  reference: string | null;
  description: string;
  vendorId: string | null;
  payee: string | null;
  categoryId: string | null;
  lines: ExpenseLine[];
  documentTaxComponentIds: string[];
  printedTaxes: PrintedTax[];
  notes: string | null;
}

export interface ExpensePayment {
  paymentMethod: PaymentMethod;
  /** YYYY-MM-DD, from the expense's day to today. */
  paidOn: string;
  /** The percentage withheld from the supplier; "0" for none (docs/SPEC.md § 7, 2026-09-24 11:40, RPT-09). */
  withholdingRate: string;
  /** Tunisia only, and only when chosen: the API refuses a code for a company that does not declare to TEJ. */
  withholdingOperationCode?: string;
}

export interface ExpenseCategoryRow {
  id: string;
  name: string;
  parentId: string | null;
  isActive: boolean;
  /** The version read, which a revision sends back; one saved since answers `record_changed`. */
  version: number;
}

export type ExpenseCategoryInput = Omit<ExpenseCategoryRow, 'id' | 'version'>;

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
  /** Whether it is added to what VAT is taken on, as FODEC is. */
  entersVatBase: boolean;
}

/** A fixed amount a bill may carry once, such as the stamp. */
export interface ExpenseChargeOption {
  id: string;
  code: string;
  name: string;
  amount: string;
}

/**
 * What the expense form offers: the currency, the active categories, the rates on the net. The VENDORS are asked
 * for a few at a time through the picker instead (docs/SPEC.md § 7, 2026-09-17, ruling 3).
 */
export interface ExpenseOptions {
  currency: string;
  currencyScale: number;
  categories: Omit<ExpenseCategoryRow, 'isActive' | 'version'>[];
  taxes: ExpenseTaxOption[];
  charges: ExpenseChargeOption[];
  paymentMethods: PaymentMethod[];
  /** The TEJ operations a withholding may be declared under, labelled as the administration publishes them; empty
   * for a company that does not declare to TEJ (docs/SPEC.md § 7, 2026-09-26 00:22). */
  withholdingOperationCodes: TejOperation[];
}

/** Why the API refused a month's TEJ file (docs/SPEC.md § 7, 2026-09-26 00:22). */
export type TejRefusalCode =
  | 'not_declared_to_tej'
  | 'company_matricule_missing'
  | 'company_matricule_unreadable'
  | 'nothing_to_declare'
  | 'incomplete_expenses';

/** A payment the month's file cannot be written without, and what it lacks, as the API names it. */
export interface TejRefusedExpense {
  id: string;
  /** YYYY-MM-DD. */
  paidOn: string;
  description: string;
  reference: string | null;
  vendorName: string | null;
  problems: string[];
}

/** A month's TEJ file, or why it cannot be written yet, or `step_up` while it waits for who is at the screen to prove it. */
export type TejFileAnswer =
  | { kind: 'file'; file: Blob; filename: string }
  | { kind: 'step_up' }
  | {
      kind: 'refused';
      code: TejRefusalCode;
      params: Record<string, string | number>;
      expenses: TejRefusedExpense[];
    };

export interface TejOperation {
  code: string;
  /** In French, verbatim, typing included: the administration's text. */
  label: string;
}

export interface ExpenseAttachment {
  id: string;
  name: string;
  /** Read by the API from the file's bytes. */
  mime: string;
  size: number;
  createdAt: string;
}

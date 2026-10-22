// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormArray, FormControl, FormGroup, type ValidatorFn } from '@angular/forms';
import type {
  FieldOption,
  FieldValue,
  FormDescriptor,
  FormField,
  FormValues,
} from '../shared/form/form-types';
import { filterValues, idValues, rangeParams } from '../shared/list/list-filters';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { AMOUNT_WIDTH } from '../shared/list/list-view';
import {
  EXPENSE_STATUSES,
  PAYMENT_METHODS,
  type ExpenseCategoryInput,
  type ExpenseCategoryRow,
  type ExpenseInput,
  type ExpenseLine,
  type PrintedTax,
  type ExpenseOptions,
  type ExpensePayment,
  type ExpenseRow,
  type ExpenseSearch,
  type ExpenseSortKey,
  type ExpenseTax,
  type PaymentMethod,
  EXPENSE_STATUS_TONES,
} from './expenses-types';

const FIELDS = 'expenses.fields';

/** The list's intervals, as the API names them (docs/SPEC.md § 7, 2026-10-06 00:15, row 197). */
const EXPENSE_INTERVALS: readonly { id: string; kind: 'day' | 'amount'; label: string }[] = [
  { id: 'date', kind: 'day', label: `${FIELDS}.date` },
  { id: 'amountGross', kind: 'amount', label: `${FIELDS}.amountGross` },
];
const WITHHELD = ['yes', 'no'] as const;
const CATEGORY_FIELDS = 'expenses.categories.fields';

export const EXPENSES_LIST: ListDescriptor<ExpenseRow> = {
  id: 'expenses',
  rowId: (row) => row.id,
  // The row opens through its naming column, so a middle click and a copied address work
  // (design review finding 1); the trailing "Ouvrir" is gone.
  link: (row) => ['/expenses', row.id],
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'date', direction: 'desc' },
  columns: [
    {
      id: 'date',
      label: `${FIELDS}.date`,
      value: (row) => row.date,
      sortable: true,
      hideable: false,
      width: 130,
    },
    {
      id: 'description',
      label: `${FIELDS}.description`,
      value: (row) => row.description,
      sortable: true,
      filterable: true,
      hideable: false,
      wraps: true,
    },
    {
      // Who was paid: the vendor, else the payee written on the expense; the API sorts by the same.
      id: 'vendor',
      label: `${FIELDS}.payee`,
      value: (row) => row.vendorName ?? row.payee ?? '',
      sortable: true,
      filterable: true,
    },
    {
      id: 'category',
      label: `${FIELDS}.categoryId`,
      value: (row) => row.categoryName ?? '',
      sortable: true,
      filterable: true,
    },
    {
      id: 'reference',
      label: `${FIELDS}.reference`,
      value: (row) => row.reference ?? '',
      filterable: true,
      defaultHidden: true,
    },
    {
      id: 'amountGross',
      priority: 'high',
      label: `${FIELDS}.amountGross`,
      value: (row) => Number(row.amountGross),
      sortable: true,
      align: 'end',
      width: AMOUNT_WIDTH,
    },
    {
      id: 'dueDate',
      // Not sortable: the due day is the vendor's terms counted from the expense's day, which is not a column the
      // API can order a page by.
      label: `${FIELDS}.dueDate`,
      value: (row) => row.dueDate ?? '',
      shown: (row) => row.dueDate !== null,
      defaultHidden: true,
      width: 130,
    },
    {
      id: 'status',
      priority: 'high',
      label: `${FIELDS}.status`,
      value: (row) => row.status,
      sortable: true,
      width: 150,
    },
  ],
  ranges: EXPENSE_INTERVALS.map(({ id, kind, label }) => ({ id, kind, label })),
  picks: [
    { id: 'vendor', label: `${FIELDS}.vendorId` },
    { id: 'category', label: `${FIELDS}.categoryId` },
  ],
  filters: [
    {
      id: 'status',
      label: `${FIELDS}.status`,
      multiple: true,
      value: (row) => row.status,
      options: EXPENSE_STATUSES.map((status) => ({
        value: status,
        label: `expenses.statuses.${status}`,
        tone: EXPENSE_STATUS_TONES[status],
      })),
    },
    {
      id: 'paymentMethod',
      label: `${FIELDS}.paymentMethod`,
      multiple: true,
      value: (row) => row.paymentMethod,
      options: PAYMENT_METHODS.map((method) => ({
        value: method,
        label: `expenses.payment_methods.${method}`,
      })),
    },
    {
      // One answer: asking both is asking nothing.
      id: 'withheld',
      label: 'expenses.filters.withheld',
      anyLabel: 'list.filter_any_feminine',
      value: (row) => (row.withholdingAmount === null ? 'no' : 'yes'),
      options: WITHHELD.map((answer) => ({
        value: answer,
        label: `expenses.filters.withheld_${answer}`,
      })),
    },
  ],
};

const SORT_KEYS: Readonly<Record<string, ExpenseSortKey>> = {
  date: 'date',
  description: 'description',
  vendor: 'vendor',
  category: 'category',
  amountGross: 'amountGross',
  status: 'status',
};

/** What the API is asked for the page of expenses the list shows. */
export function expenseSearch(query: ListQuery): ExpenseSearch {
  const chosen = filterValues(query.filters['status']);
  const methods = filterValues(query.filters['paymentMethod']);
  const withheld = WITHHELD.find((answer) => answer === query.filters['withheld']) ?? null;
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    status: EXPENSE_STATUSES.filter((known) => chosen.includes(known)),
    paymentMethods: PAYMENT_METHODS.filter((known) => methods.includes(known)),
    withheld,
    vendorIds: idValues(query.filters['vendor']),
    categoryIds: idValues(query.filters['category']),
    intervals: rangeParams(query.filters, EXPENSE_INTERVALS),
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

/** A net amount above zero with no more decimals than the currency has, as the decimal field hands it over. */
export function amountPattern(scale: number): string {
  return scale > 0 ? `(0|[1-9][0-9]{0,10})([.][0-9]{1,${scale}})?` : '(0|[1-9][0-9]{0,10})';
}

/** Each category under its parents' names, in the order a tree reads. */
export function categoryLabels(
  categories: readonly Pick<ExpenseCategoryRow, 'id' | 'name' | 'parentId'>[],
): Map<string, string> {
  const byId = new Map(categories.map((category) => [category.id, category]));
  const label = (id: string): string => {
    const names: string[] = [];
    let current = byId.get(id);
    for (let depth = 0; current !== undefined && depth <= categories.length; depth++) {
      names.unshift(current.name);
      current = current.parentId === null ? undefined : byId.get(current.parentId);
    }
    return names.join(' › ');
  };
  return new Map(
    categories
      .map((category) => [category.id, label(category.id)] as const)
      .sort(([, a], [, b]) => a.localeCompare(b)),
  );
}

/**
 * The expense form, from what the company offers: its active categories and the rates on the net. The vendor is
 * asked for a few at a time instead (see the `pick` field below). The API works the tax and the gross out and
 * checks everything again. An expense keeps showing a category or tax it was filed with after that one stops
 * being offered, and its vendor whether or not that vendor is still active.
 */
export function expenseForm(
  options: ExpenseOptions,
  current: ExpenseChoices | null = null,
  vendors = true,
): FormDescriptor {
  const descriptor = offeredExpenseForm(options, vendors);
  if (current === null) return descriptor;
  // The vendor is not here: a picker shows what the expense itself says, so nothing has to be added to its options.
  const chosen: Record<string, readonly [string | null, string]> = {
    categoryId: [current.categoryId, current.categoryName ?? current.categoryId ?? ''],
  };
  return {
    ...descriptor,
    sections: descriptor.sections.map((section) => ({
      ...section,
      fields: section.fields.map((field) => {
        const [value, label] = chosen[field.id] ?? [null, ''];
        if (
          value === null ||
          !field.options ||
          field.options.some((option) => option.value === value)
        ) {
          return field;
        }
        return { ...field, options: [...field.options, { value, label }] };
      }),
    })),
  };
}

/** What an expense has chosen, named as it was when the expense was read. */
export type ExpenseChoices = Pick<
  ExpenseRow,
  'vendorId' | 'vendorName' | 'categoryId' | 'categoryName'
>;

/**
 * The form over what the company offers today; a deactivated vendor or category is not among it. What the bill
 * charges is not here: its lines are edited beside the form (`billGroup`).
 */
function offeredExpenseForm(options: ExpenseOptions, vendors: boolean): FormDescriptor {
  const none = (label: string): FieldOption => ({ value: '', label });
  return {
    id: 'expense',
    sections: [
      {
        id: 'expense',
        title: 'expenses.sections.expense',
        fields: [
          { id: 'date', label: `${FIELDS}.date`, kind: 'date', required: true },
          {
            id: 'reference',
            label: `${FIELDS}.reference`,
            kind: 'text',
            maxLength: 64,
            hint: 'expenses.form.reference_hint',
          },
          {
            id: 'description',
            label: `${FIELDS}.description`,
            kind: 'text',
            required: true,
            maxLength: 200,
            span: 2,
          },
          // Asked only while the Vendors module is on: expenses stand without it (docs/SPEC.md § 7, 2026-09-27 18:34).
          ...(vendors
            ? [
                {
                  // A book of suppliers is not a dropdown: the form asks for the few that match what is typed, and
                  // the page passes that search beside this descriptor (docs/SPEC.md § 7, 2026-09-17, ruling 3).
                  id: 'vendorId',
                  label: `${FIELDS}.vendorId`,
                  kind: 'pick' as const,
                  noneLabel: 'expenses.form.no_vendor',
                  noneFoundLabel: 'expenses.form.no_vendor_found',
                  hint: 'expenses.form.vendor_hint',
                },
              ]
            : []),
          {
            // A car park, a notary: someone paid whom no vendor record names, and never beside one.
            id: 'payee',
            label: `${FIELDS}.payee`,
            kind: 'text',
            maxLength: 160,
            hint: 'expenses.form.payee_hint',
            ...(vendors ? { visibleWhen: { field: 'vendorId', oneOf: [''] } } : {}),
          },
          {
            id: 'categoryId',
            label: `${FIELDS}.categoryId`,
            kind: 'select',
            options: [
              none('expenses.form.no_category'),
              ...[...categoryLabels(options.categories)].map(([value, label]) => ({
                value,
                label,
              })),
            ],
            hint: 'expenses.form.category_hint',
          },
        ],
      },
      {
        id: 'notes',
        title: 'expenses.sections.notes',
        fields: [
          {
            id: 'notes',
            label: `${FIELDS}.notes`,
            kind: 'textarea',
            maxLength: 5000,
            span: 2,
            hint: 'expenses.form.notes_hint',
          },
        ],
      },
    ],
  };
}

/** Each field at the expense's value; a new expense is dated today. */
export function expenseValues(row: ExpenseRow | null, today: string): FormValues {
  return {
    date: row?.date ?? today,
    reference: row?.reference ?? '',
    description: row?.description ?? '',
    vendorId: row?.vendorId ?? '',
    payee: row?.payee ?? '',
    categoryId: row?.categoryId ?? '',
    notes: row?.notes ?? '',
  };
}

/** What an expense says besides its bill. */
export type ExpenseHeader = Omit<ExpenseInput, keyof ExpenseBill>;

/**
 * The form's values as the API takes them: trimmed, an empty field as no value. A form with no vendor field (Vendors
 * switched off) sends back the vendor the draft keeps; a payee goes only without a vendor, since a hidden field keeps
 * what was typed in it.
 */
export function expenseInput(
  values: FormValues,
  keptVendorId: string | null = null,
): ExpenseHeader {
  const vendorId = 'vendorId' in values ? text(values['vendorId']) : keptVendorId;
  return {
    date: String(values['date'] ?? '').trim(),
    reference: text(values['reference']),
    description: String(values['description'] ?? '').trim(),
    vendorId,
    payee: vendorId === null ? text(values['payee']) : null,
    categoryId: text(values['categoryId']),
    notes: text(values['notes']),
  };
}

/** The supplier's bill as the API takes it: its lines, the fixed charges it carries, the amounts it prints. */
export type ExpenseBill = Pick<ExpenseInput, 'lines' | 'documentTaxComponentIds' | 'printedTaxes'>;

export interface BillLineControls {
  amountNet: FormControl<string>;
  taxComponentIds: FormControl<string[]>;
}
export type BillLineGroup = FormGroup<BillLineControls>;

export interface BillControls {
  lines: FormArray<BillLineGroup>;
  /** The fixed charges ticked, such as the stamp. */
  charges: FormControl<string[]>;
  /** By a rated tax's code, the amount the bill prints for it; empty where it prints what twes-in works out. */
  printed: FormGroup<Record<string, FormControl<string>>>;
}
export type BillGroup = FormGroup<BillControls>;
export type BillValue = ReturnType<BillGroup['getRawValue']>;

/** What a draft's bill holds, as the API answered it. */
export type SavedBill = Pick<
  ExpenseRow,
  'lines' | 'documentTaxComponentIds' | 'printedTaxes' | 'taxes'
>;

/** The whole value matches, so a stray space around a number is not a mistake. */
function matching(pattern: string): ValidatorFn {
  const whole = new RegExp(`^(${pattern})$`);
  return (control) => {
    const value = String(control.value ?? '').trim();
    return value === '' || whole.test(value) ? null : { pattern: true };
  };
}

const netGiven: ValidatorFn = (control) => {
  const value = String(control.value ?? '').trim();
  if (value === '') return { required: true };
  return /^[0.]+$/.test(value) ? { positive: true } : null;
};

/** A line carries one VAT rate beside any levy taxed by it, as FODEC is: a bill at two rates has a line for each. */
function oneVat(options: ExpenseOptions): ValidatorFn {
  const vat = new Set(options.taxes.filter((tax) => !tax.entersVatBase).map((tax) => tax.id));
  return (control) => {
    const ids = (control.value ?? []) as readonly string[];
    return ids.filter((id) => vat.has(id)).length > 1 ? { oneVat: true } : null;
  };
}

/** One line of the bill at its values; a new one is a net to give, untaxed until a rate is chosen. */
export function billLineGroup(line: ExpenseLine | null, options: ExpenseOptions): BillLineGroup {
  return new FormGroup<BillLineControls>({
    amountNet: new FormControl(line?.amountNet ?? '', {
      nonNullable: true,
      validators: [netGiven, matching(amountPattern(options.currencyScale))],
    }),
    taxComponentIds: new FormControl<string[]>([...(line?.taxComponentIds ?? [])], {
      nonNullable: true,
      validators: [oneVat(options)],
    }),
  });
}

/**
 * The bill's controls at a draft's values. A new expense starts on one line with no fixed charge ticked: the stamp is
 * on a bill or not, and nobody is told it is. A printed amount is asked for each rate the saved draft charges, since
 * what it works out to is known only once the API has worked it out.
 */
export function billGroup(row: SavedBill | null, options: ExpenseOptions): BillGroup {
  const lines = (row?.lines ?? []).map((line) => billLineGroup(line, options));
  const given = new Map((row?.printedTaxes ?? []).map((tax) => [tax.code, tax.amount]));
  const printed: Record<string, FormControl<string>> = {};
  for (const tax of row?.taxes ?? []) {
    if (tax.rate === null) continue;
    printed[tax.code] = new FormControl(given.get(tax.code) ?? '', {
      nonNullable: true,
      validators: [matching(amountPattern(options.currencyScale))],
    });
  }
  return new FormGroup<BillControls>({
    lines: new FormArray(lines.length > 0 ? lines : [billLineGroup(null, options)]),
    charges: new FormControl<string[]>([...(row?.documentTaxComponentIds ?? [])], {
      nonNullable: true,
    }),
    printed: new FormGroup(printed),
  });
}

/** Each rate's code by its id: the company's, and those a draft still carries though they are no longer offered. */
function taxCodes(options: ExpenseOptions, row: SavedBill | null): Map<string, string> {
  const codes = new Map<string, string>();
  for (const line of row?.lines ?? []) {
    line.taxComponentIds.forEach((id, at) => {
      const code = line.taxCodes?.[at];
      if (code !== undefined) codes.set(id, code);
    });
  }
  for (const tax of options.taxes) codes.set(tax.id, tax.code);
  return codes;
}

/**
 * The bill as the API takes it: each net trimmed, and a printed amount only for a rate some line still carries, so
 * one left behind by a removed line is not sent.
 */
export function billInput(
  bill: BillValue,
  options: ExpenseOptions,
  row: SavedBill | null,
): ExpenseBill {
  const codes = taxCodes(options, row);
  const carried = new Set(
    bill.lines.flatMap((line) => line.taxComponentIds.map((id) => codes.get(id) ?? id)),
  );
  const printedTaxes: PrintedTax[] = Object.entries(bill.printed).flatMap(([code, amount]) => {
    const given = amount.trim();
    return given !== '' && carried.has(code) ? [{ code, amount: given }] : [];
  });
  return {
    lines: bill.lines.map((line) => ({
      amountNet: line.amountNet.trim(),
      taxComponentIds: [...line.taxComponentIds],
    })),
    documentTaxComponentIds: [...bill.charges],
    printedTaxes,
  };
}

/** The rates a line may carry, named with their rate; one a draft carries stays offered so it can be taken off. */
export function billTaxOptions(options: ExpenseOptions, row: SavedBill | null): FieldOption[] {
  const offered = options.taxes.map((tax) => ({
    value: tax.id,
    label: taxLabel(tax.name, tax.rate),
  }));
  const known = new Set(offered.map((option) => option.value));
  const rates = new Map((row?.taxes ?? []).map((tax) => [tax.code, tax.rate]));
  const kept: FieldOption[] = [];
  for (const [id, code] of taxCodes({ ...options, taxes: [] }, row)) {
    if (known.has(id)) continue;
    known.add(id);
    const rate = rates.get(code);
    kept.push({ value: id, label: rate == null ? code : taxLabel(code, rate) });
  }
  return [...offered, ...kept];
}

/** A rate a line carries, as `netFromGross` reads it: percent with up to three decimals. */
export interface LineRate {
  rate: string;
  /** A levy such as FODEC, taxed by the VAT beside it. */
  entersVatBase: boolean;
}

/** Thousandths of a percent: a rate's fraction is this over 100000. */
const RATE = /^\d{1,3}(\.\d{1,3})?$/;

function thousandths(rate: string): bigint | null {
  if (!RATE.test(rate)) return null;
  const [whole, fraction = ''] = rate.split('.');
  return BigInt(whole! + fraction.padEnd(3, '0'));
}

/** a / b rounded half away from zero, for a and b above zero: the API's `Decimal::round`. */
function halfUp(a: bigint, b: bigint): bigint {
  return (2n * a + b) / (2n * b);
}

/**
 * The net a line has when what it comes to with its taxes is `gross`, counted exactly in the currency's smallest unit,
 * never through a float. Each levy is taken on the net and the VAT on the net and the levies, each rounded half away
 * from zero as the API rounds them, and the net chosen is the one whose taxes give back the very figure typed; where
 * no net does (0.003 at 19 %), the nearest is given and `exact` says so, and the bill's printed amounts take the
 * supplier's rounding. Null for what is not an amount above zero at the currency's scale, or a rate not understood.
 */
export function netFromGross(
  gross: string,
  rates: readonly LineRate[],
  scale: number,
): { net: string; exact: boolean } | null {
  const total = units(gross, scale);
  const taxes = lineTaxes(rates);
  if (total === null || total <= 0n || taxes === null) return null;
  const nearest = halfUp(
    total * HUNDRED * HUNDRED,
    (HUNDRED + taxes.levies.reduce((sum, rate) => sum + rate, 0n)) * (HUNDRED + taxes.vat),
  );
  const found = [0n, -1n, 1n, -2n, 2n, -3n, 3n]
    .map((step) => nearest + step)
    .find((net) => net > 0n && comesTo(net, taxes) === total);
  return {
    net: decimal(found ?? (nearest > 0n ? nearest : 1n), scale),
    exact: found !== undefined,
  };
}

/** What a net comes to with its taxes, each rounded as the API rounds it; null where either is not understood. */
export function grossFromNet(
  net: string,
  rates: readonly LineRate[],
  scale: number,
): string | null {
  const amount = units(net, scale);
  const taxes = lineTaxes(rates);
  return amount === null || taxes === null ? null : decimal(comesTo(amount, taxes), scale);
}

/**
 * The net of a line typed with its taxes. A one-line bill is typed as the receipt's total, so the fixed charges ticked,
 * such as the stamp, are taken out first; null where the total does not exceed them.
 */
export function netOfLine(
  gross: string,
  rates: readonly LineRate[],
  charges: readonly string[],
  scale: number,
): { net: string; exact: boolean } | null {
  const total = units(gross, scale);
  const fixed = sumOf(charges, scale);
  if (total === null || fixed === null || total <= fixed) return null;
  return netFromGross(decimal(total - fixed, scale), rates, scale);
}

/** What a line comes to with its taxes and, on a one-line bill, the fixed charges ticked: `netOfLine` reversed. */
export function grossOfLine(
  net: string,
  rates: readonly LineRate[],
  charges: readonly string[],
  scale: number,
): string | null {
  const gross = grossFromNet(net, rates, scale);
  const fixed = sumOf(charges, scale);
  return gross === null || fixed === null ? null : decimal(units(gross, scale)! + fixed, scale);
}

function sumOf(amounts: readonly string[], scale: number): bigint | null {
  let sum = 0n;
  for (const amount of amounts) {
    const each = units(amount, scale);
    if (each === null) return null;
    sum += each;
  }
  return sum;
}

const HUNDRED = 100000n;

/** The levies a line's net carries and the VAT rate on the net and the levies, in thousandths of a percent. */
function lineTaxes(rates: readonly LineRate[]): { levies: bigint[]; vat: bigint } | null {
  const levies: bigint[] = [];
  let vat = 0n;
  for (const each of rates) {
    const rate = thousandths(each.rate);
    if (rate === null) return null;
    if (each.entersVatBase) levies.push(rate);
    else vat += rate;
  }
  return { levies, vat };
}

function comesTo(net: bigint, { levies, vat }: { levies: bigint[]; vat: bigint }): bigint {
  const levied = levies.reduce((sum, rate) => sum + halfUp(net * rate, HUNDRED), 0n);
  return net + levied + halfUp((net + levied) * vat, HUNDRED);
}

/** An amount in the currency's smallest unit; null for what is not one at its scale. */
function units(text: string, scale: number): bigint | null {
  const typed = text.trim();
  if (!new RegExp(`^${amountPattern(scale)}$`).test(typed)) return null;
  const [whole, fraction = ''] = typed.split('.');
  return BigInt(whole! + fraction.padEnd(scale, '0'));
}

function decimal(amount: bigint, scale: number): string {
  if (scale === 0) return amount.toString();
  const unit = 10n ** BigInt(scale);
  return `${amount / unit}.${(amount % unit).toString().padStart(scale, '0')}`;
}

/** As many lines as the API takes on one bill. */
export const BILL_LINES_MAX = 50;

/** A tax the bill charges as the figures name it: a rate by its name and rate, a fixed charge by its name. */
export function billTaxName(options: ExpenseOptions, tax: ExpenseTax): string {
  if (tax.rate === null) {
    return options.charges.find((charge) => charge.code === tax.code)?.name ?? tax.code;
  }
  const offered = options.taxes.find((each) => each.code === tax.code);
  return taxLabel(offered?.name ?? tax.code, tax.rate);
}

/** A fixed charge a bill may carry, with what it charges; null for one no longer offered, whose amount is not known. */
export interface BillChargeOption {
  id: string;
  label: string;
  amount: string | null;
}

export function billChargeOptions(
  options: ExpenseOptions,
  row: SavedBill | null,
): BillChargeOption[] {
  const offered = options.charges.map((charge) => ({
    id: charge.id,
    label: charge.name,
    amount: charge.amount,
  }));
  const known = new Set(offered.map((option) => option.id));
  const kept = (row?.documentTaxComponentIds ?? [])
    .filter((id) => !known.has(id))
    .map((id) => ({ id, label: 'expenses.bill.charge_retired', amount: null }));
  return [...offered, ...kept];
}

/** How and when a recorded expense was paid; `say` writes the hint's figures in the screen's formats. */
export function paymentForm(
  options: ExpenseOptions,
  say: {
    amount(value: string, scale: number | null): string;
    decimal(value: string): string;
  },
): FormDescriptor {
  return {
    id: 'expense-payment',
    sections: [
      {
        id: 'payment',
        title: 'expenses.sections.payment',
        fields: [
          {
            id: 'paymentMethod',
            label: `${FIELDS}.paymentMethod`,
            kind: 'select',
            required: true,
            options: options.paymentMethods.map((method) => ({
              value: method,
              label: `expenses.payment_methods.${method}`,
            })),
          },
          { id: 'paidOn', label: `${FIELDS}.paidOn`, kind: 'date', required: true },
          ...withholdingField(options, say),
          ...(options.withholdingOperationCodes.length === 0
            ? []
            : [{ ...operationField(options), hint: 'expenses.form.tej_hint' }]),
        ],
      },
    ],
  };
}

/**
 * The rate a payment withholds, asked only where the company has a withholding on a total: its hint says the company's
 * own rate and threshold, which it can revise in Taxes, never figures fixed in its words. With several, which one
 * applies depends on the supplier, and the hint says so.
 */
function withholdingField(
  options: ExpenseOptions,
  say: Parameters<typeof paymentForm>[1],
): FormField[] {
  const [only, ...more] = options.withholdings;
  if (only === undefined) return [];
  // The API writes three decimals: 1.000 reads « 1 », 1.500 « 1,5 », 10 stays 10.
  const rate = say.decimal(only.rate.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''));
  const hint: Pick<FormField, 'hint' | 'hintParams'> =
    more.length > 0
      ? { hint: 'expenses.form.withholding_hint_several' }
      : only.threshold === null
        ? { hint: 'expenses.form.withholding_hint_always', hintParams: { rate } }
        : {
            hint: 'expenses.form.withholding_hint_from',
            hintParams: {
              rate,
              threshold: `${say.amount(only.threshold, options.currencyScale)} ${options.currency}`,
            },
          };
  return [
    {
      id: 'withholdingRate',
      label: `${FIELDS}.withholdingRate`,
      kind: 'decimal',
      maxLength: 7,
      pattern: '(0|[1-9][0-9]{0,2})([.,][0-9]{1,3})?',
      patternError: 'import.rejections.invalid_withholding_rate',
      ...hint,
    },
  ];
}

/** The TEJ operation of a paid expense, given afterwards (docs/SPEC.md § 7, 2026-09-26 00:22). */
export function classifyForm(options: ExpenseOptions): FormDescriptor {
  return {
    id: 'expense-tej',
    sections: [
      {
        id: 'tej',
        title: 'expenses.sections.tej',
        fields: [{ ...operationField(options), required: true, span: 2 }],
      },
    ],
  };
}

/** The administration's own list, its labels as published: a code is chosen, never worked out from the rate. */
function operationField(options: ExpenseOptions): FormField {
  return {
    id: 'withholdingOperationCode',
    label: `${FIELDS}.withholdingOperationCode`,
    kind: 'select',
    options: options.withholdingOperationCodes.map((operation) => ({
      value: operation.code,
      label: `${operation.code} — ${operation.label}`,
    })),
  };
}

/**
 * A payment dated today, proposing the withholding the API suggests for this expense (docs/SPEC.md § 7, 2026-09-24
 * 11:40, RPT-09): the preset's rate once the expense reaches its threshold, as a person writes it, else none.
 */
export function paymentValues(
  today: string,
  suggestedWithholdingRate: string | null = null,
): FormValues {
  return {
    paymentMethod: 'transfer',
    paidOn: today,
    withholdingRate: plainRate(suggestedWithholdingRate),
  };
}

/** "1.000" as a person writes a rate: "1". */
function plainRate(rate: string | null): string {
  if (rate === null) return '';
  return rate.includes('.') ? rate.replace(/0+$/, '').replace(/\.$/, '') : rate;
}

export function paymentInput(values: FormValues): ExpensePayment {
  return {
    paymentMethod: String(values['paymentMethod'] ?? 'transfer') as PaymentMethod,
    paidOn: String(values['paidOn'] ?? '').trim(),
    // Always said: an empty field withholds nothing, rather than letting the API apply its default.
    withholdingRate: text(values['withholdingRate'])?.replace(',', '.') ?? '0',
    ...operation(values),
  };
}

/** Sent only when chosen: outside Tunisia the API refuses any code at all. */
function operation(values: FormValues): { withholdingOperationCode?: string } {
  const code = text(values['withholdingOperationCode']);
  return code === null ? {} : { withholdingOperationCode: code };
}

/**
 * The months a TEJ file may be asked for, `YYYY-MM`, the latest first: the twelve that are over by `today`, since a
 * month is declared once it has ended (docs/SPEC.md § 7, 2026-09-26).
 */
export function tejMonths(today: string): string[] {
  const year = Number(today.slice(0, 4));
  const month = Number(today.slice(5, 7));
  return Array.from({ length: 12 }, (_, back) => {
    const index = year * 12 + (month - 1) - (back + 1);
    return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`;
  });
}

/** A category as its list shows it: with its path in the tree. */
export type ExpenseCategoryListRow = ExpenseCategoryRow & { path: string };

export function categoryListRows(
  categories: readonly ExpenseCategoryRow[],
): ExpenseCategoryListRow[] {
  const labels = categoryLabels(categories);
  return categories.map((category) => ({
    ...category,
    path: labels.get(category.id) ?? category.name,
  }));
}

export const EXPENSE_CATEGORIES_LIST: ListDescriptor<ExpenseCategoryListRow> = {
  id: 'expense-categories',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'path', direction: 'asc' },
  columns: [
    {
      id: 'path',
      label: `${CATEGORY_FIELDS}.name`,
      value: (row) => row.path,
      sortable: true,
      filterable: true,
      hideable: false,
      wraps: true,
    },
    {
      id: 'status',
      label: `${CATEGORY_FIELDS}.isActive`,
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      sortable: true,
      width: 130,
    },
  ],
  filters: [
    {
      id: 'status',
      label: `${CATEGORY_FIELDS}.isActive`,
      anyLabel: 'list.filter_any_feminine',
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      options: ['active', 'inactive'].map((status) => ({
        value: status,
        label: `expenses.categories.statuses.${status}`,
      })),
    },
  ],
};

/** The category form; a category is never offered as the parent of itself or of one of its own subcategories. */
export function categoryForm(
  categories: readonly ExpenseCategoryRow[],
  editing: ExpenseCategoryRow | null,
): FormDescriptor {
  const byId = new Map(categories.map((category) => [category.id, category]));
  const below = (category: ExpenseCategoryRow): boolean => {
    if (editing === null) return false;
    let current: ExpenseCategoryRow | undefined = category;
    for (let depth = 0; current !== undefined && depth <= categories.length; depth++) {
      if (current.id === editing.id) return true;
      current = current.parentId === null ? undefined : byId.get(current.parentId);
    }
    return false;
  };
  const parents = [...categoryLabels(categories)]
    .filter(([id]) => {
      const category = byId.get(id);
      return category !== undefined && !below(category);
    })
    .map(([value, label]) => ({ value, label }));

  return {
    id: 'expense-category',
    sections: [
      {
        id: 'category',
        title: 'expenses.categories.section',
        fields: [
          {
            id: 'name',
            label: `${CATEGORY_FIELDS}.name`,
            kind: 'text',
            required: true,
            maxLength: 120,
          },
          {
            id: 'parentId',
            label: `${CATEGORY_FIELDS}.parentId`,
            kind: 'select',
            options: [{ value: '', label: 'expenses.categories.top_level' }, ...parents],
          },
          {
            id: 'isActive',
            label: `${CATEGORY_FIELDS}.isActive`,
            kind: 'checkbox',
            hint: 'expenses.categories.active_hint',
          },
        ],
      },
    ],
  };
}

export function categoryValues(row: ExpenseCategoryRow | null): FormValues {
  return { name: row?.name ?? '', parentId: row?.parentId ?? '', isActive: row?.isActive ?? true };
}

export function categoryInput(values: FormValues): ExpenseCategoryInput {
  return {
    name: String(values['name'] ?? '').trim(),
    parentId: text(values['parentId']),
    isActive: values['isActive'] === true,
  };
}

function text(value: FieldValue | undefined): string | null {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
}

/** A tax as the select names it: its name, then its rate where the name does not already say it (« TVA 19 % »). */
function taxLabel(name: string, rate: string): string {
  const shown = String(Number(rate));
  const bare = name.replace(/\s/g, '');
  const said = [shown, shown.replace('.', ',')].some((each) => bare.includes(`${each}%`));
  return said ? name : `${name} (${shown}\u00a0%)`;
}

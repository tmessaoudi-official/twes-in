// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormArray, FormControl, FormGroup, type ValidatorFn, Validators } from '@angular/forms';
import type { FieldValue, FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import { atScale } from '../shared/i18n/format';
import { filterValues, idValues, rangeParams } from '../shared/list/list-filters';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import type { PickOption } from '../shared/form/pick-field';
import { LOT_CODE_PATTERN, type ProductTracking } from '../products/products-types';
import { type LineStock, type OnHand, stockOfLines } from '../shared/documents/line-stock';
import {
  type CustomerOption,
  INVOICE_SHOWN_STATUSES,
  type InvoiceInput,
  type InvoiceLine,
  type InvoiceOptions,
  type InvoiceRow,
  OPERATION_CATEGORIES,
  type InvoiceSearch,
  type InvoiceShownStatus,
  type InvoiceSortKey,
  PAYMENT_METHODS,
  type ProductOption,
  type PaymentInput,
  type InvoiceKind,
  kindOf,
  type PaymentMethod,
  type TaxFamily,
  type TaxOption,
  INVOICE_STATUS_TONES,
} from './invoices-types';

const FIELDS = 'invoices.fields';
/** The API's longest line description. */
const DESCRIPTION_MAX = 5000;
/** A quantity as the API takes it: at most ten digits, then at most three decimals. */
const QUANTITY_PATTERN = /^(0|[1-9][0-9]{0,9})([.][0-9]{1,3})?$/;
/** A price as the API takes it: at most ten digits, then at most four decimals. */
const PRICE_PATTERN = /^(0|[1-9][0-9]{0,9})([.][0-9]{1,4})?$/;
/** A discount rate: 0 to 100, at most three decimals. */
const RATE_PATTERN = /^(100([.]0{1,3})?|[1-9]?[0-9]([.][0-9]{1,3})?)$/;
/** An amount as the API takes it: at most twelve digits, then at most three decimals. */
const AMOUNT_PATTERN = '(0|[1-9][0-9]{0,11})([.][0-9]{1,3})?';

/** A quantity without the zeros the API pads it with, "2.000" as "2", so a unit counting none accepts it back. */
function plainQuantity(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

/**
 * What a list shows for a document on the company's day `today` (YYYY-MM-DD): an issued or partly paid invoice whose
 * due day has passed is overdue (docs/SPEC.md § 7, 2026-09-16); every other document shows its status.
 */
export function shownStatus(invoice: InvoiceRow, today: string): InvoiceShownStatus {
  const open = invoice.status === 'issued' || invoice.status === 'partially_paid';
  return invoice.type === 'invoice' && open && invoice.dueDate !== null && invoice.dueDate < today
    ? 'overdue'
    : invoice.status;
}

/**
 * Whether a document still has money to collect: an issued or partly paid invoice whose amount due is not zero. A
 * credit note is paid back, never collected. The record page's payment button and the sheet's « Encaisser » both ask
 * it, so the two cannot offer a payment the other would not.
 */
export function stillOwed(invoice: InvoiceRow | null | undefined): boolean {
  if (!invoice || invoice.type === 'credit_note') return false;
  const open = invoice.status === 'issued' || invoice.status === 'partially_paid';
  return open && !/^-?[0.]+$/.test(invoice.amountDue);
}

/** Whether money paid beyond this document may be kept to the customer's credit: an invoice paid in full. */
export function paidInFull(invoice: InvoiceRow | null | undefined): boolean {
  if (!invoice || invoice.type === 'credit_note') return false;
  return invoice.status === 'paid' && /^-?[0.]+$/.test(invoice.amountDue);
}

/** Whole days from the due day to `today` (both YYYY-MM-DD): positive once late, negative while still to come. */
export function daysLate(dueDate: string, today: string): number {
  return Math.round(
    (Date.parse(`${today}T00:00:00Z`) - Date.parse(`${dueDate}T00:00:00Z`)) / 86_400_000,
  );
}

/** How much of a document's total is paid, in whole percent from 0 to 100: a drawing proportion, never a figure read. */
export function paidShare(invoice: InvoiceRow): number {
  const total = Number(invoice.total);
  if (!(total > 0)) return 0;
  return Math.min(100, Math.max(0, Math.round((Number(invoice.amountPaid) / total) * 100)));
}

const INVOICE_KINDS: readonly InvoiceKind[] = ['invoice', 'deposit', 'credit_note'];
/** The intervals the « Filtres » panel offers, in the order it draws them. */
const INVOICE_INTERVALS: readonly { id: string; kind: 'day' | 'amount'; label: string }[] = [
  { id: 'issueDate', kind: 'day', label: `${FIELDS}.issueDate` },
  { id: 'dueDate', kind: 'day', label: `${FIELDS}.dueDate` },
  { id: 'totalGross', kind: 'amount', label: `${FIELDS}.total` },
  { id: 'amountDue', kind: 'amount', label: `${FIELDS}.amountDue` },
];

const SORT_KEYS: Readonly<Record<string, InvoiceSortKey>> = {
  number: 'number',
  customer: 'customer',
  issueDate: 'issueDate',
  dueDate: 'dueDate',
  status: 'status',
};

/**
 * What the API is asked for the page of documents the list shows. The status filter carries `overdue` straight
 * through: the API answers it against the company's own day, by the rule this file's shownStatus reads on screen.
 */
export function invoiceSearch(query: ListQuery): InvoiceSearch {
  const status = INVOICE_SHOWN_STATUSES.filter((known) =>
    filterValues(query.filters['status']).includes(known),
  );
  const documentType = INVOICE_KINDS.filter((known) =>
    filterValues(query.filters['type']).includes(known),
  );
  const intervals = rangeParams(query.filters, INVOICE_INTERVALS);
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    status,
    documentType,
    customerIds: idValues(query.filters['customer']),
    intervals,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

/** A document as the list shows it: with its customer's name and the status shown for it. */
export type InvoiceListRow = InvoiceRow & {
  customer: string;
  shown: InvoiceShownStatus;
  kind: InvoiceKind;
};

/**
 * An issued document names the customer it was issued to; a draft, which has recorded nobody yet, names the customer
 * as the company has it today. Both come off the document itself, so a list never reads the book of customers.
 */
export function invoiceListRows(invoices: readonly InvoiceRow[], today: string): InvoiceListRow[] {
  return invoices.map((invoice) => ({
    ...invoice,
    customer: invoice.recordedCustomerName ?? invoice.customerName,
    shown: shownStatus(invoice, today),
    kind: kindOf(invoice),
  }));
}

export const INVOICES_LIST: ListDescriptor<InvoiceListRow> = {
  id: 'invoices',
  rowId: (row) => row.id,
  // The number opens the invoice, so a middle click and a copied address work (design review finding 1). A draft
  // has no number and its cell shows the word instead, which is what the link then reads.
  link: (row) => ['/invoices', row.id],
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'issueDate', direction: 'desc' },
  columns: [
    {
      id: 'number',
      label: `${FIELDS}.number`,
      value: (row) => row.number ?? '',
      sortable: true,
      filterable: true,
      hideable: false,
      width: 170,
    },
    {
      id: 'customer',
      label: `${FIELDS}.customer`,
      value: (row) => row.customer,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'issueDate',
      label: `${FIELDS}.issueDate`,
      value: (row) => row.issueDate ?? '',
      sortable: true,
      width: 130,
    },
    {
      id: 'dueDate',
      label: `${FIELDS}.dueDate`,
      value: (row) => row.dueDate ?? '',
      // A credit note is the company's to pay back, never due by a day.
      shown: (row) => row.type === 'invoice' && row.dueDate !== null,
      sortable: true,
      width: 130,
    },
    {
      id: 'total',
      label: `${FIELDS}.total`,
      value: (row) => row.total,
      align: 'end',
      width: 150,
    },
    {
      id: 'amountDue',
      label: `${FIELDS}.amountDue`,
      value: (row) => row.amountDue,
      shown: (row) =>
        row.type === 'invoice' && row.status !== 'draft' && row.status !== 'cancelled',
      align: 'end',
      width: 150,
    },
    {
      id: 'status',
      label: `${FIELDS}.status`,
      value: (row) => row.shown,
      sortable: true,
      width: 170,
    },
  ],
  ranges: INVOICE_INTERVALS.map(({ id, kind, label }) => ({ id, kind, label })),
  picks: [{ id: 'customer', label: `${FIELDS}.customer` }],
  filters: [
    {
      id: 'status',
      label: `${FIELDS}.status`,
      multiple: true,
      value: (row) => row.shown,
      options: INVOICE_SHOWN_STATUSES.map((status) => ({
        value: status,
        label: `invoices.statuses.${status}`,
        tone: INVOICE_STATUS_TONES[status],
      })),
    },
    {
      id: 'type',
      label: `${FIELDS}.type`,
      multiple: true,
      value: (row) => row.kind,
      options: INVOICE_KINDS.map((kind) => ({ value: kind, label: `invoices.types.${kind}` })),
    },
  ],
};

const section = (id: string, fields: FormField[]): FormDescriptor['sections'][number] => ({
  id,
  title: `invoices.sections.${id}`,
  fields,
});

/**
 * The document's header: its establishment among what the company offers, keeping the one a document already names
 * when the company no longer offers it, then its dates, terms, discount and what is printed. The API checks it all
 * again.
 *
 * The CUSTOMER is not a field here: a company's book is not a dropdown, so the page asks for it through a picker
 * beside this form (docs/SPEC.md § 7, 2026-09-17, ruling 3). A descriptor is data — it is stringified to tell one
 * form from another — and a picker needs a function to search with, which is why it cannot live in one.
 */
export function invoiceForm(
  options: InvoiceOptions,
  current: InvoiceRow | null = null,
): FormDescriptor {
  const establishments = options.establishments.map((establishment) => ({
    value: establishment.id,
    label: `${establishment.code} · ${establishment.name}`,
  }));
  const establishmentId = current?.establishmentId ?? null;
  if (
    establishmentId !== null &&
    !establishments.some((option) => option.value === establishmentId)
  ) {
    establishments.push({ value: establishmentId, label: establishmentId });
  }

  return {
    id: 'invoice',
    sections: [
      section('parties', [
        {
          id: 'establishmentId',
          label: `${FIELDS}.establishmentId`,
          kind: 'select',
          required: true,
          options: establishments,
        },
        {
          id: 'customerReference',
          label: `${FIELDS}.customerReference`,
          kind: 'text',
          maxLength: 64,
          hint: 'invoices.form.reference_hint',
        },
      ]),
      section('terms', [
        {
          id: 'supplyDate',
          label: `${FIELDS}.supplyDate`,
          kind: 'date',
          hint: 'invoices.form.supply_date_hint',
        },
        {
          id: 'paymentTermsDays',
          label: `${FIELDS}.paymentTermsDays`,
          kind: 'text',
          maxLength: 3,
          pattern: '(0|[1-9][0-9]?|[12][0-9]{2}|3[0-5][0-9]|36[0-5])',
          hint: 'invoices.form.terms_hint',
        },
        {
          id: 'discountAmount',
          label: `${FIELDS}.discountAmount`,
          kind: 'decimal',
          maxLength: 16,
          pattern: AMOUNT_PATTERN,
          hint: 'invoices.form.discount_hint',
        },
        // What the operations are, where the law asks (France): « from the lines » leaves it to the products sold.
        ...(options.operationCategory
          ? [
              {
                id: 'operationCategory',
                label: `${FIELDS}.operationCategory`,
                kind: 'select',
                options: [
                  { value: '', label: 'invoices.operations.from_lines' },
                  ...OPERATION_CATEGORIES.map((category) => ({
                    value: category,
                    label: `invoices.operations.${category}`,
                  })),
                ],
                // A credit note states the operations of the invoice it corrects, which its own lines cannot change.
                ...(current?.type === 'credit_note'
                  ? { readOnly: true, hint: 'invoices.form.operations_credit_note_hint' }
                  : { hint: 'invoices.form.operations_hint' }),
              } satisfies FormField,
            ]
          : []),
      ]),
      section('notes', [
        {
          id: 'notesPrinted',
          label: `${FIELDS}.notesPrinted`,
          kind: 'textarea',
          maxLength: 5000,
          span: 2,
          hint: 'invoices.form.printed_hint',
        },
        {
          id: 'notesInternal',
          label: `${FIELDS}.notesInternal`,
          kind: 'textarea',
          maxLength: 5000,
          span: 2,
          hint: 'invoices.form.internal_hint',
        },
      ]),
    ],
  };
}

/** Each header field at the document's value; a new invoice starts at the company's default establishment. */
export function invoiceValues(row: InvoiceRow | null, options: InvoiceOptions): FormValues {
  const establishment =
    options.establishments.find((each) => each.isDefault) ?? options.establishments[0];
  return {
    establishmentId: row?.establishmentId ?? establishment?.id ?? '',
    customerReference: row?.customerReference ?? '',
    supplyDate: row?.supplyDate ?? '',
    paymentTermsDays:
      row?.paymentTermsDays === null || row === null ? '' : String(row.paymentTermsDays),
    discountAmount: row?.discountAmount ?? '',
    notesPrinted: row?.notesPrinted ?? '',
    notesInternal: row?.notesInternal ?? '',
    operationCategory: row?.operationCategory ?? '',
  };
}

const DOCUMENT_KINDS: readonly TaxOption['kind'][] = ['fixed_document', 'withholding_total'];

const isDocumentTax = (tax: TaxOption): boolean => DOCUMENT_KINDS.includes(tax.kind);

const excludedFor = (customer: CustomerOption | null): readonly TaxFamily[] =>
  customer?.excludedFamilies ?? [];

/**
 * What a new document is charged besides its lines, as the API would charge it when none are named: the company's
 * default fixed charges and withholdings with the customer's own, less the families its regime refuses. The customer
 * is the row the picker answered, which carries both — nothing is looked up in a list here.
 */
export function defaultDocumentTaxes(
  options: InvoiceOptions,
  customer: CustomerOption | null,
): string[] {
  const excluded = excludedFor(customer);
  const chosen = new Set([
    ...options.taxes.filter((tax) => isDocumentTax(tax) && tax.isDefault).map((tax) => tax.id),
    ...(customer?.defaultTaxComponentIds ?? []),
  ]);
  return options.taxes
    .filter((tax) => isDocumentTax(tax) && chosen.has(tax.id) && !excluded.includes(tax.family))
    .map((tax) => tax.id);
}

/** The fixed charges and withholdings a document may carry: those the customer's regime allows, and those it has. */
export function documentTaxOptions(
  options: InvoiceOptions,
  customer: CustomerOption | null,
  chosen: readonly string[],
): TaxOption[] {
  const excluded = excludedFor(customer);
  const documentTaxes = options.taxes.filter(isDocumentTax);
  return [
    ...documentTaxes.filter((tax) => !excluded.includes(tax.family)),
    ...documentTaxes.filter((tax) => excluded.includes(tax.family) && chosen.includes(tax.id)),
  ];
}

export interface LineControls {
  productId: FormControl<string>;
  /** The product's own words, carried on the line so the picker shows it without reading the catalogue. */
  productReference: FormControl<string>;
  productName: FormControl<string>;
  description: FormControl<string>;
  quantity: FormControl<string>;
  unitId: FormControl<string>;
  unitPriceNet: FormControl<string>;
  discountRate: FormControl<string>;
  /** The discount as an amount off the whole line, asked in place of the rate when `discountKind` says so. */
  discountAmount: FormControl<string>;
  /** Whether the line is discounted by a rate or by an amount: the API takes one or the other, never both. */
  discountKind: FormControl<DiscountKind>;
  taxComponentIds: FormControl<string[]>;
  sourceDeliveryNoteLineId: FormControl<string>;
  /** The lot or serial sold; asked only for a product tracked by one (docs/SPEC.md § 7, 2026-09-24 12:40 row 5). */
  lotCode: FormControl<string>;
  /** On a credit note: whether the line's goods come back to stock when it is issued. */
  returned: FormControl<boolean>;
  /** The deposit invoice the line gives back, which the API writes from the deposit; '' for any other line. */
  deductsInvoiceId: FormControl<string>;
  /** The title of the section the line opens, carried as read: this screen does not edit it yet. */
  section: FormControl<string>;
  /** How the line's product is tracked, which decides whether the lot is asked; '' for a line naming no product. */
  productTracking: FormControl<ProductTracking | ''>;
}

export type DiscountKind = 'rate' | 'amount';

export type LineGroup = FormGroup<LineControls>;
export type LinesArray = FormArray<LineGroup>;

/**
 * Whether a line holds what its figures are worked out from: a quantity, a unit, a price and a discount the API would
 * take. Its description is not among them, and a line not ready yet leaves the others' figures alone.
 */
export function figuresReady(line: LineGroup): boolean {
  const { quantity, unitId, unitPriceNet, discountRate, discountAmount } = line.controls;
  return (
    [quantity, unitId, unitPriceNet, discountRate, discountAmount].every(
      (control) => !control.invalid,
    ) &&
    !line.hasError('quantityDecimals') &&
    !line.hasError('aboveSource') &&
    !line.hasError('discountAboveLine')
  );
}

/** Whether a line asks which lot or serial it sells. */
export function namesALot(line: LineGroup): boolean {
  const tracking = line.controls.productTracking.value;
  return tracking === 'lot' || tracking === 'serial';
}

/** Unlike Validators.required, a value made only of spaces is missing too. */
const notBlank: ValidatorFn = (control) =>
  String(control.value ?? '').trim() === '' ? { required: true } : null;

/** The whole trimmed value matches, so a stray space around a number is not a mistake. */
const matches =
  (pattern: RegExp): ValidatorFn =>
  (control) => {
    const value = String(control.value ?? '').trim();
    return value === '' || pattern.test(value) ? null : { pattern: true };
  };

const positive: ValidatorFn = (control) => {
  const value = String(control.value ?? '').trim();
  return value !== '' && /^[0.]+$/.test(value) ? { positive: true } : null;
};

/** A quantity never finer than its unit counts: no half piece, a kilogram to the gram. */
function fitsUnit(options: InvoiceOptions): ValidatorFn {
  return (control) => {
    const line = control as LineGroup;
    const unit = options.units.find((each) => each.id === line.controls.unitId.value);
    const decimals = (line.controls.quantity.value.trim().split('.')[1] ?? '').replace(/0+$/, '');
    return unit !== undefined && decimals.length > unit.decimals
      ? { quantityDecimals: true }
      : null;
  };
}

/** What each line taken from a delivery note may invoice at most, as the API answered it; kept off the form's values. */
const SOURCE_LEFT = new WeakMap<LineGroup, string>();

/** The most a line taken from a delivery note may invoice, without its padding zeros; null where nothing caps it. */
export function sourceLeftOf(line: LineGroup): string | null {
  return SOURCE_LEFT.get(line) ?? null;
}

/** A non-negative decimal string as thousandths, so two quantities compare exactly, never as floats. */
function thousandths(value: string): bigint {
  const [whole, fraction = ''] = value.trim().split('.');
  return BigInt(whole || '0') * 1000n + BigInt((fraction + '000').slice(0, 3));
}

/** A non-negative decimal string as a whole number of units of 10^-`scale`, read exactly. */
function scaled(value: string, scale: number): bigint {
  const [whole, fraction = ''] = value.trim().split('.');
  return (
    BigInt(whole || '0') * 10n ** BigInt(scale) +
    BigInt((fraction + '0'.repeat(scale)).slice(0, scale))
  );
}

/** An amount the currency counts: at most eleven digits, then no more decimals than the currency has. */
function currencyAmount(scale: number): RegExp {
  return new RegExp(`^(0|[1-9][0-9]{0,10})${scale > 0 ? `([.][0-9]{1,${scale}})?` : ''}$`);
}

/**
 * A discount given as an amount takes off at most the whole line, quantity × price rounded to the currency as the
 * API rounds it (half away from zero); the API refuses more the same way.
 */
function discountWithinLine(options: InvoiceOptions): ValidatorFn {
  return (control) => {
    const line = control as LineGroup;
    const { quantity, unitPriceNet, discountAmount, discountKind } = line.controls;
    if (
      discountKind.value !== 'amount' ||
      discountAmount.invalid ||
      discountAmount.value.trim() === ''
    )
      return null;
    if (
      !QUANTITY_PATTERN.test(quantity.value.trim()) ||
      !PRICE_PATTERN.test(unitPriceNet.value.trim())
    )
      return null;
    // Thousandths × ten-thousandths: the line exact, to the ten-millionth.
    const exact = scaled(quantity.value, 3) * scaled(unitPriceNet.value, 4);
    const step = 10n ** BigInt(7 - options.currencyScale);
    const rounded = ((exact + step / 2n) / step) * step;
    return scaled(discountAmount.value, 7) > rounded ? { discountAboveLine: true } : null;
  };
}

/** A line's discount as the API takes it: the rate or the amount the line is discounted by, the other one null. */
export function lineDiscount(line: {
  discountKind: DiscountKind;
  discountRate: string;
  discountAmount: string;
}): { discountRate: string | null; discountAmount: string | null } {
  const rate = line.discountKind === 'rate' ? line.discountRate.trim() : '';
  const amount = line.discountKind === 'amount' ? line.discountAmount.trim() : '';
  return { discountRate: rate === '' ? null : rate, discountAmount: amount === '' ? null : amount };
}

export type { LineStock, OnHand } from '../shared/documents/line-stock';

/**
 * What each line says of stock, from what the establishment holds of the products whose stock is kept: nothing for a
 * line naming no such product or giving a deposit back. Only a line in its product's stock unit, not taken from a
 * delivery note (which handed the goods over already), takes stock when the invoice is issued, so only such a line is
 * told what the document leaves, and only such lines are taken off it.
 */
export function lineStock(
  lines: LinesArray,
  onHand: ReadonlyMap<string, OnHand>,
): (LineStock | null)[] {
  return stockOfLines(
    lines.getRawValue().map((line) => ({
      productId: line.deductsInvoiceId === '' ? line.productId : '',
      unitId: line.unitId,
      quantity: line.quantity,
      takes: line.sourceDeliveryNoteLineId === '',
    })),
    onHand,
  );
}

/** A line taken from a delivery note invoices no more than the note leaves it; the API refuses it the same way. */
const withinSource: ValidatorFn = (control) => {
  const line = control as LineGroup;
  const left = SOURCE_LEFT.get(line);
  const quantity = line.controls.quantity.value.trim();
  if (left === undefined || !QUANTITY_PATTERN.test(quantity)) return null;
  return thousandths(quantity) > thousandths(left) ? { aboveSource: true } : null;
};

/** One line's controls at its values; a new line counts one of the company's first unit, at the customer's discount. */
export function lineGroup(
  line: InvoiceLine | null,
  options: InvoiceOptions,
  customer: CustomerOption | null,
): LineGroup {
  const group = new FormGroup<LineControls>(
    {
      productId: new FormControl(line?.productId ?? '', { nonNullable: true }),
      productReference: new FormControl(line?.productReference ?? '', { nonNullable: true }),
      productName: new FormControl(line?.productName ?? '', { nonNullable: true }),
      description: new FormControl(line?.description ?? '', {
        nonNullable: true,
        validators: [notBlank, Validators.maxLength(DESCRIPTION_MAX)],
      }),
      quantity: new FormControl(line === null ? '1' : plainQuantity(line.quantity), {
        nonNullable: true,
        validators: [notBlank, matches(QUANTITY_PATTERN), positive],
      }),
      unitId: new FormControl(line?.unitId ?? options.units[0]?.id ?? '', {
        nonNullable: true,
        validators: [notBlank],
      }),
      unitPriceNet: new FormControl(
        line === null ? '' : atScale(line.unitPriceNet, options.currencyScale),
        { nonNullable: true, validators: [notBlank, matches(PRICE_PATTERN)] },
      ),
      discountRate: new FormControl(
        line === null
          ? (customer?.defaultDiscountRate ?? '')
          : plainQuantity(line.discountRate ?? ''),
        { nonNullable: true, validators: [matches(RATE_PATTERN)] },
      ),
      discountAmount: new FormControl(
        line?.discountAmount == null ? '' : atScale(line.discountAmount, options.currencyScale),
        { nonNullable: true, validators: [matches(currencyAmount(options.currencyScale))] },
      ),
      discountKind: new FormControl<DiscountKind>(
        line?.discountAmount == null ? 'rate' : 'amount',
        { nonNullable: true },
      ),
      taxComponentIds: new FormControl<string[]>(
        line === null
          ? defaultLineTaxes(options, excludedFor(customer))
          : [...line.taxComponentIds],
        { nonNullable: true },
      ),
      sourceDeliveryNoteLineId: new FormControl(line?.sourceDeliveryNoteLineId ?? '', {
        nonNullable: true,
      }),
      lotCode: new FormControl(line?.lotCode ?? '', {
        nonNullable: true,
        validators: [matches(LOT_CODE_PATTERN)],
      }),
      returned: new FormControl(line?.returned ?? false, { nonNullable: true }),
      deductsInvoiceId: new FormControl(line?.deductsInvoiceId ?? '', { nonNullable: true }),
      section: new FormControl(line?.section ?? '', { nonNullable: true }),
      productTracking: new FormControl<ProductTracking | ''>(line?.productTracking ?? '', {
        nonNullable: true,
      }),
    },
    { validators: [fitsUnit(options), withinSource, discountWithinLine(options)] },
  );
  // The other way of discounting is emptied, so what is sent is never both.
  group.controls.discountKind.valueChanges.subscribe((kind) => {
    const dropped = kind === 'rate' ? group.controls.discountAmount : group.controls.discountRate;
    dropped.setValue('');
    dropped.markAsPristine();
  });
  if (line?.sourceLeft != null) {
    SOURCE_LEFT.set(group, plainQuantity(line.sourceLeft));
    group.updateValueAndValidity();
  }
  return group;
}

/** The document's lines as controls; a document without lines starts with one to fill in. */
export function linesArray(
  lines: readonly InvoiceLine[],
  options: InvoiceOptions,
  customer: CustomerOption | null,
): LinesArray {
  const groups = lines.map((line) => lineGroup(line, options, customer));
  return new FormArray(groups.length > 0 ? groups : [lineGroup(null, options, customer)]);
}

/** The line taxes a customer may be charged: the company's, less the families its regime refuses. */
export function offeredLineTaxes(
  options: InvoiceOptions,
  excludedFamilies: readonly TaxFamily[],
): TaxOption[] {
  return options.taxes.filter(
    (tax) => tax.kind === 'percentage_line' && !excludedFamilies.includes(tax.family),
  );
}

/**
 * A customer chosen after a line was drawn may be of a regime that refuses what the line carries: those taxes come off,
 * so that the API does not refuse the draft for a box nobody ticked on purpose. Nothing is ever added by a change of
 * customer.
 */
export function dropRefusedLineTaxes(
  line: LineGroup,
  options: InvoiceOptions,
  excludedFamilies: readonly TaxFamily[],
): void {
  if (excludedFamilies.length === 0) return;
  const refused = new Set(
    options.taxes.filter((tax) => excludedFamilies.includes(tax.family)).map((tax) => tax.id),
  );
  const kept = line.controls.taxComponentIds.value.filter((id) => !refused.has(id));
  if (kept.length !== line.controls.taxComponentIds.value.length) {
    line.controls.taxComponentIds.setValue(kept);
  }
}

/**
 * What a new line is charged until a product is picked: the company's default percentage taxes, the VAT a counter sale
 * carries, less the families the customer's regime refuses. The API charges a line that names no product and states no
 * taxes the same, so a free line (labour, a service) is never silently untaxed.
 */
export function defaultLineTaxes(
  options: InvoiceOptions,
  excludedFamilies: readonly TaxFamily[],
): string[] {
  return offeredLineTaxes(options, excludedFamilies)
    .filter((tax) => tax.isDefault)
    .map((tax) => tax.id);
}

/**
 * Fills a line from the product picked on it: its name, unit, price and default line taxes, less those the customer's
 * regime refuses. The quantity and the discount stay; picking no product clears what the line named and leaves the
 * description, which someone may have typed themselves.
 *
 * The product is the row the picker answered, so this reads no catalogue.
 */
export function applyProduct(
  line: LineGroup,
  product: ProductOption | null,
  options: InvoiceOptions,
  excludedFamilies: readonly TaxFamily[],
): void {
  if (product === null) {
    line.patchValue({
      productId: '',
      productReference: '',
      productName: '',
      lotCode: '',
      returned: false,
      productTracking: '',
    });
    return;
  }
  const offered = new Set(offeredLineTaxes(options, excludedFamilies).map((tax) => tax.id));
  // A lot names a batch of one product: another product starts without it.
  const sameProduct = line.controls.productId.value === product.id;
  line.patchValue({
    productId: product.id,
    productReference: product.reference,
    productName: product.name,
    description: product.name,
    unitId: product.unitId,
    unitPriceNet: atScale(product.unitPriceNet, options.currencyScale),
    taxComponentIds: product.defaultTaxComponentIds.filter((id) => offered.has(id)),
    lotCode: sameProduct && product.tracking !== 'none' ? line.controls.lotCode.value : '',
    productTracking: product.tracking,
  });
}

/** What the picker shows on a line: the product it names, in its own words, or nothing. */
export function pickedProduct(line: LineGroup): PickOption | null {
  const id = line.controls.productId.value;
  return id === ''
    ? null
    : {
        id,
        code: line.controls.productReference.value,
        name: line.controls.productName.value,
      };
}

/** What the picker shows in the header: the customer, in its own words. */
export function pickedCustomer(customer: CustomerOption | null): PickOption | null {
  return customer === null ? null : { id: customer.id, code: customer.number, name: customer.name };
}

/** The header, the lines and the document taxes as the API takes them: trimmed, an empty field as no value. */
export function invoiceInput(
  values: FormValues,
  lines: LinesArray,
  documentTaxComponentIds: readonly string[],
  customerId: string,
): InvoiceInput {
  const terms = text(values['paymentTermsDays']);
  return {
    customerId,
    establishmentId: text(values['establishmentId']),
    supplyDate: text(values['supplyDate']),
    paymentTermsDays: terms === null ? null : Number(terms),
    customerReference: text(values['customerReference']),
    notesPrinted: text(values['notesPrinted']),
    notesInternal: text(values['notesInternal']),
    discountAmount: text(values['discountAmount']),
    operationCategory:
      OPERATION_CATEGORIES.find((category) => category === values['operationCategory']) ?? null,
    documentTaxComponentIds: [...documentTaxComponentIds],
    lines: lines.getRawValue().map((line) => ({
      productId: line.productId === '' ? null : line.productId,
      description: line.description.trim(),
      quantity: line.quantity.trim(),
      unitId: line.unitId,
      unitPriceNet: line.unitPriceNet.trim(),
      ...lineDiscount(line),
      taxComponentIds: [...line.taxComponentIds],
      sourceDeliveryNoteLineId:
        line.sourceDeliveryNoteLineId === '' ? null : line.sourceDeliveryNoteLineId,
      lotCode:
        line.productTracking === 'lot' || line.productTracking === 'serial'
          ? text(line.lotCode)
          : null,
      // Only goods come back, so a line with no product never says so, whatever was ticked before the product went.
      returned: line.returned && line.productId !== '',
      deductsInvoiceId: line.deductsInvoiceId === '' ? null : line.deductsInvoiceId,
      section: text(line.section),
    })),
  };
}

/** A payment: its day, amount, method, and the reference and notes kept with it. */
export function paymentForm(): FormDescriptor {
  return {
    id: 'invoice-payment',
    sections: [
      {
        id: 'payment',
        title: 'invoices.payments.record_title',
        fields: [
          { id: 'date', label: 'invoices.payments.date', kind: 'date', required: true },
          {
            id: 'amount',
            label: 'invoices.payments.amount',
            kind: 'decimal',
            required: true,
            maxLength: 16,
            pattern: AMOUNT_PATTERN,
          },
          {
            id: 'method',
            label: 'invoices.payments.method',
            kind: 'select',
            required: true,
            options: PAYMENT_METHODS.map((method) => ({
              value: method,
              label: `invoices.payments.methods.${method}`,
            })),
          },
          {
            id: 'reference',
            label: 'invoices.payments.reference',
            kind: 'text',
            maxLength: 120,
            hint: 'invoices.payments.reference_hint',
          },
          {
            id: 'notes',
            label: 'invoices.payments.notes',
            kind: 'textarea',
            maxLength: 2000,
            span: 2,
          },
        ],
      },
    ],
  };
}

/** The longest reason a credit note states, as the API keeps it. */
export const CREDIT_NOTE_REASON_MAX = 500;

/** Why a credit note corrects its invoice, asked before it is drafted (docs/SPEC.md § 7, 2026-09-24 22:51). */
export function creditNoteForm(): FormDescriptor {
  return {
    id: 'invoice-credit-note',
    sections: [
      {
        id: 'credit-note',
        title: 'invoices.credit_note.title',
        fields: [
          {
            id: 'reason',
            label: 'invoices.credit_note.reason',
            kind: 'textarea',
            required: true,
            maxLength: CREDIT_NOTE_REASON_MAX,
            hint: 'invoices.credit_note.reason_hint',
            span: 2,
          },
        ],
      },
    ],
  };
}

/** A new payment on the company's day, for the whole amount still due, by transfer. */
export function paymentValues(today: string, amountDue: string): FormValues {
  return { date: today, amount: amountDue, method: 'transfer', reference: '', notes: '' };
}

export function paymentInput(values: FormValues): PaymentInput {
  const method = PAYMENT_METHODS.find((each) => each === values['method']) ?? 'other';
  return {
    date: String(values['date'] ?? '').trim(),
    amount: String(values['amount'] ?? '').trim(),
    method: method satisfies PaymentMethod,
    reference: text(values['reference']),
    notes: text(values['notes']),
  };
}

function text(value: FieldValue | undefined): string | null {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
}

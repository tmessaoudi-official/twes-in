// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormArray, FormControl, FormGroup, type ValidatorFn, Validators } from '@angular/forms';
import type { FieldValue, FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import { atScale } from '../shared/i18n/format';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import type { PickOption } from '../shared/form/pick-field';
import {
  type CustomerOption,
  INVOICE_SHOWN_STATUSES,
  type InvoiceInput,
  type InvoiceLine,
  type InvoiceOptions,
  type InvoiceRow,
  type InvoiceSearch,
  type InvoiceShownStatus,
  type InvoiceSortKey,
  PAYMENT_METHODS,
  type ProductOption,
  type PaymentInput,
  type PaymentMethod,
  type TaxFamily,
  type TaxOption,
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
  const status = INVOICE_SHOWN_STATUSES.find((known) => known === query.filters['status']) ?? null;
  const documentType = query.filters['type'];
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    status,
    documentType:
      documentType === 'invoice' || documentType === 'credit_note' ? documentType : null,
    customerId: null,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

/** A document as the list shows it: with its customer's name and the status shown for it. */
export type InvoiceListRow = InvoiceRow & { customer: string; shown: InvoiceShownStatus };

/**
 * An issued document names the customer it was issued to; a draft, which has recorded nobody yet, names the customer
 * as the company has it today. Both come off the document itself, so a list never reads the book of customers.
 */
export function invoiceListRows(invoices: readonly InvoiceRow[], today: string): InvoiceListRow[] {
  return invoices.map((invoice) => ({
    ...invoice,
    customer: invoice.recordedCustomerName ?? invoice.customerName,
    shown: shownStatus(invoice, today),
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
  filters: [
    {
      id: 'status',
      label: `${FIELDS}.status`,
      value: (row) => row.shown,
      options: INVOICE_SHOWN_STATUSES.map((status) => ({
        value: status,
        label: `invoices.statuses.${status}`,
      })),
    },
    {
      id: 'type',
      label: `${FIELDS}.type`,
      value: (row) => row.type,
      options: [
        { value: 'invoice', label: 'invoices.types.invoice' },
        { value: 'credit_note', label: 'invoices.types.credit_note' },
      ],
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
  taxComponentIds: FormControl<string[]>;
  sourceDeliveryNoteLineId: FormControl<string>;
}

export type LineGroup = FormGroup<LineControls>;
export type LinesArray = FormArray<LineGroup>;

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

/** One line's controls at its values; a new line counts one of the company's first unit, at the customer's discount. */
export function lineGroup(
  line: InvoiceLine | null,
  options: InvoiceOptions,
  customer: CustomerOption | null,
): LineGroup {
  return new FormGroup<LineControls>(
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
      taxComponentIds: new FormControl<string[]>(line === null ? [] : [...line.taxComponentIds], {
        nonNullable: true,
      }),
      sourceDeliveryNoteLineId: new FormControl(line?.sourceDeliveryNoteLineId ?? '', {
        nonNullable: true,
      }),
    },
    { validators: fitsUnit(options) },
  );
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
    line.patchValue({ productId: '', productReference: '', productName: '' });
    return;
  }
  const offered = new Set(offeredLineTaxes(options, excludedFamilies).map((tax) => tax.id));
  line.patchValue({
    productId: product.id,
    productReference: product.reference,
    productName: product.name,
    description: product.name,
    unitId: product.unitId,
    unitPriceNet: atScale(product.unitPriceNet, options.currencyScale),
    taxComponentIds: product.defaultTaxComponentIds.filter((id) => offered.has(id)),
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
    documentTaxComponentIds: [...documentTaxComponentIds],
    lines: lines.getRawValue().map((line) => ({
      productId: line.productId === '' ? null : line.productId,
      description: line.description.trim(),
      quantity: line.quantity.trim(),
      unitId: line.unitId,
      unitPriceNet: line.unitPriceNet.trim(),
      discountRate: line.discountRate.trim() === '' ? null : line.discountRate.trim(),
      taxComponentIds: [...line.taxComponentIds],
      sourceDeliveryNoteLineId:
        line.sourceDeliveryNoteLineId === '' ? null : line.sourceDeliveryNoteLineId,
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

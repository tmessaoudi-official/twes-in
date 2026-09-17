// SPDX-License-Identifier: AGPL-3.0-or-later

import { atScale } from '../shared/i18n/format';
import { FormArray, FormControl, FormGroup, type ValidatorFn, Validators } from '@angular/forms';
import type { FieldValue, FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import {
  DELIVERY_NOTE_STATUSES,
  type DeliveryNoteInput,
  type DeliveryNoteLine,
  type DeliveryNoteOptions,
  type DeliveryNoteRow,
  type DeliveryNoteSearch,
  type DeliveryNoteSortKey,
  type LineTaxOption,
  type TaxFamily,
} from './delivery-notes-types';

const FIELDS = 'delivery_notes.fields';
/** The API's longest line description. */
const DESCRIPTION_MAX = 5000;
/** A quantity as the API takes it: at most ten digits, then at most three decimals. */
const QUANTITY_PATTERN = /^(0|[1-9][0-9]{0,9})([.][0-9]{1,3})?$/;
/** A price as the API takes it: at most ten digits, then at most four decimals. */
const PRICE_PATTERN = /^(0|[1-9][0-9]{0,9})([.][0-9]{1,4})?$/;

/** A quantity without the zeros the API pads it with, "2.000" as "2", so a unit counting none accepts it back. */
function plainQuantity(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

/** A note as the list shows it: with its customer's name. */
export type DeliveryNoteListRow = DeliveryNoteRow & { customer: string };

/** A validated note names the customer it was issued to; a draft names the customer as the company has it today. */
export function deliveryNoteListRows(
  notes: readonly DeliveryNoteRow[],
  options: DeliveryNoteOptions | null,
): DeliveryNoteListRow[] {
  const customers = new Map(
    (options?.customers ?? []).map((customer) => [customer.id, customer.name]),
  );
  return notes.map((note) => ({
    ...note,
    customer: note.customerName ?? customers.get(note.customerId) ?? '',
  }));
}

const SORT_KEYS: Readonly<Record<string, DeliveryNoteSortKey>> = {
  number: 'number',
  customer: 'customer',
  issueDate: 'issueDate',
  deliveryDate: 'deliveryDate',
  status: 'status',
};

/** What the API is asked for the page of notes the list shows. */
export function deliveryNoteSearch(query: ListQuery): DeliveryNoteSearch {
  const status = DELIVERY_NOTE_STATUSES.find((known) => known === query.filters['status']) ?? null;
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    status,
    customerId: null,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

export const DELIVERY_NOTES_LIST: ListDescriptor<DeliveryNoteListRow> = {
  id: 'delivery-notes',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'number', direction: 'desc' },
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
      id: 'status',
      label: `${FIELDS}.status`,
      value: (row) => row.status,
      sortable: true,
      width: 130,
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
      id: 'deliveryDate',
      label: `${FIELDS}.deliveryDate`,
      value: (row) => row.deliveryDate ?? '',
      sortable: true,
      width: 150,
    },
    {
      id: 'total',
      label: `${FIELDS}.total`,
      value: (row) => row.total,
      align: 'end',
      width: 160,
    },
  ],
  filters: [
    {
      id: 'status',
      label: `${FIELDS}.status`,
      value: (row) => row.status,
      options: DELIVERY_NOTE_STATUSES.map((status) => ({
        value: status,
        label: `delivery_notes.statuses.${status}`,
      })),
    },
  ],
};

const section = (id: string, fields: FormField[]): FormDescriptor['sections'][number] => ({
  id,
  title: `delivery_notes.sections.${id}`,
  fields,
});

/**
 * The note's header: its customer and establishment among what the company offers, keeping those a note already names
 * when the company no longer offers them, then where the goods go and what is printed. The API checks it all again.
 */
export function deliveryNoteForm(
  options: DeliveryNoteOptions,
  current: DeliveryNoteRow | null = null,
): FormDescriptor {
  const customers = options.customers.map((customer) => ({
    value: customer.id,
    label: `${customer.number} · ${customer.name}`,
  }));
  if (current !== null && !customers.some((option) => option.value === current.customerId)) {
    customers.push({
      value: current.customerId,
      label: current.customerName ?? current.customerId,
    });
  }
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
    id: 'delivery-note',
    sections: [
      section('parties', [
        {
          id: 'customerId',
          label: `${FIELDS}.customerId`,
          kind: 'select',
          required: true,
          options: customers,
        },
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
          hint: 'delivery_notes.form.reference_hint',
        },
        {
          id: 'deliveryDate',
          label: `${FIELDS}.deliveryDate`,
          kind: 'date',
          hint: 'delivery_notes.form.delivery_date_hint',
        },
      ]),
      section('delivery_address', [
        {
          id: 'deliveryAddressLine1',
          label: `${FIELDS}.deliveryAddressLine1`,
          kind: 'text',
          maxLength: 200,
          span: 2,
          hint: 'delivery_notes.form.address_hint',
        },
        {
          id: 'deliveryAddressLine2',
          label: `${FIELDS}.deliveryAddressLine2`,
          kind: 'text',
          maxLength: 200,
          span: 2,
        },
        {
          id: 'deliveryPostalCode',
          label: `${FIELDS}.deliveryPostalCode`,
          kind: 'text',
          maxLength: 20,
        },
        { id: 'deliveryCity', label: `${FIELDS}.deliveryCity`, kind: 'text', maxLength: 120 },
        {
          id: 'deliveryCountryCode',
          label: `${FIELDS}.deliveryCountryCode`,
          kind: 'text',
          maxLength: 2,
          pattern: '[A-Za-z]{2}',
          hint: 'delivery_notes.form.country_hint',
        },
      ]),
      section('remarks', [
        {
          id: 'remarksPrinted',
          label: `${FIELDS}.remarksPrinted`,
          kind: 'textarea',
          maxLength: 5000,
          span: 2,
          hint: 'delivery_notes.form.remarks_hint',
        },
        {
          id: 'notesInternal',
          label: `${FIELDS}.notesInternal`,
          kind: 'textarea',
          maxLength: 5000,
          span: 2,
          hint: 'delivery_notes.form.notes_hint',
        },
      ]),
    ],
  };
}

/** Each header field at the note's value; a new note starts at the company's default establishment. */
export function deliveryNoteValues(
  row: DeliveryNoteRow | null,
  options: DeliveryNoteOptions,
): FormValues {
  const address = row?.deliveryAddress;
  const establishment =
    options.establishments.find((each) => each.isDefault) ?? options.establishments[0];
  return {
    customerId: row?.customerId ?? '',
    establishmentId: row?.establishmentId ?? establishment?.id ?? '',
    customerReference: row?.customerReference ?? '',
    deliveryDate: row?.deliveryDate ?? '',
    deliveryAddressLine1: address?.line1 ?? '',
    deliveryAddressLine2: address?.line2 ?? '',
    deliveryPostalCode: address?.postalCode ?? '',
    deliveryCity: address?.city ?? '',
    deliveryCountryCode: address?.countryCode ?? '',
    remarksPrinted: row?.remarksPrinted ?? '',
    notesInternal: row?.notesInternal ?? '',
  };
}

export interface LineControls {
  productId: FormControl<string>;
  description: FormControl<string>;
  quantity: FormControl<string>;
  unitId: FormControl<string>;
  unitPriceNet: FormControl<string>;
  taxComponentIds: FormControl<string[]>;
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
function fitsUnit(options: DeliveryNoteOptions): ValidatorFn {
  return (control) => {
    const line = control as LineGroup;
    const unit = options.units.find((each) => each.id === line.controls.unitId.value);
    const decimals = (line.controls.quantity.value.trim().split('.')[1] ?? '').replace(/0+$/, '');
    return unit !== undefined && decimals.length > unit.decimals
      ? { quantityDecimals: true }
      : null;
  };
}

/** One line's controls, at its values; a new line delivers one of the company's first unit. */
export function lineGroup(line: DeliveryNoteLine | null, options: DeliveryNoteOptions): LineGroup {
  return new FormGroup<LineControls>(
    {
      productId: new FormControl(line?.productId ?? '', { nonNullable: true }),
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
      taxComponentIds: new FormControl<string[]>(line === null ? [] : [...line.taxComponentIds], {
        nonNullable: true,
      }),
    },
    { validators: fitsUnit(options) },
  );
}

/** The note's lines as controls; a note without lines starts with one to fill in. */
export function linesArray(
  lines: readonly DeliveryNoteLine[],
  options: DeliveryNoteOptions,
): LinesArray {
  const groups = lines.map((line) => lineGroup(line, options));
  return new FormArray(groups.length > 0 ? groups : [lineGroup(null, options)]);
}

/** The line taxes a customer may be charged: the company's, less the families its regime refuses. */
export function offeredTaxes(
  options: DeliveryNoteOptions,
  excludedFamilies: readonly TaxFamily[],
): LineTaxOption[] {
  return options.taxes.filter((tax) => !excludedFamilies.includes(tax.family));
}

/**
 * Fills a line from the product chosen on it: its name, unit, price and default taxes, less the taxes the customer's
 * regime refuses. The quantity stays; choosing no product leaves what was typed.
 */
export function applyProduct(
  line: LineGroup,
  productId: string,
  options: DeliveryNoteOptions,
  excludedFamilies: readonly TaxFamily[],
): void {
  const product = options.products.find((each) => each.id === productId);
  if (product === undefined) {
    line.controls.productId.setValue('');
    return;
  }
  const offered = new Set(offeredTaxes(options, excludedFamilies).map((tax) => tax.id));
  line.patchValue({
    productId: product.id,
    description: product.name,
    unitId: product.unitId,
    unitPriceNet: atScale(product.unitPriceNet, options.currencyScale),
    taxComponentIds: product.defaultTaxComponentIds.filter((id) => offered.has(id)),
  });
}

/** The header and the lines as the API takes them: trimmed, an empty field as no value. */
export function deliveryNoteInput(values: FormValues, lines: LinesArray): DeliveryNoteInput {
  const country = text(values['deliveryCountryCode']);
  return {
    customerId: String(values['customerId'] ?? ''),
    establishmentId: text(values['establishmentId']),
    deliveryDate: text(values['deliveryDate']),
    deliveryAddress: {
      line1: text(values['deliveryAddressLine1']),
      line2: text(values['deliveryAddressLine2']),
      postalCode: text(values['deliveryPostalCode']),
      city: text(values['deliveryCity']),
      countryCode: country === null ? null : country.toUpperCase(),
    },
    customerReference: text(values['customerReference']),
    remarksPrinted: text(values['remarksPrinted']),
    notesInternal: text(values['notesInternal']),
    lines: lines.getRawValue().map((line) => ({
      productId: line.productId === '' ? null : line.productId,
      description: line.description.trim(),
      quantity: line.quantity.trim(),
      unitId: line.unitId,
      unitPriceNet: line.unitPriceNet.trim(),
      taxComponentIds: [...line.taxComponentIds],
    })),
  };
}

function text(value: FieldValue | undefined): string | null {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
}

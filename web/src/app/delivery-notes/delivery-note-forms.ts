// SPDX-License-Identifier: AGPL-3.0-or-later

import { atScale } from '../shared/i18n/format';
import { FormArray, FormControl, FormGroup, type ValidatorFn, Validators } from '@angular/forms';
import type { FieldValue, FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import type { PickOption } from '../shared/form/pick-field';
import {
  type CustomerOption,
  DELIVERY_NOTE_STATUSES,
  type DeliveryNoteInput,
  type DeliveryNoteLine,
  type DeliveryNoteOptions,
  type DeliveryNoteRow,
  type DeliveryNoteSearch,
  type DeliveryNoteSortKey,
  type LineTaxOption,
  type ProductOption,
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

/**
 * A validated note names the customer it was issued to; a draft, which has recorded nobody yet, names the customer as
 * the company has it today. Both come off the note itself, so a list never reads the book of customers.
 */
export function deliveryNoteListRows(notes: readonly DeliveryNoteRow[]): DeliveryNoteListRow[] {
  return notes.map((note) => ({
    ...note,
    customer: note.recordedCustomerName ?? note.customerName,
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
  // The row opens through its naming column, so a middle click and a copied address work
  // (design review finding 1); the trailing "Ouvrir" is gone.
  link: (row) => ['/delivery-notes', row.id],
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
 * The note's header: its establishment among what the company offers, keeping the one a note already names when the
 * company no longer offers it, then where the goods go and what is printed. The API checks it all again.
 *
 * The CUSTOMER is not a field here: a company's book is not a dropdown, so the page asks for it through a picker
 * beside this form (docs/SPEC.md § 7, 2026-09-17, ruling 3). A descriptor is data — it is stringified to tell one
 * form from another — and a picker needs a function to search with, which cannot be.
 */
export function deliveryNoteForm(
  options: DeliveryNoteOptions,
  current: DeliveryNoteRow | null = null,
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
    id: 'delivery-note',
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
  /** The product's own words, carried on the line so the picker shows it without reading the catalogue. */
  productReference: FormControl<string>;
  productName: FormControl<string>;
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
 * Fills a line from the product picked on it: its name, unit, price and default taxes, less the taxes the customer's
 * regime refuses. The quantity stays; picking no product clears what the line named and leaves the description,
 * which someone may have typed themselves. The product is the row the picker answered, so this reads no catalogue.
 */
export function applyProduct(
  line: LineGroup,
  product: ProductOption | null,
  options: DeliveryNoteOptions,
  excludedFamilies: readonly TaxFamily[],
): void {
  if (product === null) {
    line.patchValue({ productId: '', productReference: '', productName: '' });
    return;
  }
  const offered = new Set(offeredTaxes(options, excludedFamilies).map((tax) => tax.id));
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

/** What the picker shows on a line: the product it names, in its own words. */
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

/** The header and the lines as the API takes them: trimmed, an empty field as no value. */
export function deliveryNoteInput(
  values: FormValues,
  lines: LinesArray,
  customerId: string,
): DeliveryNoteInput {
  const country = text(values['deliveryCountryCode']);
  return {
    customerId,
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

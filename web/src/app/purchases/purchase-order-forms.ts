// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormArray, FormControl, FormGroup, Validators } from '@angular/forms';
import type { FieldValue, FormDescriptor, FormValues } from '../shared/form/form-types';
import { filterValues, idValues, rangeParams } from '../shared/list/list-filters';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { AMOUNT_WIDTH } from '../shared/list/list-view';
import {
  PURCHASE_ORDER_STATUSES,
  PURCHASE_ORDER_STATUS_TONES,
  type PurchaseOrderInput,
  type PurchaseOrderLine,
  type PurchaseOrderRow,
  type PurchaseOrderSearch,
  type PurchaseOrderSortKey,
} from './purchases-types';

const FIELDS = 'purchases.fields';
/** The API's longest text on an order and on a line. */
const TEXT_MAX = 5000;
/** A quantity as the API takes it: at most eleven digits, then at most three decimals. */
export const QUANTITY_PATTERN = '(0|[1-9][0-9]{0,10})([.][0-9]{1,3})?';
/** A price or a cost as the API takes it: at most ten digits, then at most four decimals. */
export const PRICE_PATTERN = '(0|[1-9][0-9]{0,9})([.][0-9]{1,4})?';

/** A decimal without the zeros the API pads it with, "2.000" as "2", so what was saved and what is typed compare. */
export function plainDecimal(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

const SORT_KEYS: Readonly<Record<string, PurchaseOrderSortKey>> = {
  number: 'number',
  vendor: 'vendor',
  issueDate: 'issueDate',
  expectedOn: 'expectedOn',
  status: 'status',
};

/** The intervals the « Filtres » panel offers: both are days. */
const INTERVALS: readonly { id: string; label: string }[] = [
  { id: 'issueDate', label: `${FIELDS}.issueDate` },
  { id: 'expectedOn', label: `${FIELDS}.expectedOn` },
];

/** What the API is asked for the page of orders the list shows. */
export function purchaseOrderSearch(query: ListQuery): PurchaseOrderSearch {
  const status = PURCHASE_ORDER_STATUSES.filter((known) =>
    filterValues(query.filters['status']).includes(known),
  );
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    status,
    vendorIds: idValues(query.filters['vendor']),
    intervals: rangeParams(
      query.filters,
      INTERVALS.map(({ id }) => ({ id, kind: 'day' as const })),
    ),
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

export const PURCHASES_LIST: ListDescriptor<PurchaseOrderRow> = {
  id: 'purchases',
  rowId: (row) => row.id,
  // The row opens through its naming column, so a middle click and a copied address work.
  link: (row) => ['/purchases', row.id],
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
      priority: 'high',
      label: `${FIELDS}.status`,
      value: (row) => row.status,
      sortable: true,
      width: 160,
    },
    {
      id: 'vendor',
      label: `${FIELDS}.vendorId`,
      value: (row) => row.vendorName,
      sortable: true,
      filterable: true,
      hideable: false,
      wraps: true,
    },
    {
      id: 'issueDate',
      label: `${FIELDS}.issueDate`,
      value: (row) => row.issueDate ?? '',
      sortable: true,
      width: 130,
    },
    {
      id: 'expectedOn',
      label: `${FIELDS}.expectedOn`,
      value: (row) => row.expectedOn ?? '',
      shown: (row) => row.expectedOn !== null,
      sortable: true,
      width: 150,
    },
    {
      id: 'totalNet',
      priority: 'high',
      label: `${FIELDS}.totalNet`,
      value: (row) => row.totalNet ?? '',
      align: 'end',
      width: AMOUNT_WIDTH,
    },
    {
      // Who received the goods, each receipt's receiver once: what a person looking for « the order Sami took in »
      // searches by, given way first when the list is narrow.
      id: 'receivers',
      priority: 'low',
      label: `${FIELDS}.receivers`,
      value: (row) => row.receivers ?? '',
      shown: (row) => row.receivers !== null,
      wraps: true,
    },
  ],
  ranges: INTERVALS.map(({ id, label }) => ({ id, label, kind: 'day' as const })),
  picks: [{ id: 'vendor', label: `${FIELDS}.vendorId` }],
  filters: [
    {
      id: 'status',
      label: `${FIELDS}.status`,
      multiple: true,
      value: (row) => row.status,
      options: PURCHASE_ORDER_STATUSES.map((status) => ({
        value: status,
        label: `purchases.statuses.${status}`,
        tone: PURCHASE_ORDER_STATUS_TONES[status],
      })),
    },
  ],
};

/** The order's header: who it goes to and when the goods are expected, then what is printed and what is kept. */
export function purchaseOrderForm(): FormDescriptor {
  return {
    id: 'purchase-order',
    sections: [
      {
        id: 'order',
        title: 'purchases.sections.order',
        fields: [
          {
            id: 'vendorId',
            label: `${FIELDS}.vendorId`,
            kind: 'pick',
            required: true,
            noneFoundLabel: 'purchases.form.no_vendor_found',
          },
          {
            id: 'expectedOn',
            label: `${FIELDS}.expectedOn`,
            kind: 'date',
            hint: 'purchases.form.expected_hint',
          },
        ],
      },
      {
        id: 'notes',
        title: 'purchases.sections.notes',
        fields: [
          {
            id: 'remarksPrinted',
            label: `${FIELDS}.remarksPrinted`,
            kind: 'textarea',
            maxLength: TEXT_MAX,
            span: 2,
            hint: 'purchases.form.remarks_hint',
          },
          {
            id: 'notesInternal',
            label: `${FIELDS}.notesInternal`,
            kind: 'textarea',
            maxLength: TEXT_MAX,
            span: 2,
            hint: 'purchases.form.notes_hint',
          },
        ],
      },
    ],
  };
}

/** The header's values as the form holds them: a new order starts for the vendor it was opened for, if any. */
export function purchaseOrderValues(
  current: PurchaseOrderRow | null,
  vendorId: string | null = null,
): FormValues {
  return {
    vendorId: current?.vendorId ?? vendorId ?? '',
    expectedOn: current?.expectedOn ?? '',
    remarksPrinted: current?.remarksPrinted ?? '',
    notesInternal: current?.notesInternal ?? '',
  };
}

export type LineGroup = FormGroup<{
  productId: FormControl<string>;
  description: FormControl<string>;
  quantity: FormControl<string>;
  unitPriceNet: FormControl<string>;
}>;

export function lineGroup(
  line: Pick<PurchaseOrderLine, 'productId' | 'description' | 'quantity' | 'unitPriceNet'> | null,
): LineGroup {
  return new FormGroup({
    productId: new FormControl(line?.productId ?? '', {
      nonNullable: true,
      validators: [Validators.required],
    }),
    description: new FormControl(line?.description ?? '', {
      nonNullable: true,
      validators: [Validators.maxLength(TEXT_MAX)],
    }),
    quantity: new FormControl(line === null ? '' : plainDecimal(line.quantity), {
      nonNullable: true,
      validators: [Validators.required, Validators.pattern(QUANTITY_PATTERN)],
    }),
    unitPriceNet: new FormControl(
      line?.unitPriceNet == null ? '' : plainDecimal(line.unitPriceNet),
      { nonNullable: true, validators: [Validators.pattern(PRICE_PATTERN)] },
    ),
  });
}

/** The lines as the form holds them; a new order starts with one empty line to fill in. */
export function linesArray(lines: readonly PurchaseOrderLine[] | null): FormArray<LineGroup> {
  return new FormArray(lines === null ? [lineGroup(null)] : lines.map((line) => lineGroup(line)));
}

function text(value: FieldValue | undefined): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

/**
 * What a save sends. A price is sent only by whoever may read costs: left out by anyone else, the API keeps what a
 * cost reader set rather than taking an empty price for "free".
 */
export function purchaseOrderInput(
  values: FormValues,
  lines: FormArray<LineGroup>,
  seesCost: boolean,
): PurchaseOrderInput {
  return {
    vendorId: text(values['vendorId']) ?? '',
    establishmentId: null,
    expectedOn: text(values['expectedOn']),
    remarksPrinted: text(values['remarksPrinted']),
    notesInternal: text(values['notesInternal']),
    lines: lines.getRawValue().map((line) => ({
      productId: line.productId,
      description: text(line.description),
      quantity: line.quantity,
      unitPriceNet: seesCost ? text(line.unitPriceNet) : null,
    })),
  };
}

/** A field a refusal named, split into the line it is on (from 0) and the field there: `lines[2].quantity`. */
export function refusedPlace(field: string): { line: number | null; field: string } {
  const match = /^lines\[(\d+)\]\.?(.*)$/.exec(field);
  return match === null
    ? { line: null, field }
    : { line: Number(match[1]), field: match[2] === '' ? 'line' : (match[2] ?? 'line') };
}

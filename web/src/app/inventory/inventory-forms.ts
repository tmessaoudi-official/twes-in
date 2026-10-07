// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue, FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import {
  STOCK_LOCATION_KINDS,
  STOCK_MOVEMENT_KINDS,
  STOCK_LOSS_REASONS,
  COST_BASES,
  type CostBasis,
  type CostOnReceive,
  STOCK_SOURCE_TYPES,
  type StockLevelRow,
  type StockLocationInput,
  type StockLocationRow,
  type StockMovementInput,
  type StockMovementKind,
  type StockMovementRow,
  type StockMovementSearch,
  type StockMovementSortKey,
  type StockOperation,
  type StockOptions,
  type StockCountInput,
  type StockReceiptInput,
  type StockProductOption,
  type StockSearch,
  type StockSortKey,
  type StockSourceType,
} from './inventory-types';
import { toReceiptParts } from './split-receipt';

const LOCATION_FIELDS = 'inventory.locations.fields';
const STOCK_FIELDS = 'inventory.stock.fields';
/** The API's shape of a location code. */
const CODE_PATTERN = '[A-Za-z0-9._\\-]{1,32}';
/** A quantity as the decimal field hands it over: at most eleven digits, then at most three decimals. */
/** A cost: an amount from zero with at most four decimals, as the API takes it. */
export const COST_PATTERN = '(0|[1-9][0-9]{0,10})([.][0-9]{1,4})?';
export const QUANTITY_PATTERN = '(0|[1-9][0-9]{0,10})([.][0-9]{1,3})?';

/** Each location's path of codes from its establishment's default, then its name, ordered by that path. */
export function locationLabels(locations: readonly StockLocationRow[]): Map<string, string> {
  const byId = new Map(locations.map((location) => [location.id, location]));
  const paths = locations.map((location): [StockLocationRow, string[]] => {
    const codes = [location.code];
    const seen = new Set([location.id]);
    let parentId = location.parentId;
    // The API refuses a cycle and an unknown parent; the guards keep a malformed answer from hanging the screen.
    while (parentId !== null && !seen.has(parentId)) {
      const parent = byId.get(parentId);
      if (parent === undefined) break;
      codes.unshift(parent.code);
      seen.add(parent.id);
      parentId = parent.parentId;
    }
    return [location, codes];
  });
  paths.sort(([, a], [, b]) => comparePaths(a, b));
  return new Map(
    paths.map(([location, codes]) => [location.id, `${codes.join(' › ')} — ${location.name}`]),
  );
}

/** Segment by segment, so a location always follows the one it sits under. */
function comparePaths(a: readonly string[], b: readonly string[]): number {
  for (let i = 0; i < Math.min(a.length, b.length); i++) {
    const order = (a[i] ?? '').localeCompare(b[i] ?? '');
    if (order !== 0) return order;
  }
  return a.length - b.length;
}

/** What is on hand, as the list shows it: where, in how many decimals, and whether it fell below zero. */
export type StockListRow = StockLevelRow & {
  id: string;
  locationLabel: string;
  negative: boolean;
  /** Past its use-by day and not released: such a lot is left out of what a delivery note takes. */
  expired: boolean;
};

export function stockListRows(
  levels: readonly StockLevelRow[],
  locations: readonly StockLocationRow[],
  today: string,
): StockListRow[] {
  const labels = locationLabels(locations);
  return levels.map((level) => ({
    ...level,
    locationLabel: labels.get(level.locationId) ?? `${level.locationCode} — ${level.locationName}`,
    negative: Number(level.quantity) < 0,
    expired: level.lotExpiresOn !== null && !level.lotReleased && level.lotExpiresOn < today,
  }));
}

export type StockLocationListRow = StockLocationRow & { path: string };

export function locationListRows(locations: readonly StockLocationRow[]): StockLocationListRow[] {
  return [...locationLabels(locations)].flatMap(([id, path]) => {
    const location = locations.find((row) => row.id === id);
    return location === undefined ? [] : [{ ...location, path }];
  });
}

/** A movement as the list shows it: its product and location by name, its quantity in the product's decimals. */
export type StockMovementListRow = StockMovementRow & {
  productLabel: string;
  locationLabel: string;
};

export function movementListRows(
  movements: readonly StockMovementRow[],
  locations: readonly StockLocationRow[],
): StockMovementListRow[] {
  const labels = locationLabels(locations);
  return movements.map((movement) => ({
    ...movement,
    // The movement names what it moved, so a product or location no longer offered is still named here.
    productLabel: `${movement.productReference} — ${movement.productName}`,
    locationLabel:
      labels.get(movement.locationId) ?? `${movement.locationCode} — ${movement.locationName}`,
  }));
}

/** A movements column a person sorted by, as the API names that sort; a column absent here is not sorted by the API. */
const MOVEMENT_SORT_KEYS: Readonly<Record<string, StockMovementSortKey>> = {
  at: 'movedAt',
  product: 'product',
  location: 'location',
  kind: 'kind',
  quantity: 'quantity',
  source: 'source',
};

/**
 * What the movements list asks the API for. Both faceted filters are sent, not applied here: the list shows the page
 * the API answered, so a filter kept on this side would narrow that page alone and read as the whole history.
 */
export function movementSearch(query: ListQuery): StockMovementSearch {
  const key = query.sort === null ? undefined : MOVEMENT_SORT_KEYS[query.sort.column];
  const kind = query.filters['kind'] ?? '';
  const sourceType = query.filters['source'] ?? '';
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    productId: null,
    locationId: null,
    kind: isMovementKind(kind) ? kind : null,
    sourceType: isSourceType(sourceType) ? sourceType : null,
    lot: null,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

const isMovementKind = (value: string): value is StockMovementKind =>
  (STOCK_MOVEMENT_KINDS as readonly string[]).includes(value);

const isSourceType = (value: string): value is StockSourceType =>
  (STOCK_SOURCE_TYPES as readonly string[]).includes(value);

const SORT_KEYS: Readonly<Record<string, StockSortKey>> = {
  reference: 'reference',
  product: 'product',
  location: 'location',
  quantity: 'quantity',
};

/** What the API is asked for the page of stock the list shows. */
export function stockSearch(query: ListQuery): StockSearch {
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    locationId: null,
    establishmentId: null,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

export const STOCK_LIST: ListDescriptor<StockListRow> = {
  id: 'stock-levels',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'reference', direction: 'asc' },
  columns: [
    {
      id: 'reference',
      label: `${STOCK_FIELDS}.reference`,
      value: (row) => row.productReference,
      sortable: true,
      filterable: true,
      hideable: false,
      width: 140,
    },
    {
      id: 'product',
      label: `${STOCK_FIELDS}.product`,
      value: (row) => row.productName,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'location',
      label: `${STOCK_FIELDS}.location`,
      value: (row) => row.locationLabel,
      sortable: true,
      filterable: true,
    },
    // The quantity is what the list is for, so it comes before the optional columns a narrow window cuts first.
    {
      id: 'quantity',
      label: `${STOCK_FIELDS}.quantity`,
      value: (row) => Number(row.quantity),
      sortable: true,
      align: 'end',
      width: 160,
    },
    { id: 'unit', label: `${STOCK_FIELDS}.unit`, value: (row) => row.unitName, width: 100 },
    // A tracked product's stock is a number per lot (docs/SPEC.md § 7, 2026-09-22 11:10); an untracked one shows none.
    {
      id: 'lot',
      label: `${STOCK_FIELDS}.lot`,
      value: (row) => row.lotCode ?? '',
      filterable: true,
      width: 140,
    },
    {
      id: 'useBy',
      label: `${STOCK_FIELDS}.useBy`,
      value: (row) => row.lotExpiresOn ?? '',
      width: 130,
    },
  ],
};

export const MOVEMENTS_LIST: ListDescriptor<StockMovementListRow> = {
  id: 'stock-movements',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'at', direction: 'desc' },
  columns: [
    {
      id: 'at',
      label: `${STOCK_FIELDS}.at`,
      value: (row) => row.at,
      sortable: true,
      hideable: false,
      width: 180,
    },
    {
      id: 'product',
      label: `${STOCK_FIELDS}.product`,
      value: (row) => row.productLabel,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'location',
      label: `${STOCK_FIELDS}.location`,
      value: (row) => row.locationLabel,
      sortable: true,
      filterable: true,
    },
    {
      id: 'lot',
      label: `${STOCK_FIELDS}.lot`,
      value: (row) => row.lotCode ?? '',
      width: 140,
    },
    {
      id: 'kind',
      label: `${STOCK_FIELDS}.kind`,
      value: (row) => row.kind,
      sortable: true,
      width: 140,
    },
    {
      id: 'quantity',
      label: `${STOCK_FIELDS}.quantity`,
      value: (row) => Number(row.quantity),
      sortable: true,
      align: 'end',
      width: 140,
    },
    {
      id: 'source',
      label: `${STOCK_FIELDS}.source`,
      value: (row) => row.sourceType,
      sortable: true,
      width: 180,
    },
    {
      id: 'vendor',
      label: `${STOCK_FIELDS}.vendor`,
      value: (row) => row.vendorName ?? '',
      defaultHidden: true,
    },
    {
      id: 'supplierReference',
      label: `${STOCK_FIELDS}.supplierReference`,
      value: (row) => row.supplierReference ?? '',
      defaultHidden: true,
      width: 160,
    },
    {
      id: 'receivedOn',
      label: `${STOCK_FIELDS}.receivedOn`,
      value: (row) => row.receivedOn ?? '',
      defaultHidden: true,
      width: 140,
    },
  ],
  filters: [
    {
      id: 'kind',
      label: `${STOCK_FIELDS}.kind`,
      value: (row) => row.kind,
      options: STOCK_MOVEMENT_KINDS.map((kind) => ({
        value: kind,
        label: `inventory.movement_kinds.${kind}`,
      })),
    },
    {
      id: 'source',
      label: `${STOCK_FIELDS}.source`,
      value: (row) => row.sourceType,
      options: STOCK_SOURCE_TYPES.map((type) => ({
        value: type,
        label: `inventory.sources.${type}`,
      })),
    },
  ],
};

export const LOCATIONS_LIST: ListDescriptor<StockLocationListRow> = {
  id: 'stock-locations',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'path', direction: 'asc' },
  columns: [
    {
      id: 'path',
      label: `${LOCATION_FIELDS}.path`,
      value: (row) => row.path,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'kind',
      label: `${LOCATION_FIELDS}.kind`,
      value: (row) => row.kind,
      sortable: true,
      width: 140,
    },
    {
      id: 'children',
      label: `${LOCATION_FIELDS}.childCount`,
      value: (row) => row.childCount,
      sortable: true,
      align: 'end',
      width: 150,
    },
    {
      id: 'movements',
      label: `${LOCATION_FIELDS}.movementCount`,
      value: (row) => row.movementCount,
      sortable: true,
      align: 'end',
      width: 150,
    },
  ],
};

/**
 * The location form. An existing location stays in its establishment and never sits under itself or under one of its
 * own locations; a default location stays at the top and never becomes a quarantine. A new one may name a parent of any establishment: the API
 * refuses one of another establishment than the one chosen.
 */
export function locationForm(
  options: StockOptions,
  locations: readonly StockLocationRow[],
  editing: StockLocationRow | null,
): FormDescriptor {
  const byId = new Map(locations.map((location) => [location.id, location]));
  const under = (location: StockLocationRow): boolean => {
    if (editing === null) return false;
    const seen = new Set<string>();
    let current: StockLocationRow | undefined = location;
    while (current !== undefined && !seen.has(current.id)) {
      if (current.id === editing.id) return true;
      seen.add(current.id);
      current = current.parentId === null ? undefined : byId.get(current.parentId);
    }
    return false;
  };
  const parents =
    editing?.isDefault === true
      ? []
      : [...locationLabels(locations)]
          .filter(([id]) => {
            const location = byId.get(id);
            return (
              location !== undefined &&
              !under(location) &&
              (editing === null || location.establishmentId === editing.establishmentId)
            );
          })
          .map(([id, label]) => ({ value: id, label }));

  return {
    id: 'stock-location',
    sections: [
      {
        id: 'location',
        title: 'inventory.locations.section',
        fields: [
          {
            id: 'establishmentId',
            label: `${LOCATION_FIELDS}.establishmentId`,
            kind: 'select',
            required: true,
            readOnly: editing !== null,
            options: options.establishments.map((establishment) => ({
              value: establishment.id,
              label: `${establishment.code} — ${establishment.name}`,
            })),
          },
          {
            id: 'parentId',
            label: `${LOCATION_FIELDS}.parentId`,
            kind: 'select',
            readOnly: editing?.isDefault === true,
            hint: 'inventory.locations.parent_hint',
            options: [
              {
                value: '',
                label:
                  editing?.isDefault === true
                    ? 'inventory.locations.top_level'
                    : 'inventory.locations.under_default',
              },
              ...parents,
            ],
          },
          {
            id: 'kind',
            label: `${LOCATION_FIELDS}.kind`,
            kind: 'select',
            required: true,
            // Every delivery falls back on the default location, so it never holds goods waiting for a decision.
            options: STOCK_LOCATION_KINDS.filter(
              (kind) => editing?.isDefault !== true || kind !== 'quarantine',
            ).map((kind) => ({
              value: kind,
              label: `inventory.kinds.${kind}`,
            })),
          },
          {
            id: 'code',
            label: `${LOCATION_FIELDS}.code`,
            kind: 'text',
            required: true,
            maxLength: 32,
            pattern: CODE_PATTERN,
            hint: 'inventory.locations.code_hint',
          },
          {
            id: 'name',
            label: `${LOCATION_FIELDS}.name`,
            kind: 'text',
            required: true,
            maxLength: 120,
          },
        ],
      },
    ],
  };
}

export function locationValues(row: StockLocationRow | null, options: StockOptions): FormValues {
  const only = options.establishments.length === 1 ? (options.establishments[0]?.id ?? '') : '';
  return {
    establishmentId: row?.establishmentId ?? only,
    parentId: row?.parentId ?? '',
    kind: row?.kind ?? 'zone',
    code: row?.code ?? '',
    name: row?.name ?? '',
  };
}

export function locationInput(values: FormValues): StockLocationInput {
  const parentId = text(values['parentId']);
  return {
    establishmentId: text(values['establishmentId']),
    parentId: parentId === '' ? null : parentId,
    kind: STOCK_LOCATION_KINDS.find((kind) => kind === values['kind']) ?? 'zone',
    code: text(values['code']),
    name: text(values['name']),
  };
}

/**
 * Goods received, what a count found, or goods moved: which product, where, and how much. The product is a `pick`, not a select:
 * a catalogue is not a dropdown, so the form asks the API for the few that match what is typed (docs/SPEC.md § 7,
 * 2026-09-17, ruling 3), and the page passes that search to `DescriptorForm` beside this descriptor.
 */
export function movementForm(
  operation: StockOperation,
  locations: readonly StockLocationRow[],
  tracking: StockProductOption['tracking'] = 'none',
  withCost = false,
  costMode: CostOnReceive | null = null,
  split = false,
  withVendor = false,
): FormDescriptor {
  return {
    id: `stock-${operation}`,
    sections: [
      {
        id: 'movement',
        title: `inventory.movement.${operation}`,
        fields: [
          {
            id: 'productId',
            label: `${STOCK_FIELDS}.product`,
            kind: 'pick',
            required: true,
            span: 2,
            noneFoundLabel: 'inventory.stock.no_product_found',
            hint: 'inventory.stock.product_hint',
          },
          // A delivery or a count shared over several places names them on the rows below: no single place is asked here.
          ...((operation === 'receive' || operation === 'count') && split
            ? []
            : [
                {
                  id: 'locationId',
                  label: `${STOCK_FIELDS}.${'move' === operation ? 'from_location' : 'location'}`,
                  kind: 'select' as const,
                  required: true,
                  options: [...locationLabels(locations)].map(([id, label]) => ({
                    value: id,
                    label,
                  })),
                },
              ]),
          // Only a move has somewhere to go, and it sits between the two so the form reads from where to where.
          ...(operation === 'move'
            ? [
                {
                  id: 'toLocationId',
                  label: `${STOCK_FIELDS}.to_location`,
                  kind: 'select' as const,
                  required: true,
                  options: [...locationLabels(locations)].map(([id, label]) => ({
                    value: id,
                    label,
                  })),
                },
              ]
            : []),
          ...lotFields(operation, tracking),
          // A count over several places has no total: each row says what was found there.
          ...(operation === 'count' && split
            ? []
            : [
                {
                  id: 'quantity',
                  label: `${STOCK_FIELDS}.quantity`,
                  kind: 'decimal' as const,
                  required: true,
                  pattern: QUANTITY_PATTERN,
                  // A serial number is one piece, whatever the movement: the page puts the 1 there and nobody types another.
                  readOnly: tracking === 'serial',
                  hint:
                    tracking === 'serial'
                      ? 'inventory.movement.quantity_hint.serial'
                      : operation === 'receive' && split
                        ? 'inventory.movement.quantity_hint.receive_split'
                        : `inventory.movement.quantity_hint.${operation}`,
                },
              ]),
          // A loss says why: a report tells a breakage from a theft by it, and a count never stands in for either.
          ...(operation === 'loss'
            ? [
                {
                  id: 'reason',
                  label: `${STOCK_FIELDS}.reason`,
                  kind: 'select' as const,
                  required: true,
                  options: STOCK_LOSS_REASONS.map((reason) => ({
                    value: reason,
                    label: `inventory.loss.reasons.${reason}`,
                  })),
                },
                {
                  id: 'note',
                  label: `${STOCK_FIELDS}.note`,
                  kind: 'text' as const,
                  maxLength: 500,
                  span: 2 as const,
                },
              ]
            : []),
          // Asked only of someone who may read what things cost, and only of goods coming in.
          ...(operation === 'receive' && withCost
            ? [
                {
                  id: 'unitCost',
                  label: `${STOCK_FIELDS}.unitCost`,
                  kind: 'decimal' as const,
                  maxLength: 16,
                  pattern: COST_PATTERN,
                  hint: 'inventory.movement.cost_hint',
                },
              ]
            : []),
          // Only where the company leaves it to the person: the other modes decide, and a form never asks what is decided.
          ...(operation === 'receive' && withCost && costMode === 'suggest'
            ? [
                {
                  id: 'applyCost',
                  label: `${STOCK_FIELDS}.applyCost`,
                  kind: 'select' as const,
                  options: [
                    { value: '', label: 'inventory.movement.apply_cost.none' },
                    { value: 'last', label: 'inventory.movement.apply_cost.last' },
                    { value: 'average', label: 'inventory.movement.apply_cost.average' },
                  ],
                  hint: 'inventory.movement.apply_cost_hint',
                },
              ]
            : []),
          // What the delivery came with, asked of a receipt only: the vendor where vendors are kept, the supplier's own
          // reference and the day it arrived, none of them required.
          ...(operation === 'receive'
            ? [
                ...(withVendor
                  ? [
                      {
                        id: 'vendorId',
                        label: `${STOCK_FIELDS}.vendor`,
                        kind: 'pick' as const,
                        span: 2 as const,
                        noneLabel: 'inventory.movement.no_vendor',
                        noneFoundLabel: 'inventory.movement.no_vendor_found',
                        hint: 'inventory.movement.vendor_hint',
                      },
                    ]
                  : []),
                {
                  id: 'supplierReference',
                  label: `${STOCK_FIELDS}.supplierReference`,
                  kind: 'text' as const,
                  maxLength: 60,
                  hint: 'inventory.movement.supplier_reference_hint',
                },
                {
                  id: 'receivedOn',
                  label: `${STOCK_FIELDS}.receivedOn`,
                  kind: 'date' as const,
                },
              ]
            : []),
        ],
      },
    ],
  };
}

/**
 * A new movement starts at the first default location, the one goods go to when nothing else is said, and on no
 * product: a picker cannot guess which of a catalogue was meant.
 */
export function movementValues(locations: readonly StockLocationRow[]): FormValues {
  const byId = new Map(locations.map((location) => [location.id, location]));
  const first = [...locationLabels(locations).keys()].find((id) => byId.get(id)?.isDefault);
  // Where a move goes is left empty on purpose: a default that happens to be where the goods already are would be
  // refused, and any other guess would be this screen choosing a destination nobody asked for.
  return {
    productId: '',
    locationId: first ?? '',
    toLocationId: '',
    quantity: '',
    lotCode: '',
    lotExpiresOn: '',
    unitCost: '',
    applyCost: '',
    vendorId: '',
    supplierReference: '',
    receivedOn: '',
    reason: '',
    note: '',
  };
}

/**
 * What a tracked product's movement names besides (docs/SPEC.md § 7, 2026-09-22 11:10): its lot, or its serial number,
 * as a scanner can read it back — printable ASCII without a space, at most 40 (2026-09-23 04:10). A receipt or a count
 * may be the first to meet a lot, so it may give its use-by day; a move takes goods of a lot that exists.
 */
function lotFields(
  operation: StockOperation,
  tracking: StockProductOption['tracking'],
): FormField[] {
  if (tracking === 'none') return [];
  return [
    {
      id: 'lotCode',
      label: `${STOCK_FIELDS}.${tracking}`,
      kind: 'text',
      required: true,
      maxLength: 40,
      pattern: '[!-~]{1,40}',
      hint: `inventory.movement.lot_hint.${tracking}`,
    },
    ...(operation === 'move' || operation === 'loss'
      ? []
      : [
          {
            id: 'lotExpiresOn',
            label: `${STOCK_FIELDS}.useBy`,
            kind: 'date',
            hint: 'inventory.movement.use_by_hint',
          } satisfies FormField,
        ]),
  ];
}

/**
 * What a cost reader enters for a receipt left « à compléter » (docs/SPEC.md § 7, audit 2026-10-06 C challenge 9):
 * what one unit cost and, only where the company leaves it to the receipt, whether the product now costs that or the
 * new average, as the receipt itself would have asked.
 */
export function receiptCostForm(costMode: CostOnReceive | null): FormDescriptor {
  return {
    id: 'stock-receipt-cost',
    sections: [
      {
        id: 'cost',
        title: 'inventory.receipt_cost.section',
        fields: [
          {
            id: 'unitCost',
            label: `${STOCK_FIELDS}.unitCost`,
            kind: 'decimal',
            required: true,
            maxLength: 16,
            pattern: COST_PATTERN,
          },
          ...(costMode === 'suggest'
            ? [
                {
                  id: 'applyCost',
                  label: `${STOCK_FIELDS}.applyCost`,
                  kind: 'select' as const,
                  options: [
                    { value: '', label: 'inventory.movement.apply_cost.none' },
                    { value: 'last', label: 'inventory.movement.apply_cost.last' },
                    { value: 'average', label: 'inventory.movement.apply_cost.average' },
                  ],
                  hint: 'inventory.movement.apply_cost_hint',
                },
              ]
            : []),
        ],
      },
    ],
  };
}

export function receiptCostValues(): FormValues {
  return { unitCost: '', applyCost: '' };
}

export function receiptCostInput(values: FormValues): {
  unitCost: string;
  applyCost: CostBasis | null;
} {
  return {
    unitCost: text(values['unitCost']),
    applyCost: COST_BASES.find((candidate) => candidate === values['applyCost']) ?? null,
  };
}

export function movementInput(operation: StockOperation, values: FormValues): StockMovementInput {
  const basis = COST_BASES.find((candidate) => candidate === values['applyCost']);
  return {
    operation,
    productId: text(values['productId']),
    locationId: text(values['locationId']),
    // Named only by a move: an empty one on a receipt is a claim about a location nobody chose, answered 422.
    ...(operation === 'move' ? { toLocationId: text(values['toLocationId']) } : {}),
    quantity: text(values['quantity']),
    ...(operation === 'receive' && text(values['unitCost']) !== ''
      ? { unitCost: text(values['unitCost']) }
      : {}),
    // Only with a cost typed: the API ignores a choice with nothing to apply, and a stale one is not sent.
    ...(operation === 'receive' && text(values['unitCost']) !== '' && basis !== undefined
      ? { applyCost: basis }
      : {}),
    ...(operation === 'receive' ? documentOf(values) : {}),
    ...(operation === 'loss'
      ? {
          reason: STOCK_LOSS_REASONS.find((reason) => reason === values['reason']) ?? undefined,
          ...(text(values['note']) === '' ? {} : { note: text(values['note']) }),
        }
      : {}),
    // Named only for a tracked product, whose form asked; a move never says a lot's date again.
    ...(text(values['lotCode']) === '' ? {} : { lotCode: text(values['lotCode']) }),
    ...(operation === 'move' || operation === 'loss' || text(values['lotExpiresOn']) === ''
      ? {}
      : { lotExpiresOn: text(values['lotExpiresOn']) }),
  };
}

/**
 * A delivery shared over several places, as the one request the API takes: the rows given a quantity, each written the
 * way it reads it, with the lot, its day and the cost typed beside, as for a receipt in one place.
 */
export function receiptInput(
  values: FormValues,
  parts: readonly { readonly locationId: string; readonly quantity: string }[],
  decimals = 3,
): StockReceiptInput {
  const basis = COST_BASES.find((candidate) => candidate === values['applyCost']);
  return {
    productId: text(values['productId']),
    parts: toReceiptParts(parts, decimals),
    ...(text(values['lotCode']) === '' ? {} : { lotCode: text(values['lotCode']) }),
    ...(text(values['lotExpiresOn']) === '' ? {} : { lotExpiresOn: text(values['lotExpiresOn']) }),
    ...(text(values['unitCost']) === '' ? {} : { unitCost: text(values['unitCost']) }),
    ...(text(values['unitCost']) !== '' && basis !== undefined ? { applyCost: basis } : {}),
    ...documentOf(values),
  };
}

/** A count over several places: the product, its lot, and what was found at each place, as `toCountParts` reads them. */
export function countInput(
  values: FormValues,
  parts: readonly { locationId: string; quantity: string }[],
): StockCountInput {
  return {
    productId: text(values['productId']),
    parts: parts.map((part) => ({ ...part })),
    ...(text(values['lotCode']) === '' ? {} : { lotCode: text(values['lotCode']) }),
    ...(text(values['lotExpiresOn']) === '' ? {} : { lotExpiresOn: text(values['lotExpiresOn']) }),
  };
}

/** The vendor, reference and arrival day a person typed on a receipt; what was left empty is not sent. */
function documentOf(
  values: FormValues,
): Pick<StockMovementInput, 'vendorId' | 'supplierReference' | 'receivedOn'> {
  return {
    ...(text(values['vendorId']) === '' ? {} : { vendorId: text(values['vendorId']) }),
    ...(text(values['supplierReference']) === ''
      ? {}
      : { supplierReference: text(values['supplierReference']) }),
    ...(text(values['receivedOn']) === '' ? {} : { receivedOn: text(values['receivedOn']) }),
  };
}

function text(value: FieldValue | undefined): string {
  return String(value ?? '').trim();
}

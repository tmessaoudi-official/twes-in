// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue, FormDescriptor, FormValues } from '../shared/form/form-types';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import {
  STOCK_LOCATION_KINDS,
  STOCK_MOVEMENT_KINDS,
  STOCK_SOURCE_TYPES,
  type StockLevelRow,
  type StockLocationInput,
  type StockLocationRow,
  type StockMovementInput,
  type StockMovementRow,
  type StockOperation,
  type StockOptions,
  type StockSearch,
  type StockSortKey,
} from './inventory-types';

const LOCATION_FIELDS = 'inventory.locations.fields';
const STOCK_FIELDS = 'inventory.stock.fields';
/** The API's shape of a location code. */
const CODE_PATTERN = '[A-Za-z0-9._\\-]{1,32}';
/** A quantity as the decimal field hands it over: at most eleven digits, then at most three decimals. */
const QUANTITY_PATTERN = '(0|[1-9][0-9]{0,10})([.][0-9]{1,3})?';

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
};

export function stockListRows(
  levels: readonly StockLevelRow[],
  locations: readonly StockLocationRow[],
): StockListRow[] {
  const labels = locationLabels(locations);
  return levels.map((level) => ({
    ...level,
    locationLabel: labels.get(level.locationId) ?? `${level.locationCode} — ${level.locationName}`,
    negative: Number(level.quantity) < 0,
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
    {
      id: 'quantity',
      label: `${STOCK_FIELDS}.quantity`,
      value: (row) => Number(row.quantity),
      sortable: true,
      align: 'end',
      width: 160,
    },
    { id: 'unit', label: `${STOCK_FIELDS}.unit`, value: (row) => row.unitCode, width: 100 },
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
 * own locations; a default location stays at the top. A new one may name a parent of any establishment: the API
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
            options: STOCK_LOCATION_KINDS.map((kind) => ({
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
 * Goods received, or what a count found: where, and how much. The PRODUCT is not a field here — a catalogue is not a
 * dropdown, so the page asks for it through a picker beside this form (docs/SPEC.md § 7, 2026-09-17, ruling 3).
 */
export function movementForm(
  operation: StockOperation,
  locations: readonly StockLocationRow[],
): FormDescriptor {
  return {
    id: `stock-${operation}`,
    sections: [
      {
        id: 'movement',
        title: `inventory.movement.${operation}`,
        fields: [
          {
            id: 'locationId',
            label: `${STOCK_FIELDS}.location`,
            kind: 'select',
            required: true,
            options: [...locationLabels(locations)].map(([id, label]) => ({ value: id, label })),
          },
          {
            id: 'quantity',
            label: `${STOCK_FIELDS}.quantity`,
            kind: 'decimal',
            required: true,
            pattern: QUANTITY_PATTERN,
            hint: `inventory.movement.quantity_hint.${operation}`,
          },
        ],
      },
    ],
  };
}

/** A new movement starts at the first default location, the one goods go to when nothing else is said. */
export function movementValues(locations: readonly StockLocationRow[]): FormValues {
  const byId = new Map(locations.map((location) => [location.id, location]));
  const first = [...locationLabels(locations).keys()].find((id) => byId.get(id)?.isDefault);
  return { locationId: first ?? '', quantity: '' };
}

export function movementInput(
  operation: StockOperation,
  values: FormValues,
  productId: string,
): StockMovementInput {
  return {
    operation,
    productId,
    locationId: text(values['locationId']),
    quantity: text(values['quantity']),
  };
}

function text(value: FieldValue | undefined): string {
  return String(value ?? '').trim();
}

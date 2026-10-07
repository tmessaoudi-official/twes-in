// SPDX-License-Identifier: AGPL-3.0-or-later

import { atScale } from '../shared/i18n/format';
import {
  customFieldInput,
  customFieldValues,
  customFormFields,
  customListColumns,
} from '../shared/custom-fields/custom-fields';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { withCustomFields } from '../shared/form/form-builder';
import type {
  FieldValue,
  FormDescriptor,
  FormField,
  FormSection,
  FormValues,
} from '../shared/form/form-types';
import { filterValues, idValues, rangeParams } from '../shared/list/list-filters';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { withCustomColumns } from '../shared/list/list-view';
import {
  PRODUCT_KINDS,
  PRODUCT_TRACKINGS,
  SUBSTITUTION_GROUP_MAX,
  type ProductCategoryInput,
  type ProductCategoryRow,
  type ProductInput,
  type ProductKind,
  type ProductOptions,
  type ProductRow,
  type ProductSearch,
  type ProductSortKey,
  type ProductTracking,
} from './products-types';

const FIELDS = 'products.fields';
/** The API's shape of a price, anchored by the form: at most ten digits, then at most four decimals. */
const PRICE_PATTERN = '(0|[1-9][0-9]{0,9})([.][0-9]{1,4})?';

/** Each category's path from the top of the tree, "Matériel › Portables", ordered by path. */
export function categoryLabels(categories: readonly ProductCategoryRow[]): Map<string, string> {
  const byId = new Map(categories.map((category) => [category.id, category]));
  const path = (category: ProductCategoryRow): string => {
    const names = [category.name];
    let parentId = category.parentId;
    // The API refuses a cycle; the bound keeps a malformed answer from hanging the screen.
    for (let depth = 0; parentId !== null && depth < categories.length; depth++) {
      const parent = byId.get(parentId);
      if (parent === undefined) break;
      names.unshift(parent.name);
      parentId = parent.parentId;
    }
    return names.join(' › ');
  };
  const labels = categories.map((category): [string, string] => [category.id, path(category)]);
  labels.sort(([, a], [, b]) => a.localeCompare(b));
  return new Map(labels);
}

/** A product as the list shows it: its category's path and its unit's name, never its code (audit V-10). */
export type ProductListRow = ProductRow & {
  categoryName: string | null;
  unitName: string;
};

export function productListRows(
  rows: readonly ProductRow[],
  categories: readonly ProductCategoryRow[],
  options: ProductOptions | null,
): ProductListRow[] {
  const labels = categoryLabels(categories);
  const units = new Map((options?.units ?? []).map((unit) => [unit.id, unit.name]));
  return rows.map((row) => ({
    ...row,
    categoryName: row.categoryId === null ? null : (labels.get(row.categoryId) ?? null),
    unitName: units.get(row.unitId) ?? '',
  }));
}

export const PRODUCTS_LIST: ListDescriptor<ProductListRow> = {
  id: 'products',
  rowId: (row) => row.id,
  // The row opens through its naming column, so a middle click and a copied address work
  // (design review finding 1); the trailing "Ouvrir" is gone.
  link: (row) => ['/products', row.id],
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'reference', direction: 'asc' },
  columns: [
    {
      id: 'reference',
      label: `${FIELDS}.reference`,
      value: (row) => row.reference,
      sortable: true,
      filterable: true,
      hideable: false,
      width: 140,
    },
    {
      id: 'name',
      label: `${FIELDS}.name`,
      value: (row) => row.name,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    { id: 'kind', label: `${FIELDS}.kind`, value: (row) => row.kind, sortable: true, width: 120 },
    {
      id: 'category',
      label: `${FIELDS}.categoryId`,
      value: (row) => row.categoryName ?? '',
      sortable: true,
      filterable: true,
    },
    { id: 'unit', label: `${FIELDS}.unit`, value: (row) => row.unitName, width: 100 },
    {
      id: 'price',
      label: `${FIELDS}.price`,
      value: (row) => row.unitPriceNet,
      align: 'end',
      width: 160,
    },
    {
      id: 'status',
      label: `${FIELDS}.isActive`,
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      sortable: true,
      width: 120,
    },
  ],
  ranges: [{ id: 'unitPriceNet', kind: 'amount', label: `${FIELDS}.unitPriceNet` }],
  picks: [{ id: 'category', label: `${FIELDS}.categoryId` }],
  filters: [
    {
      id: 'kind',
      label: `${FIELDS}.kind`,
      multiple: true,
      value: (row) => row.kind,
      options: PRODUCT_KINDS.map((kind) => ({ value: kind, label: `products.kinds.${kind}` })),
    },
    {
      id: 'tracking',
      label: `${FIELDS}.tracking`,
      multiple: true,
      value: (row) => row.tracking,
      options: PRODUCT_TRACKINGS.map((tracking) => ({
        value: tracking,
        label: `products.trackings.${tracking}`,
      })),
    },
    {
      id: 'status',
      label: `${FIELDS}.isActive`,
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      options: ['active', 'inactive'].map((status) => ({
        value: status,
        label: `products.statuses.${status}`,
      })),
    },
  ],
};

/** The products list with a hidden column per active custom field of the company for products. */
/** The column a person sorts by, as the API names what it sorts products by. */
const SORT_KEYS: Readonly<Record<string, ProductSortKey>> = {
  reference: 'reference',
  name: 'name',
  kind: 'kind',
  category: 'category',
  status: 'isActive',
};

/** What the API is asked for the page of products the list shows. */
const INTERVALS: readonly { id: string; kind: 'day' | 'amount'; label: string }[] = [
  { id: 'unitPriceNet', kind: 'amount', label: `${FIELDS}.unitPriceNet` },
];

/**
 * What the API is asked for the page of products the list shows: every filter is sent, none applied here, since the
 * list shows the page the API answered and a filter kept on this side would narrow that page alone.
 */
export function productSearch(query: ListQuery): ProductSearch {
  const kinds = filterValues(query.filters['kind']);
  const trackings = filterValues(query.filters['tracking']);
  const status = query.filters['status'];
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    kinds: PRODUCT_KINDS.filter((known) => kinds.includes(known)),
    trackings: PRODUCT_TRACKINGS.filter((known) => trackings.includes(known)),
    categoryIds: idValues(query.filters['category']),
    isActive: status === 'active' ? true : status === 'inactive' ? false : null,
    intervals: rangeParams(query.filters, INTERVALS),
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

export function productsList(
  fields: readonly CustomFieldDefinition[],
): ListDescriptor<ProductListRow> {
  return withCustomColumns(PRODUCTS_LIST, customListColumns<ProductListRow>(fields));
}

const section = (id: string, fields: FormField[]): FormSection => ({
  id,
  title: `products.sections.${id}`,
  fields,
});

/**
 * The product form, from what the company offers: its active units, one box per active tax charged on a line, its
 * categories by path, then its custom fields for products. The API checks everything again. The cost is asked only
 * where it may be shown: to someone who may read costs, outside customer view (docs/SPEC.md § 7, 2026-09-23 slice 5).
 */
export function productForm(
  options: ProductOptions,
  categories: readonly ProductCategoryRow[],
  fields: readonly CustomFieldDefinition[] = [],
  shown: { readonly cost: boolean } = { cost: true },
): FormDescriptor {
  const labels = categoryLabels(categories);
  const taxes: FormField[] = [
    {
      id: 'defaultTaxComponentIds',
      label: `${FIELDS}.defaultTaxComponentIds`,
      kind: 'multiselect',
      span: 2,
      options: options.taxes.map((tax) => ({ value: tax.id, label: tax.name })),
    },
  ];

  const descriptor: FormDescriptor = {
    id: 'product',
    sections: [
      section('identity', [
        {
          id: 'reference',
          label: `${FIELDS}.reference`,
          kind: 'text',
          required: true,
          maxLength: 32,
          pattern: '[A-Za-z0-9][A-Za-z0-9._/\\-]{0,31}',
          hint: 'products.form.reference_hint',
        },
        {
          id: 'kind',
          label: `${FIELDS}.kind`,
          kind: 'select',
          required: true,
          options: PRODUCT_KINDS.map((kind) => ({ value: kind, label: `products.kinds.${kind}` })),
        },
        {
          id: 'tracking',
          label: `${FIELDS}.tracking`,
          kind: 'select',
          required: true,
          options: PRODUCT_TRACKINGS.map((tracking) => ({
            value: tracking,
            label: `products.trackings.${tracking}`,
          })),
          hint: 'products.form.tracking_hint',
        },
        {
          id: 'name',
          label: `${FIELDS}.name`,
          kind: 'text',
          required: true,
          maxLength: 200,
          span: 2,
        },
        {
          id: 'categoryId',
          label: `${FIELDS}.categoryId`,
          kind: 'select',
          options: [
            { value: '', label: 'products.form.no_category' },
            ...[...labels].map(([id, label]) => ({ value: id, label })),
          ],
        },
        {
          id: 'isActive',
          label: `${FIELDS}.isActive`,
          kind: 'checkbox',
          hint: 'products.form.active_hint',
        },
      ]),
      section('pricing', [
        {
          id: 'unitId',
          label: `${FIELDS}.unitId`,
          kind: 'select',
          required: true,
          options: options.units.map((unit) => ({
            value: unit.id,
            label: unit.name,
          })),
        },
        {
          id: 'unitPriceNet',
          label: `${FIELDS}.unitPriceNet`,
          kind: 'decimal',
          required: true,
          maxLength: 15,
          pattern: PRICE_PATTERN,
          hint: 'products.form.price_hint',
        },
        ...(shown.cost
          ? [
              {
                id: 'costPrice',
                label: `${FIELDS}.costPrice`,
                kind: 'decimal',
                maxLength: 15,
                pattern: PRICE_PATTERN,
                hint: 'products.form.cost_hint',
              } satisfies FormField,
            ]
          : []),
      ]),
      ...(taxes.length > 0 ? [section('taxes', taxes)] : []),
      section('description', [
        {
          id: 'description',
          label: `${FIELDS}.description`,
          kind: 'textarea',
          maxLength: 5000,
          span: 2,
          hint: 'products.form.description_hint',
        },
        {
          id: 'substitutionGroup',
          label: `${FIELDS}.substitutionGroup`,
          kind: 'text',
          maxLength: SUBSTITUTION_GROUP_MAX,
          hint: 'products.form.substitution_group_hint',
        },
      ]),
    ],
  };
  const custom = customFormFields(fields);
  return custom.length === 0
    ? descriptor
    : withCustomFields(
        { ...descriptor, sections: [...descriptor.sections, section('custom', [])] },
        'custom',
        custom,
      );
}

/**
 * Each field at the product's value. A new product is goods, active and without taxes, in the unit its company's
 * articles chain resolves (`article.default_unit`), or in the first unit the company offers when it offers not that one,
 * and followed as `article.traceability` says.
 */
export function productValues(
  row: ProductRow | null,
  options: ProductOptions,
  fields: readonly CustomFieldDefinition[] = [],
  defaultUnitCode: string | null = null,
  defaultTracking: ProductTracking = 'none',
): FormValues {
  const unit = options.units.find((each) => each.code === defaultUnitCode) ?? options.units[0];
  const values: FormValues = {
    reference: row?.reference ?? '',
    kind: row?.kind ?? 'goods',
    name: row?.name ?? '',
    categoryId: row?.categoryId ?? '',
    isActive: row?.isActive ?? true,
    unitId: row?.unitId ?? unit?.id ?? '',
    unitPriceNet: row === null ? '' : atScale(row.unitPriceNet, options.currencyScale),
    costPrice:
      row?.costPrice === null || row === null ? '' : atScale(row.costPrice, options.currencyScale),
    description: row?.description ?? '',
    substitutionGroup: row?.substitutionGroup ?? '',
    tracking: row?.tracking ?? defaultTracking,
  };
  values['defaultTaxComponentIds'] = offeredTaxes(options, row?.defaultTaxComponentIds ?? []);
  return { ...values, ...customFieldValues(fields, row?.customFields ?? {}) };
}

/**
 * The form's values as the API takes them: trimmed, an empty field as no value, the ticked taxes in form order. A form
 * that did not show the cost sends the product's own, so a save in customer view never erases it.
 */
export function productInput(
  values: FormValues,
  options: ProductOptions,
  fields: readonly CustomFieldDefinition[] = [],
  kept: ProductRow | null = null,
): ProductInput {
  const kind = (PRODUCT_KINDS as readonly string[]).includes(String(values['kind']))
    ? (values['kind'] as ProductKind)
    : 'goods';
  return {
    reference: String(values['reference'] ?? '').trim(),
    name: String(values['name'] ?? '').trim(),
    description: text(values['description']),
    substitutionGroup: text(values['substitutionGroup']),
    kind,
    unitId: String(values['unitId'] ?? ''),
    unitPriceNet: String(values['unitPriceNet'] ?? '').trim(),
    costPrice: 'costPrice' in values ? text(values['costPrice']) : (kept?.costPrice ?? null),
    categoryId: text(values['categoryId']),
    defaultTaxComponentIds: offeredTaxes(options, values['defaultTaxComponentIds']),
    isActive: values['isActive'] === true,
    customFields: customFieldInput(fields, values),
    // A service holds no stock, so it tracks nothing; the API says the same.
    tracking:
      kind === 'service'
        ? 'none'
        : (PRODUCT_TRACKINGS.find((tracking) => tracking === values['tracking']) ?? 'none'),
  };
}

/** The taxes the company offers that are held, in the order it offers them: a list a person ticked in any order. */
function offeredTaxes(options: ProductOptions, held: FieldValue | undefined): string[] {
  const chosen = Array.isArray(held) ? held : [];
  return options.taxes.filter((tax) => chosen.includes(tax.id)).map((tax) => tax.id);
}

const CATEGORY_FIELDS = 'products.categories.fields';

/** A category as its list shows it: with its path in the tree. */
export type ProductCategoryListRow = ProductCategoryRow & { path: string };

export function categoryListRows(
  categories: readonly ProductCategoryRow[],
): ProductCategoryListRow[] {
  const labels = categoryLabels(categories);
  return categories.map((category) => ({
    ...category,
    path: labels.get(category.id) ?? category.name,
  }));
}

export const CATEGORIES_LIST: ListDescriptor<ProductCategoryListRow> = {
  id: 'product-categories',
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
    },
    {
      id: 'childCount',
      label: `${CATEGORY_FIELDS}.childCount`,
      value: (row) => row.childCount,
      sortable: true,
      align: 'end',
      width: 160,
    },
    {
      id: 'productCount',
      label: `${CATEGORY_FIELDS}.productCount`,
      value: (row) => row.productCount,
      sortable: true,
      align: 'end',
      width: 140,
    },
  ],
};

/** The category form; a category is never offered as the parent of itself or of one of its own subcategories. */
export function categoryForm(
  categories: readonly ProductCategoryRow[],
  editing: ProductCategoryRow | null,
): FormDescriptor {
  const byId = new Map(categories.map((category) => [category.id, category]));
  const below = (category: ProductCategoryRow): boolean => {
    if (editing === null) return false;
    let current: ProductCategoryRow | undefined = category;
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
    .map(([id, label]) => ({ value: id, label }));

  return {
    id: 'product-category',
    sections: [
      {
        id: 'category',
        title: 'products.categories.section',
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
            options: [{ value: '', label: 'products.categories.top_level' }, ...parents],
          },
        ],
      },
    ],
  };
}

export function categoryValues(row: ProductCategoryRow | null): FormValues {
  return { name: row?.name ?? '', parentId: row?.parentId ?? '' };
}

export function categoryInput(values: FormValues): ProductCategoryInput {
  return { name: String(values['name'] ?? '').trim(), parentId: text(values['parentId']) };
}

function text(value: FieldValue | undefined): string | null {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
}

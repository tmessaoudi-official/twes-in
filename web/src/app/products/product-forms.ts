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
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { withCustomColumns } from '../shared/list/list-view';
import {
  PRODUCT_KINDS,
  type ProductCategoryInput,
  type ProductCategoryRow,
  type ProductInput,
  type ProductKind,
  type ProductOptions,
  type ProductRow,
  type ProductSearch,
  type ProductSortKey,
} from './products-types';

const FIELDS = 'products.fields';
const TAX_PREFIX = 'tax__';
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

/** A product as the list shows it: its category's path and its unit's code. */
export type ProductListRow = ProductRow & {
  categoryName: string | null;
  unitCode: string;
};

export function productListRows(
  rows: readonly ProductRow[],
  categories: readonly ProductCategoryRow[],
  options: ProductOptions | null,
): ProductListRow[] {
  const labels = categoryLabels(categories);
  const units = new Map((options?.units ?? []).map((unit) => [unit.id, unit.code]));
  return rows.map((row) => ({
    ...row,
    categoryName: row.categoryId === null ? null : (labels.get(row.categoryId) ?? null),
    unitCode: units.get(row.unitId) ?? '',
  }));
}

export const PRODUCTS_LIST: ListDescriptor<ProductListRow> = {
  id: 'products',
  rowId: (row) => row.id,
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
    { id: 'unit', label: `${FIELDS}.unit`, value: (row) => row.unitCode, width: 100 },
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
  filters: [
    {
      id: 'kind',
      label: `${FIELDS}.kind`,
      value: (row) => row.kind,
      options: PRODUCT_KINDS.map((kind) => ({ value: kind, label: `products.kinds.${kind}` })),
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
export function productSearch(query: ListQuery): ProductSearch {
  const kind = PRODUCT_KINDS.find((known) => known === query.filters['kind']) ?? null;
  const status = query.filters['status'];
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    kind,
    isActive: status === 'active' ? true : status === 'inactive' ? false : null,
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
 * categories by path, then its custom fields for products. The API checks everything again.
 */
export function productForm(
  options: ProductOptions,
  categories: readonly ProductCategoryRow[],
  fields: readonly CustomFieldDefinition[] = [],
): FormDescriptor {
  const labels = categoryLabels(categories);
  const taxes = options.taxes.map((tax): FormField => ({
    id: TAX_PREFIX + tax.id,
    label: tax.name,
    kind: 'checkbox',
  }));

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
          id: 'barcode',
          label: `${FIELDS}.barcode`,
          kind: 'text',
          maxLength: 64,
          pattern: '\\S{1,64}',
          hint: 'products.form.barcode_hint',
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
            label: `${unit.code} · ${unit.name}`,
          })),
        },
        {
          id: 'unitPriceNet',
          label: `${FIELDS}.unitPriceNet`,
          kind: 'text',
          required: true,
          maxLength: 15,
          pattern: PRICE_PATTERN,
          hint: 'products.form.price_hint',
        },
        {
          id: 'costPrice',
          label: `${FIELDS}.costPrice`,
          kind: 'text',
          maxLength: 15,
          pattern: PRICE_PATTERN,
          hint: 'products.form.cost_hint',
        },
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
 * articles chain resolves (`article.default_unit`), or in the first unit the company offers when it offers not that one.
 */
export function productValues(
  row: ProductRow | null,
  options: ProductOptions,
  fields: readonly CustomFieldDefinition[] = [],
  defaultUnitCode: string | null = null,
): FormValues {
  const unit = options.units.find((each) => each.code === defaultUnitCode) ?? options.units[0];
  const values: FormValues = {
    reference: row?.reference ?? '',
    kind: row?.kind ?? 'goods',
    name: row?.name ?? '',
    categoryId: row?.categoryId ?? '',
    barcode: row?.barcode ?? '',
    isActive: row?.isActive ?? true,
    unitId: row?.unitId ?? unit?.id ?? '',
    unitPriceNet: row === null ? '' : atScale(row.unitPriceNet, options.currencyScale),
    costPrice:
      row?.costPrice === null || row === null ? '' : atScale(row.costPrice, options.currencyScale),
    description: row?.description ?? '',
  };
  for (const tax of options.taxes) {
    values[TAX_PREFIX + tax.id] = row?.defaultTaxComponentIds.includes(tax.id) ?? false;
  }
  return { ...values, ...customFieldValues(fields, row?.customFields ?? {}) };
}

/** The form's values as the API takes them: trimmed, an empty field as no value, the ticked taxes in form order. */
export function productInput(
  values: FormValues,
  options: ProductOptions,
  fields: readonly CustomFieldDefinition[] = [],
): ProductInput {
  const kind = (PRODUCT_KINDS as readonly string[]).includes(String(values['kind']))
    ? (values['kind'] as ProductKind)
    : 'goods';
  return {
    reference: String(values['reference'] ?? '').trim(),
    name: String(values['name'] ?? '').trim(),
    description: text(values['description']),
    kind,
    unitId: String(values['unitId'] ?? ''),
    unitPriceNet: String(values['unitPriceNet'] ?? '').trim(),
    costPrice: text(values['costPrice']),
    categoryId: text(values['categoryId']),
    barcode: text(values['barcode']),
    defaultTaxComponentIds: options.taxes
      .filter((tax) => values[TAX_PREFIX + tax.id] === true)
      .map((tax) => tax.id),
    isActive: values['isActive'] === true,
    customFields: customFieldInput(fields, values),
  };
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

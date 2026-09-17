// SPDX-License-Identifier: AGPL-3.0-or-later

import type {
  FieldOption,
  FieldValue,
  FormDescriptor,
  FormValues,
} from '../shared/form/form-types';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import {
  EXPENSE_STATUSES,
  type ExpenseCategoryInput,
  type ExpenseCategoryRow,
  type ExpenseInput,
  type ExpenseOptions,
  type ExpensePayment,
  type ExpenseRow,
  type ExpenseSearch,
  type ExpenseSortKey,
  type PaymentMethod,
} from './expenses-types';

const FIELDS = 'expenses.fields';
const CATEGORY_FIELDS = 'expenses.categories.fields';

export const EXPENSES_LIST: ListDescriptor<ExpenseRow> = {
  id: 'expenses',
  rowId: (row) => row.id,
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
    },
    {
      id: 'vendor',
      label: `${FIELDS}.vendorId`,
      value: (row) => row.vendorName ?? '',
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
      label: `${FIELDS}.amountGross`,
      value: (row) => Number(row.amountGross),
      sortable: true,
      align: 'end',
      width: 150,
    },
    {
      id: 'dueDate',
      // Not sortable: the due day is the vendor's terms counted from the expense's day, which is not a column the
      // API can order a page by.
      label: `${FIELDS}.dueDate`,
      value: (row) => row.dueDate ?? '',
      defaultHidden: true,
      width: 130,
    },
    {
      id: 'status',
      label: `${FIELDS}.status`,
      value: (row) => row.status,
      sortable: true,
      width: 130,
    },
  ],
  filters: [
    {
      id: 'status',
      label: `${FIELDS}.status`,
      value: (row) => row.status,
      options: EXPENSE_STATUSES.map((status) => ({
        value: status,
        label: `expenses.statuses.${status}`,
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
  const status = EXPENSE_STATUSES.find((known) => known === query.filters['status']) ?? null;
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    status,
    vendorId: null,
    categoryId: null,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

/** A net amount above zero with no more decimals than the currency has. */
export function amountPattern(scale: number): string {
  return scale > 0 ? `(0|[1-9][0-9]{0,10})([.,][0-9]{1,${scale}})?` : '(0|[1-9][0-9]{0,10})';
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
 * The expense form, from what the company offers: its active vendors and categories and the rates on the net. The
 * API works the tax and the gross out and checks everything again. An expense keeps showing a vendor, category or
 * tax it was filed with after that one stops being offered.
 */
export function expenseForm(
  options: ExpenseOptions,
  current: ExpenseChoices | null = null,
): FormDescriptor {
  const descriptor = offeredExpenseForm(options);
  if (current === null) return descriptor;
  const chosen: Record<string, readonly [string | null, string]> = {
    vendorId: [current.vendorId, current.vendorName ?? current.vendorId ?? ''],
    categoryId: [current.categoryId, current.categoryName ?? current.categoryId ?? ''],
    taxComponentId: [
      current.taxComponentId,
      current.taxRate === null ? (current.taxComponentId ?? '') : `${Number(current.taxRate)} %`,
    ],
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
  'vendorId' | 'vendorName' | 'categoryId' | 'categoryName' | 'taxComponentId' | 'taxRate'
>;

/** The form over what the company offers today; a deactivated vendor or category is not among it. */
function offeredExpenseForm(options: ExpenseOptions): FormDescriptor {
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
          {
            id: 'vendorId',
            label: `${FIELDS}.vendorId`,
            kind: 'select',
            options: [
              none('expenses.form.no_vendor'),
              ...options.vendors.map((vendor) => ({
                value: vendor.id,
                label: `${vendor.number} · ${vendor.name}`,
              })),
            ],
            hint: 'expenses.form.vendor_hint',
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
        id: 'amounts',
        title: 'expenses.sections.amounts',
        fields: [
          {
            id: 'amountNet',
            label: `${FIELDS}.amountNet`,
            kind: 'text',
            required: true,
            maxLength: 16,
            pattern: amountPattern(options.currencyScale),
            hint: 'expenses.form.net_hint',
          },
          {
            id: 'taxComponentId',
            label: `${FIELDS}.taxComponentId`,
            kind: 'select',
            options: [
              none('expenses.form.no_tax'),
              ...options.taxes.map((tax) => ({
                value: tax.id,
                label: `${tax.name} (${Number(tax.rate)} %)`,
              })),
            ],
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
    categoryId: row?.categoryId ?? '',
    amountNet: row?.amountNet ?? '',
    taxComponentId: row?.taxComponentId ?? '',
    notes: row?.notes ?? '',
  };
}

/** The form's values as the API takes them: trimmed, an empty field as no value, a decimal comma as a point. */
export function expenseInput(values: FormValues): ExpenseInput {
  return {
    date: String(values['date'] ?? '').trim(),
    reference: text(values['reference']),
    description: String(values['description'] ?? '').trim(),
    vendorId: text(values['vendorId']),
    categoryId: text(values['categoryId']),
    amountNet: String(values['amountNet'] ?? '')
      .trim()
      .replace(',', '.'),
    taxComponentId: text(values['taxComponentId']),
    notes: text(values['notes']),
  };
}

/** How and when a recorded expense was paid. */
export function paymentForm(options: ExpenseOptions): FormDescriptor {
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
        ],
      },
    ],
  };
}

export function paymentValues(today: string): FormValues {
  return { paymentMethod: 'transfer', paidOn: today };
}

export function paymentInput(values: FormValues): ExpensePayment {
  return {
    paymentMethod: String(values['paymentMethod'] ?? 'transfer') as PaymentMethod,
    paidOn: String(values['paidOn'] ?? '').trim(),
  };
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
    },
    {
      id: 'status',
      label: `${CATEGORY_FIELDS}.isActive`,
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      sortable: true,
      width: 130,
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

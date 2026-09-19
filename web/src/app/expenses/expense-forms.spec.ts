// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  amountPattern,
  categoryForm,
  categoryInput,
  categoryLabels,
  categoryListRows,
  expenseForm,
  expenseInput,
  expenseSearch,
  expenseValues,
  EXPENSES_LIST,
  paymentInput,
} from './expense-forms';
import type { ExpenseCategoryRow, ExpenseOptions } from './expenses-types';

const vehicles: ExpenseCategoryRow = {
  id: 'k1',
  name: 'Véhicules',
  parentId: null,
  isActive: true,
};
const fuel: ExpenseCategoryRow = { id: 'k2', name: 'Carburant', parentId: 'k1', isActive: true };
const diesel: ExpenseCategoryRow = { id: 'k3', name: 'Gasoil', parentId: 'k2', isActive: false };
const office: ExpenseCategoryRow = { id: 'k4', name: 'Bureau', parentId: null, isActive: true };

const options: ExpenseOptions = {
  currency: 'TND',
  currencyScale: 3,
  vendors: [
    {
      id: 'v1',
      number: 'FRN-1',
      name: 'Sotumag',
      paymentTermsDays: 30,
      defaultExpenseCategoryId: 'k2',
    },
  ],
  categories: [vehicles, fuel],
  taxes: [{ id: 't1', code: 'TVA19', name: 'TVA', rate: '19.000' }],
  paymentMethods: ['transfer', 'cash'],
};

describe('expense forms', () => {
  describe('expenseSearch', () => {
    const query = {
      pageIndex: 0,
      pageSize: 25,
      query: '',
      filters: {} as Record<string, string>,
      sort: null,
    };

    it('asks for the page the list shows, numbered from one', () => {
      expect(expenseSearch({ ...query, pageIndex: 3, pageSize: 100 })).toMatchObject({
        page: 4,
        itemsPerPage: 100,
      });
    });

    it('passes a status it knows and drops one it does not', () => {
      expect(expenseSearch({ ...query, filters: { status: 'recorded' } }).status).toBe('recorded');
      expect(expenseSearch({ ...query, filters: { status: 'nonsense' } }).status).toBeNull();
    });

    it('passes the sort the column names, and leaves the API its own order otherwise', () => {
      expect(
        expenseSearch({ ...query, sort: { column: 'vendor', direction: 'asc' } }).order,
      ).toEqual({ key: 'vendor', direction: 'asc' });
      // The due day is worked out from the vendor's terms, so the API cannot order a page by it.
      expect(
        expenseSearch({ ...query, sort: { column: 'dueDate', direction: 'asc' } }).order,
      ).toBeNull();
    });

    it('offers no sort on a column the API cannot answer', () => {
      const dueDate = EXPENSES_LIST.columns.find((column) => column.id === 'dueDate');
      expect(dueDate?.sortable ?? false).toBe(false);
    });
  });

  it('accepts a net amount with no more decimals than the currency has', () => {
    const tnd = new RegExp(`^${amountPattern(3)}$`);
    expect(['0', '12', '12.5', '12.500', '10000000000.125'].every((value) => tnd.test(value))).toBe(
      true,
    );
    // A typed comma never reaches the control: the decimal field hands it over as a point.
    expect(['12.5000', '12,500', '-3', '012', '1e3', ''].some((value) => tnd.test(value))).toBe(
      false,
    );
    expect(new RegExp(`^${amountPattern(0)}$`).test('12.5')).toBe(false);
  });

  it('names each category under its parents, in the order the tree reads', () => {
    expect([...categoryLabels([diesel, office, fuel, vehicles])]).toEqual([
      ['k4', 'Bureau'],
      ['k1', 'Véhicules'],
      ['k2', 'Véhicules › Carburant'],
      ['k3', 'Véhicules › Carburant › Gasoil'],
    ]);
    // A broken parent chain still ends.
    expect(categoryLabels([{ ...vehicles, parentId: 'k2' }, fuel]).get('k2')).toContain(
      'Carburant',
    );
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: the amount shows and takes the locale's decimal separator.
  it('asks the net amount as a decimal', () => {
    const fields = expenseForm(options).sections.flatMap((section) => section.fields);
    expect(fields.filter((field) => field.kind === 'decimal').map((field) => field.id)).toEqual([
      'amountNet',
    ]);
  });

  it('offers the vendors, categories and rates the company has, each optional', () => {
    const fields = expenseForm(options).sections.flatMap((section) => section.fields);
    const byId = new Map(fields.map((field) => [field.id, field]));
    expect(byId.get('vendorId')?.options).toEqual([
      { value: '', label: 'expenses.form.no_vendor' },
      { value: 'v1', label: 'FRN-1 · Sotumag' },
    ]);
    expect(byId.get('categoryId')?.options?.map((option) => option.label)).toEqual([
      'expenses.form.no_category',
      'Véhicules',
      'Véhicules › Carburant',
    ]);
    expect(byId.get('taxComponentId')?.options?.[1]).toEqual({ value: 't1', label: 'TVA (19 %)' });
    expect(byId.get('amountNet')?.pattern).toBe(amountPattern(3));
  });

  it('dates a new expense today and sends empty fields as no value, a decimal comma as a point', () => {
    expect(expenseValues(null, '2026-09-15')).toMatchObject({ date: '2026-09-15', vendorId: '' });
    expect(
      expenseInput({
        date: '2026-09-10 ',
        reference: '  ',
        description: ' Gasoil ',
        vendorId: '',
        categoryId: 'k2',
        amountNet: ' 100.5 ',
        taxComponentId: '',
        notes: '',
      }),
    ).toEqual({
      date: '2026-09-10',
      reference: null,
      description: 'Gasoil',
      vendorId: null,
      categoryId: 'k2',
      amountNet: '100.5',
      taxComponentId: null,
      notes: null,
    });
    expect(paymentInput({ paymentMethod: 'check', paidOn: '2026-09-12' })).toEqual({
      paymentMethod: 'check',
      paidOn: '2026-09-12',
    });
  });

  it('never offers a category as the parent of itself or of one of its subcategories', () => {
    const all = [vehicles, fuel, diesel, office];
    const parents = (editing: ExpenseCategoryRow | null) =>
      categoryForm(all, editing)
        .sections[0]!.fields.find((field) => field.id === 'parentId')!
        .options!.map((option) => option.value);

    expect(parents(null)).toEqual(['', 'k4', 'k1', 'k2', 'k3']);
    expect(parents(fuel)).toEqual(['', 'k4', 'k1']);
    expect(parents(vehicles)).toEqual(['', 'k4']);
  });

  it('lists categories with their path and sends one back without its id', () => {
    expect(categoryListRows([vehicles, fuel]).map((row) => row.path)).toEqual([
      'Véhicules',
      'Véhicules › Carburant',
    ]);
    expect(categoryInput({ name: ' Péages ', parentId: '', isActive: false })).toEqual({
      name: 'Péages',
      parentId: null,
      isActive: false,
    });
  });

  it("still names an expense's vendor, category and tax once they are no longer offered", () => {
    const fields = (row: Parameters<typeof expenseForm>[1]) =>
      new Map(
        expenseForm(options, row)
          .sections.flatMap((section) => section.fields)
          .map((field) => [field.id, field.options?.map((option) => [option.value, option.label])]),
      );
    const kept = fields({
      vendorId: 'v7',
      vendorName: 'Ancien fournisseur',
      categoryId: 'k9',
      categoryName: 'Péages',
      taxComponentId: 't9',
      taxRate: '7.000',
    });
    expect(kept.get('vendorId')).toContainEqual(['v7', 'Ancien fournisseur']);
    expect(kept.get('categoryId')).toContainEqual(['k9', 'Péages']);
    expect(kept.get('taxComponentId')).toContainEqual(['t9', '7 %']);

    const offered = fields({
      vendorId: 'v1',
      vendorName: 'Sotumag',
      categoryId: 'k2',
      categoryName: 'Carburant',
      taxComponentId: null,
      taxRate: null,
    });
    expect(offered.get('vendorId')).toHaveLength(2);
    expect(offered.get('categoryId')).toHaveLength(3);
    expect(offered.get('taxComponentId')).toHaveLength(2);
  });
});

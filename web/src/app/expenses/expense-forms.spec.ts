// SPDX-License-Identifier: AGPL-3.0-or-later

import { applyFilters } from '../shared/list/list-view';
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
  EXPENSE_CATEGORIES_LIST,
  classifyForm,
  tejMonths,
  paymentForm,
  paymentInput,
  paymentValues,
} from './expense-forms';
import type { ExpenseCategoryRow, ExpenseOptions, ExpenseRow } from './expenses-types';

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
  categories: [vehicles, fuel],
  taxes: [{ id: 't1', code: 'TVA19', name: 'TVA', rate: '19.000' }],
  paymentMethods: ['transfer', 'cash'],
  withholdingOperationCodes: [],
};
const tunisian: ExpenseOptions = {
  ...options,
  withholdingOperationCodes: [
    { code: 'RS1_000001', label: 'Traitements et salaires' },
    { code: 'RS7_000006', label: 'Honoraires exonérés' },
  ],
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

    it('passes the statuses and ways of paying it knows, and drops what it does not', () => {
      expect(
        expenseSearch({ ...query, filters: { status: 'recorded,nonsense,paid' } }).status,
      ).toEqual(['recorded', 'paid']);
      expect(
        expenseSearch({ ...query, filters: { paymentMethod: 'cash,barter' } }).paymentMethods,
      ).toEqual(['cash']);
    });

    // Row 197: the facets, the vendor and category picks and the intervals all reach the API.
    it('passes whether something was withheld, the vendors and categories picked, and the intervals', () => {
      const id = '0199a1b2-0000-7000-8000-00000000000';
      const search = expenseSearch({
        ...query,
        filters: {
          withheld: 'yes',
          vendor: `${id}1,${id}2,not-an-id`,
          category: `${id}3`,
          'date.from': '2026-09-01',
          'date.to': '2026-09-30',
          'amountGross.min': '100',
          'amountGross.max': 'lots',
        },
      });
      expect(search.withheld).toBe('yes');
      expect(search.vendorIds).toEqual([`${id}1`, `${id}2`]);
      expect(search.categoryIds).toEqual([`${id}3`]);
      expect(search.intervals).toEqual({
        'date.from': '2026-09-01',
        'date.to': '2026-09-30',
        'amountGross.min': '100',
      });
      expect(expenseSearch({ ...query, filters: { withheld: 'maybe' } }).withheld).toBeNull();
    });

    it('offers the filters ruled for the list: several statuses and ways of paying, one answer on withholding', () => {
      const filters = Object.fromEntries(
        (EXPENSES_LIST.filters ?? []).map((filter) => [filter.id, filter.multiple ?? false]),
      );
      expect(filters).toEqual({ status: true, paymentMethod: true, withheld: false });
      expect(EXPENSES_LIST.picks?.map((pick) => pick.id)).toEqual(['vendor', 'category']);
      expect(EXPENSES_LIST.ranges?.map((range) => [range.id, range.kind])).toEqual([
        ['date', 'day'],
        ['amountGross', 'amount'],
      ]);
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

  it('offers the categories and rates the company has, each optional, and asks the vendor', () => {
    const fields = expenseForm(options).sections.flatMap((section) => section.fields);
    const byId = new Map(fields.map((field) => [field.id, field]));
    // A book of suppliers is not a dropdown: the vendor is asked for, so the descriptor carries no list of them.
    expect(byId.get('vendorId')?.kind).toBe('pick');
    expect(byId.get('vendorId')?.options).toBeUndefined();
    expect(byId.get('vendorId')?.noneLabel).toBe('expenses.form.no_vendor');
    expect(byId.get('categoryId')?.options?.map((option) => option.label)).toEqual([
      'expenses.form.no_category',
      'Véhicules',
      'Véhicules › Carburant',
    ]);
    expect(byId.get('taxComponentId')?.options?.[1]).toEqual({ value: 't1', label: 'TVA (19 %)' });
    expect(byId.get('amountNet')?.pattern).toBe(amountPattern(3));
  });

  it('asks who was paid, in words, only while no vendor is picked', () => {
    const fields = expenseForm(options).sections.flatMap((section) => section.fields);
    const payee = fields.find((field) => field.id === 'payee');
    expect(payee).toMatchObject({ kind: 'text', maxLength: 160, hint: 'expenses.form.payee_hint' });
    expect(payee?.visibleWhen).toEqual({ field: 'vendorId', oneOf: [''] });
    // Right after the vendor, where the eye looks for who was paid.
    const ids = fields.map((field) => field.id);
    expect(ids.indexOf('payee')).toBe(ids.indexOf('vendorId') + 1);
  });

  it('asks no vendor while the Vendors module is off, and who was paid unconditionally', () => {
    const fields = expenseForm(options, null, false).sections.flatMap((section) => section.fields);
    expect(fields.some((field) => field.id === 'vendorId')).toBe(false);
    expect(fields.find((field) => field.id === 'payee')?.visibleWhen).toBeUndefined();
  });

  it('sends a payee only when no vendor is named, and the vendor a draft keeps when the form asks none', () => {
    const typed = {
      date: '2026-09-10',
      description: 'Stationnement',
      amountNet: '4',
      payee: ' Parking Lafayette ',
    };
    // A hidden field keeps what was typed in it: picking a vendor after typing a payee must not send both.
    expect(expenseInput({ ...typed, vendorId: 'v1' })).toMatchObject({
      vendorId: 'v1',
      payee: null,
    });
    expect(expenseInput({ ...typed, vendorId: '' })).toMatchObject({
      vendorId: null,
      payee: 'Parking Lafayette',
    });
    // Vendors switched off: the form has no vendor field, and the draft's own vendor is sent back as it was.
    expect(expenseInput(typed, 'v7')).toMatchObject({ vendorId: 'v7', payee: null });
    expect(expenseInput(typed)).toMatchObject({ vendorId: null, payee: 'Parking Lafayette' });
    expect(expenseValues(null, '2026-09-15')).toMatchObject({ payee: '' });
  });

  it('shows the vendor in the list, else who was paid', () => {
    const paidTo = EXPENSES_LIST.columns.find((column) => column.id === 'vendor');
    expect(paidTo?.label).toBe('expenses.fields.payee');
    const row = { vendorName: null, payee: 'Parking Lafayette' } as ExpenseRow;
    expect(paidTo?.value(row)).toBe('Parking Lafayette');
    expect(paidTo?.value({ ...row, vendorName: 'Sotumag' })).toBe('Sotumag');
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
      payee: null,
      categoryId: 'k2',
      amountNet: '100.5',
      taxComponentId: null,
      notes: null,
    });
    expect(paymentInput({ paymentMethod: 'check', paidOn: '2026-09-12' })).toEqual({
      paymentMethod: 'check',
      paidOn: '2026-09-12',
      withholdingRate: '0',
    });
  });

  // docs/SPEC.md § 7, 2026-09-26 00:22: the TEJ operation is chosen by a person, from the administration's own list.
  it('asks for the TEJ operation on a payment only where the company declares to TEJ', () => {
    const ids = (descriptor: ReturnType<typeof paymentForm>) =>
      descriptor.sections.flatMap((section) => section.fields.map((field) => field.id));
    expect(ids(paymentForm(options))).not.toContain('withholdingOperationCode');

    const field = paymentForm(tunisian)
      .sections.flatMap((section) => section.fields)
      .find((each) => each.id === 'withholdingOperationCode');
    expect(field?.kind).toBe('select');
    expect(field?.required).toBeFalsy();
    expect(field?.options).toEqual([
      { value: 'RS1_000001', label: 'RS1_000001 — Traitements et salaires' },
      { value: 'RS7_000006', label: 'RS7_000006 — Honoraires exonérés' },
    ]);

    const chosen = { paymentMethod: 'transfer', paidOn: '2026-09-15', withholdingRate: '' };
    expect(paymentInput({ ...chosen, withholdingOperationCode: 'RS7_000006' })).toEqual({
      paymentMethod: 'transfer',
      paidOn: '2026-09-15',
      withholdingRate: '0',
      withholdingOperationCode: 'RS7_000006',
    });
    // Nothing chosen is nothing sent: outside Tunisia the API refuses any code at all.
    expect(paymentInput({ ...chosen, withholdingOperationCode: '' })).not.toHaveProperty(
      'withholdingOperationCode',
    );

    const classify = classifyForm(tunisian).sections[0]?.fields[0];
    expect(classify).toMatchObject({
      id: 'withholdingOperationCode',
      kind: 'select',
      required: true,
    });
  });

  // The TEJ file is declared for a month that is over: the one before today's first, then a year back.
  it('offers the twelve months that are over, the latest first, across a new year', () => {
    const months = tejMonths('2026-09-26');
    expect(months).toHaveLength(12);
    expect(months[0]).toBe('2026-08');
    expect(months[11]).toBe('2025-09');
    expect(tejMonths('2027-01-01').slice(0, 2)).toEqual(['2026-12', '2026-11']);
  });

  // docs/SPEC.md § 7, 2026-09-24 11:40 (RPT-09): the withholding on a supplier, said on the payment.
  it('proposes the withholding the API suggests, and sends an emptied one as none', () => {
    const field = paymentForm(options).sections[0]?.fields.find(
      (each) => each.id === 'withholdingRate',
    );
    expect(field?.kind).toBe('decimal');
    expect(paymentValues('2026-09-15', '1.000')).toEqual({
      paymentMethod: 'transfer',
      paidOn: '2026-09-15',
      withholdingRate: '1',
    });
    expect(paymentValues('2026-09-15', null)['withholdingRate']).toBe('');
    expect(
      paymentInput({ paymentMethod: 'transfer', paidOn: '2026-09-15', withholdingRate: ' 1.5 ' })
        .withholdingRate,
    ).toBe('1.5');
    expect(
      paymentInput({ paymentMethod: 'transfer', paidOn: '2026-09-15', withholdingRate: '' })
        .withholdingRate,
    ).toBe('0');
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

  it("still names an expense's category and tax once they are no longer offered", () => {
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
    // The vendor is a picker: what the expense says is shown by the page, not added to a list of options here.
    expect(kept.get('vendorId')).toBeUndefined();
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
    expect(offered.get('categoryId')).toHaveLength(3);
    expect(offered.get('taxComponentId')).toHaveLength(2);
  });
});

describe('the expense categories list', () => {
  it('narrows by whether a category is still offered', () => {
    const rows = categoryListRows([vehicles, fuel, diesel, office]);
    expect(
      applyFilters(rows, EXPENSE_CATEGORIES_LIST.filters ?? [], { status: 'inactive' }).map(
        (row) => row.id,
      ),
    ).toEqual([diesel.id]);
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { ExpensesApi, ExpensesRefused } from './expenses-api';
import { ExpensesFacade } from './expenses-facade';
import type { ExpenseAttachment, ExpenseInput, ExpenseOptions, ExpenseRow } from './expenses-types';

const input: ExpenseInput = {
  date: '2026-09-10',
  reference: null,
  description: 'Gasoil',
  vendorId: null,
  categoryId: 'k1',
  amountNet: '100.000',
  taxComponentId: null,
  notes: null,
};
const draft: ExpenseRow = {
  ...input,
  id: 'e1',
  status: 'draft',
  vendorName: null,
  categoryName: 'Carburant',
  taxRate: null,
  taxAmount: '0.000',
  amountGross: '100.000',
  currency: 'TND',
  dueDate: null,
  paymentMethod: null,
  paidOn: null,
  attachmentCount: 0,
};
const receipt: ExpenseAttachment = {
  id: 'a1',
  name: 'recu.pdf',
  mime: 'application/pdf',
  size: 1200,
  createdAt: '2026-09-15T08:00:00+00:00',
};
const options: ExpenseOptions = {
  currency: 'TND',
  currencyScale: 3,
  vendors: [],
  categories: [],
  taxes: [],
  paymentMethods: ['transfer'],
};

describe('ExpensesFacade', () => {
  const api = {
    options: vi.fn(),
    expenses: vi.fn(),
    expense: vi.fn(),
    createExpense: vi.fn(),
    reviseExpense: vi.fn(),
    recordExpense: vi.fn(),
    payExpense: vi.fn(),
    deleteExpense: vi.fn(),
    attachments: vi.fn(),
    attach: vi.fn(),
    detach: vi.fn(),
    attachmentUrl: vi.fn(),
    categories: vi.fn(),
    createCategory: vi.fn(),
    reviseCategory: vi.fn(),
  };
  let facade: ExpensesFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    TestBed.configureTestingModule({ providers: [{ provide: ExpensesApi, useValue: api }] });
    facade = TestBed.inject(ExpensesFacade);
  });

  it('reads only the options for a new expense, and the expense with its files for an existing one', async () => {
    api.options.mockResolvedValue(options);
    api.expense.mockResolvedValue(draft);
    api.attachments.mockResolvedValue([receipt]);

    await facade.loadExpense('c1', null);
    expect(api.expense).not.toHaveBeenCalled();
    expect([facade.options(), facade.expense(), facade.attachments()]).toEqual([options, null, []]);

    await facade.loadExpense('c1', 'e1');
    expect([facade.expense(), facade.attachments()]).toEqual([draft, [receipt]]);
    expect(facade.error()).toBeNull();
  });

  it('reads the files and the count again after a file is attached or removed', async () => {
    api.attach.mockResolvedValue(receipt);
    api.attachments.mockResolvedValue([receipt]);
    api.expense.mockResolvedValue({ ...draft, attachmentCount: 1 });
    const file = new File(['%PDF'], 'recu.pdf');

    expect(await facade.attach('c1', 'e1', file)).toBe(true);
    expect(api.attach).toHaveBeenCalledWith('c1', 'e1', file);
    expect(facade.attachments()).toEqual([receipt]);
    expect(facade.expense()?.attachmentCount).toBe(1);

    api.detach.mockRejectedValue(new ExpensesRefused('not_draft'));
    expect(await facade.detach('c1', 'e1', 'a1')).toBe(false);
    expect(facade.error()).toBe('not_draft');
    expect(facade.busy()).toBe(false);
  });

  it('keeps the expense the API answered after each step, and the reason when it refused', async () => {
    api.recordExpense.mockResolvedValue({ ...draft, status: 'recorded' });
    expect((await facade.recordExpense('c1', 'e1'))?.status).toBe('recorded');
    expect(facade.expense()?.status).toBe('recorded');

    api.payExpense.mockRejectedValue(new ExpensesRefused('invalid'));
    expect(
      await facade.payExpense('c1', 'e1', { paymentMethod: 'cash', paidOn: '2026-09-01' }),
    ).toBeNull();
    expect(facade.error()).toBe('invalid');
    expect(facade.expense()?.status).toBe('recorded');

    api.createExpense.mockRejectedValue(new Error('offline'));
    expect(await facade.createExpense('c1', input)).toBeNull();
    expect(facade.error()).toBe('network');
  });

  it('forgets a deleted expense and reads the categories again after a change', async () => {
    api.expense.mockResolvedValue(draft);
    api.options.mockResolvedValue(options);
    api.attachments.mockResolvedValue([]);
    await facade.loadExpense('c1', 'e1');
    api.deleteExpense.mockResolvedValue(undefined);
    expect(await facade.deleteExpense('c1', 'e1')).toBe(true);
    expect(facade.expense()).toBeNull();

    api.createCategory.mockResolvedValue({
      id: 'k1',
      name: 'Carburant',
      parentId: null,
      isActive: true,
    });
    api.categories.mockResolvedValue([
      { id: 'k1', name: 'Carburant', parentId: null, isActive: true },
    ]);
    expect(
      await facade.createCategory('c1', { name: 'Carburant', parentId: null, isActive: true }),
    ).toBe(true);
    expect(facade.categories().map((category) => category.name)).toEqual(['Carburant']);
  });
});

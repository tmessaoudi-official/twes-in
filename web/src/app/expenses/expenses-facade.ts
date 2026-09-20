// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { ExpensesApi, ExpensesRefused } from './expenses-api';
import type { PickAsked } from '../shared/form/pick-api';
import type {
  ExpenseAttachment,
  ExpenseCategoryInput,
  ExpenseCategoryRow,
  ExpenseInput,
  ExpenseOptions,
  ExpensePayment,
  ExpenseRow,
  ExpenseSearch,
  ExpensesError,
  ExpenseVendorOption,
} from './expenses-types';

/** The expenses of the company being worked in, their categories, and the expense open with its files. */
@Injectable({ providedIn: 'root' })
export class ExpensesFacade {
  private readonly api = inject(ExpensesApi);

  private readonly expensesSignal = signal<readonly ExpenseRow[]>([]);
  private readonly optionsSignal = signal<ExpenseOptions | null>(null);
  private readonly expenseSignal = signal<ExpenseRow | null>(null);
  private readonly attachmentsSignal = signal<readonly ExpenseAttachment[]>([]);
  private readonly categoriesSignal = signal<readonly ExpenseCategoryRow[]>([]);
  private readonly totalSignal = signal(0);
  private pageRequest = 0;
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ExpensesError | null>(null);

  readonly expenses = this.expensesSignal.asReadonly();
  /** How many expenses the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  readonly expense = this.expenseSignal.asReadonly();
  readonly attachments = this.attachmentsSignal.asReadonly();
  readonly categories = this.categoriesSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /**
   * One page of the expenses the search finds. Only the latest search's answer is shown: typing sends one search per
   * keystroke and they need not come back in order.
   */
  async loadPage(companyId: string, search: ExpenseSearch): Promise<void> {
    const request = ++this.pageRequest;
    await this.read(async () => {
      const page = await this.api.expenses(companyId, search);
      if (request !== this.pageRequest) return;
      this.expensesSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  /** What the expense screen needs: its options, and the expense with its files unless it is new. */
  async loadExpense(companyId: string, id: string | null): Promise<void> {
    await this.read(async () => {
      const [options, expense, attachments] = await Promise.all([
        this.api.options(companyId),
        id === null ? Promise.resolve(null) : this.api.expense(companyId, id),
        id === null ? Promise.resolve([]) : this.api.attachments(companyId, id),
      ]);
      this.optionsSignal.set(options);
      this.expenseSignal.set(expense);
      this.attachmentsSignal.set(attachments);
    });
  }

  async loadCategories(companyId: string): Promise<void> {
    await this.read(async () => this.categoriesSignal.set(await this.api.categories(companyId)));
  }

  /** The expense as the API kept it, or null with the reason in `error`. */
  async createExpense(companyId: string, input: ExpenseInput): Promise<ExpenseRow | null> {
    return this.save(() => this.api.createExpense(companyId, input));
  }

  async reviseExpense(
    companyId: string,
    id: string,
    input: ExpenseInput,
  ): Promise<ExpenseRow | null> {
    return this.save(() => this.api.reviseExpense(companyId, id, input));
  }

  async recordExpense(companyId: string, id: string): Promise<ExpenseRow | null> {
    return this.save(() => this.api.recordExpense(companyId, id));
  }

  async payExpense(
    companyId: string,
    id: string,
    payment: ExpensePayment,
  ): Promise<ExpenseRow | null> {
    return this.save(() => this.api.payExpense(companyId, id, payment));
  }

  async deleteExpense(companyId: string, id: string): Promise<boolean> {
    return this.write(
      () => this.api.deleteExpense(companyId, id),
      async () => this.expenseSignal.set(null),
    );
  }

  /** Attaches a file, then reads the expense's files and count again. */
  async attach(companyId: string, expenseId: string, file: File): Promise<boolean> {
    return this.write(
      () => this.api.attach(companyId, expenseId, file),
      () => this.reloadFiles(companyId, expenseId),
    );
  }

  async detach(companyId: string, expenseId: string, attachmentId: string): Promise<boolean> {
    return this.write(
      () => this.api.detach(companyId, expenseId, attachmentId),
      () => this.reloadFiles(companyId, expenseId),
    );
  }

  attachmentUrl(companyId: string, expenseId: string, attachmentId: string): string {
    return this.api.attachmentUrl(companyId, expenseId, attachmentId);
  }

  async createCategory(companyId: string, input: ExpenseCategoryInput): Promise<boolean> {
    return this.write(
      () => this.api.createCategory(companyId, input),
      async () => this.categoriesSignal.set(await this.api.categories(companyId)),
    );
  }

  async reviseCategory(
    companyId: string,
    id: string,
    input: ExpenseCategoryInput,
  ): Promise<boolean> {
    return this.write(
      () => this.api.reviseCategory(companyId, id, input),
      async () => this.categoriesSignal.set(await this.api.categories(companyId)),
    );
  }

  /**
   * The few vendors a person means while typing, and — by id — the one an expense already names, active or not. A
   * search that fails answers nothing and says so in `error`, rather than reading as "no such vendor".
   */
  async pickVendors(companyId: string, asked: PickAsked): Promise<ExpenseVendorOption[]> {
    // A picker never marks the screen busy, because a person is typing while it runs.
    try {
      return await this.api.pickVendors(companyId, asked);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return [];
    }
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async reloadFiles(companyId: string, expenseId: string): Promise<void> {
    const [attachments, expense] = await Promise.all([
      this.api.attachments(companyId, expenseId),
      this.api.expense(companyId, expenseId),
    ]);
    this.attachmentsSignal.set(attachments);
    this.expenseSignal.set(expense);
  }

  private async read(load: () => Promise<void>): Promise<void> {
    this.busySignal.set(true);
    try {
      await load();
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  private async save(call: () => Promise<ExpenseRow>): Promise<ExpenseRow | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const expense = await call();
      this.expenseSignal.set(expense);
      return expense;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }

  private async write(call: () => Promise<unknown>, reload: () => Promise<void>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      await reload();
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): ExpensesError {
  return error instanceof ExpensesRefused ? error.code : 'network';
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { LatestRequest, requestState } from '../shared/feedback/request-state';
import { inject, Injectable, signal } from '@angular/core';
import { ExpensesApi, ExpensesRefused } from './expenses-api';
import type { FieldRefusal } from '../shared/form/field-refusals';
import type { PickAsked } from '../shared/form/pick-api';
import type { ExportFormat } from '../shared/list/export-address';
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
  ExpenseStatusCounts,
  ExpenseSummary,
  ExpenseVendorOption,
  TejFileAnswer,
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
  private readonly statusCountsSignal = signal<ExpenseStatusCounts | null>(null);
  private readonly summarySignal = signal<ExpenseSummary | null>(null);
  private readonly pageRequests = new LatestRequest();
  private readonly countsRequests = new LatestRequest();
  private readonly state = requestState(codeOf);
  private readonly refusalsSignal = signal<readonly FieldRefusal[]>([]);

  readonly expenses = this.expensesSignal.asReadonly();
  /** How many expenses the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  /** What each status chip of the list would show; null until read, and kept while a new count is on its way. */
  readonly statusCounts = this.statusCountsSignal.asReadonly();
  /** What the home shows of the expenses; null until read, and kept when a later read fails. */
  readonly summary = this.summarySignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  readonly expense = this.expenseSignal.asReadonly();
  readonly attachments = this.attachmentsSignal.asReadonly();
  readonly categories = this.categoriesSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  /** The fields the last save or recording was refused on, for the form and the bill to say under each. */
  readonly refusals = this.refusalsSignal.asReadonly();

  exportUrl(companyId: string, search: ExpenseSearch, format: ExportFormat): string {
    return this.api.exportUrl(companyId, search, format);
  }

  /**
   * One page of the expenses the search finds. Only the latest search's answer is shown: typing sends one search per
   * keystroke and they need not come back in order.
   */
  async loadPage(companyId: string, search: ExpenseSearch): Promise<void> {
    const current = this.pageRequests.next();
    await this.read(async () => {
      const page = await this.api.expenses(companyId, search);
      if (!current()) return;
      this.expensesSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  /** The chips' counts for the search, the latest search's answer only, as for its page. */
  async loadStatusCounts(companyId: string, search: ExpenseSearch): Promise<void> {
    const current = this.countsRequests.next();
    await this.read(async () => {
      const counts = await this.api.statusCounts(companyId, search);
      if (current()) this.statusCountsSignal.set(counts);
    });
  }

  /** The home's figure; a failed read leaves the last one, since the panel is secondary to the page it sits on. */
  async loadSummary(companyId: string): Promise<void> {
    try {
      this.summarySignal.set(await this.api.summary(companyId));
    } catch {
      /* the last summary stays */
    }
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
    version: number | null,
  ): Promise<ExpenseRow | null> {
    return this.save(() => this.api.reviseExpense(companyId, id, input, version));
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

  /** The options alone, for a screen that shows no expense: the list asks whether the company declares to TEJ. */
  async loadOptions(companyId: string): Promise<void> {
    if (this.optionsSignal() !== null) return;
    try {
      this.optionsSignal.set(await this.api.options(companyId));
    } catch (error) {
      this.state.fail(error);
    }
  }

  /** A month's TEJ file, or why it cannot be written; null when the call failed, with the error said. */
  async tejFile(companyId: string, month: string): Promise<TejFileAnswer | null> {
    return this.state.writeAnswer(() => this.api.tejFile(companyId, month));
  }

  async classifyWithholding(
    companyId: string,
    id: string,
    code: string,
  ): Promise<ExpenseRow | null> {
    return this.save(() => this.api.classifyWithholding(companyId, id, code));
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

  /**
   * A file taken off put back where it was: what « Annuler » on its toast does. The toast outlives its page, so the
   * expense is read again only while it is still the one open, and a refusal is returned for the toast to say rather
   * than written on whatever page is open now. Null once back.
   */
  async restoreAttachment(
    companyId: string,
    expenseId: string,
    attachmentId: string,
  ): Promise<ExpensesError | null> {
    try {
      await this.api.restoreAttachment(companyId, expenseId, attachmentId);
    } catch (error) {
      return codeOf(error);
    }
    if (this.expenseSignal()?.id === expenseId) {
      await this.write(
        async () => undefined,
        () => this.reloadFiles(companyId, expenseId),
      );
    }
    return null;
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
    version: number | null,
  ): Promise<boolean> {
    return this.write(
      () => this.api.reviseCategory(companyId, id, input, version),
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
      this.state.fail(error);
      return [];
    }
  }

  clearError(): void {
    this.state.clearError();
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
    await this.state.read(load);
  }

  private async save(call: () => Promise<ExpenseRow>): Promise<ExpenseRow | null> {
    this.refusalsSignal.set([]);
    return this.state.writeAnswer(
      async () => {
        const expense = await call();
        this.expenseSignal.set(expense);
        return expense;
      },
      {
        failed: (error) =>
          this.refusalsSignal.set(error instanceof ExpensesRefused ? error.refusals : []),
      },
    );
  }

  private async write(call: () => Promise<unknown>, reload: () => Promise<void>): Promise<boolean> {
    return this.state.write(async () => {
      await call();
      await reload();
    });
  }
}

function codeOf(error: unknown): ExpensesError {
  return error instanceof ExpensesRefused ? error.code : 'network';
}

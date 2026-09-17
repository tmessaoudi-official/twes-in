// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdexpensesGetCollectionResponse,
  ExpenseAttachmentExpenseAttachmentRead,
  ExpenseCategoryExpenseCategoryRead,
  ExpenseCategoryExpenseCategoryWrite,
  ExpenseExpensePay,
  ExpenseExpenseRead,
  ExpenseExpenseWrite,
  ExpenseJsonldExpenseRead,
  ExpenseOptionsExpenseOptionsRead,
} from '../api/types.gen';
import type { ListPage } from '../shared/list/list-types';
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
} from './expenses-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class ExpensesRefused extends Error {
  constructor(readonly code: ExpensesError) {
    super(code);
  }
}

/** What a 409 and a 422 mean for the call that received them. */
interface Refusals {
  conflict: ExpensesError;
  invalid: ExpensesError;
}

const EXPENSE: Refusals = { conflict: 'not_draft', invalid: 'invalid' };
const CATEGORY: Refusals = { conflict: 'name_taken', invalid: 'invalid' };
const FILE: Refusals = { conflict: 'not_draft', invalid: 'file_refused' };

/** The HTTP edge of the expenses feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class ExpensesApi {
  private readonly http = inject(HttpClient);

  async options(companyId: string): Promise<ExpenseOptions> {
    return this.guard(EXPENSE, async () =>
      toOptions(
        await firstValueFrom(
          this.http.get<ExpenseOptionsExpenseOptionsRead>(path(companyId, 'expense-options')),
        ),
      ),
    );
  }

  /** One page of the company's expenses, searched, narrowed and sorted by the API. */
  async expenses(companyId: string, search: ExpenseSearch): Promise<ListPage<ExpenseRow>> {
    return this.guard(EXPENSE, async () => {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIdexpensesGetCollectionResponse>(
          path(companyId, 'expenses'),
          { headers: { Accept: 'application/ld+json' }, params: toSearchParams(search) },
        ),
      );
      if (page.totalItems === undefined)
        throw new Error('A page of expenses came without its total.');
      return { rows: page.member.map(toExpense), total: page.totalItems };
    });
  }

  async expense(companyId: string, id: string): Promise<ExpenseRow> {
    return this.guard(EXPENSE, async () =>
      toExpense(
        await firstValueFrom(this.http.get<ExpenseExpenseRead>(path(companyId, 'expenses', id))),
      ),
    );
  }

  /** 422 naming the field the API refused. */
  async createExpense(companyId: string, input: ExpenseInput): Promise<ExpenseRow> {
    return this.guard(EXPENSE, async () =>
      toExpense(
        await firstValueFrom(
          this.http.post<ExpenseExpenseRead>(path(companyId, 'expenses'), toExpenseBody(input)),
        ),
      ),
    );
  }

  /** 409 once the expense is no longer a draft. */
  async reviseExpense(companyId: string, id: string, input: ExpenseInput): Promise<ExpenseRow> {
    return this.guard(EXPENSE, async () =>
      toExpense(
        await firstValueFrom(
          this.http.put<ExpenseExpenseRead>(path(companyId, 'expenses', id), toExpenseBody(input)),
        ),
      ),
    );
  }

  async deleteExpense(companyId: string, id: string): Promise<void> {
    await this.guard(EXPENSE, async () =>
      firstValueFrom(this.http.delete(path(companyId, 'expenses', id))),
    );
  }

  /** 422 while the expense has no category. */
  async recordExpense(companyId: string, id: string): Promise<ExpenseRow> {
    return this.guard(EXPENSE, async () =>
      toExpense(
        await firstValueFrom(
          this.http.post<ExpenseExpenseRead>(`${path(companyId, 'expenses', id)}/record`, null),
        ),
      ),
    );
  }

  /** 422 for a day before the expense's or after today. */
  async payExpense(companyId: string, id: string, payment: ExpensePayment): Promise<ExpenseRow> {
    const body: ExpenseExpensePay = { ...payment };
    return this.guard(EXPENSE, async () =>
      toExpense(
        await firstValueFrom(
          this.http.post<ExpenseExpenseRead>(`${path(companyId, 'expenses', id)}/pay`, body),
        ),
      ),
    );
  }

  async categories(companyId: string): Promise<ExpenseCategoryRow[]> {
    return this.guard(CATEGORY, async () =>
      (
        await firstValueFrom(
          this.http.get<ExpenseCategoryExpenseCategoryRead[]>(
            path(companyId, 'expense-categories'),
          ),
        )
      ).map(toCategory),
    );
  }

  /** 409 for a name another category has; 422 for a parent that would make a cycle. */
  async createCategory(
    companyId: string,
    input: ExpenseCategoryInput,
  ): Promise<ExpenseCategoryRow> {
    const body: ExpenseCategoryExpenseCategoryWrite = { ...input };
    return this.guard(CATEGORY, async () =>
      toCategory(
        await firstValueFrom(
          this.http.post<ExpenseCategoryExpenseCategoryRead>(
            path(companyId, 'expense-categories'),
            body,
          ),
        ),
      ),
    );
  }

  async reviseCategory(
    companyId: string,
    id: string,
    input: ExpenseCategoryInput,
  ): Promise<ExpenseCategoryRow> {
    const body: ExpenseCategoryExpenseCategoryWrite = { ...input };
    return this.guard(CATEGORY, async () =>
      toCategory(
        await firstValueFrom(
          this.http.put<ExpenseCategoryExpenseCategoryRead>(
            path(companyId, 'expense-categories', id),
            body,
          ),
        ),
      ),
    );
  }

  async attachments(companyId: string, expenseId: string): Promise<ExpenseAttachment[]> {
    return this.guard(FILE, async () =>
      (
        await firstValueFrom(
          this.http.get<ExpenseAttachmentExpenseAttachmentRead[]>(
            attachmentsPath(companyId, expenseId),
          ),
        )
      ).map(toAttachment),
    );
  }

  /** A multipart part named `file`; 422 for a file the API does not keep, 413 when the proxy finds it too large. */
  async attach(companyId: string, expenseId: string, file: File): Promise<ExpenseAttachment> {
    const body = new FormData();
    body.append('file', file, file.name);
    return this.guard(FILE, async () =>
      toAttachment(
        await firstValueFrom(
          this.http.post<ExpenseAttachmentExpenseAttachmentRead>(
            attachmentsPath(companyId, expenseId),
            body,
          ),
        ),
      ),
    );
  }

  /** 409 once the expense is recorded: what it rests on stays. */
  async detach(companyId: string, expenseId: string, attachmentId: string): Promise<void> {
    await this.guard(FILE, async () =>
      firstValueFrom(
        this.http.delete(
          `${attachmentsPath(companyId, expenseId)}/${encodeURIComponent(attachmentId)}`,
        ),
      ),
    );
  }

  /** Where the browser opens a file: a same-origin address the session cookie reaches. */
  attachmentUrl(companyId: string, expenseId: string, attachmentId: string): string {
    return `${attachmentsPath(companyId, expenseId)}/${encodeURIComponent(attachmentId)}/content`;
  }

  private async guard<T>(refusals: Refusals, call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new ExpensesRefused(codeOf(error, refusals));
    }
  }
}

function codeOf(error: unknown, refusals: Refusals): ExpensesError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return refusals.conflict;
    case 413:
      return 'file_too_large';
    default:
      return refusals.invalid;
  }
}

const path = (companyId: string, collection: string, id?: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/${collection}${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

const attachmentsPath = (companyId: string, expenseId: string): string =>
  `${path(companyId, 'expenses', expenseId)}/attachments`;

function toExpense(raw: ExpenseExpenseRead | ExpenseJsonldExpenseRead): ExpenseRow {
  return {
    id: raw.id ?? '',
    status: raw.status ?? 'draft',
    date: raw.date ?? '',
    reference: raw.reference ?? null,
    description: raw.description ?? '',
    vendorId: raw.vendorId ?? null,
    vendorName: raw.vendorName ?? null,
    categoryId: raw.categoryId ?? null,
    categoryName: raw.categoryName ?? null,
    amountNet: raw.amountNet ?? '',
    taxComponentId: raw.taxComponentId ?? null,
    taxRate: raw.taxRate ?? null,
    taxAmount: raw.taxAmount ?? '',
    amountGross: raw.amountGross ?? '',
    currency: raw.currency ?? '',
    dueDate: raw.dueDate ?? null,
    paymentMethod: raw.paymentMethod ?? null,
    paidOn: raw.paidOn ?? null,
    notes: raw.notes ?? null,
    attachmentCount: raw.attachmentCount ?? 0,
  };
}

/** Only what the search asks for: an absent parameter is the API's own default, never an empty one. */
function toSearchParams(search: ExpenseSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  if (search.status !== null) params = params.set('status', search.status);
  if (search.vendorId !== null) params = params.set('vendorId', search.vendorId);
  if (search.categoryId !== null) params = params.set('categoryId', search.categoryId);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

function toExpenseBody(input: ExpenseInput): ExpenseExpenseWrite {
  return { ...input };
}

function toCategory(raw: ExpenseCategoryExpenseCategoryRead): ExpenseCategoryRow {
  return {
    id: raw.id ?? '',
    name: raw.name ?? '',
    parentId: raw.parentId ?? null,
    isActive: raw.isActive ?? true,
  };
}

function toAttachment(raw: ExpenseAttachmentExpenseAttachmentRead): ExpenseAttachment {
  return {
    id: raw.id ?? '',
    name: raw.name ?? '',
    mime: raw.mime ?? '',
    size: raw.size ?? 0,
    createdAt: raw.createdAt ?? '',
  };
}

function toOptions(raw: ExpenseOptionsExpenseOptionsRead): ExpenseOptions {
  return {
    currency: raw.currency ?? '',
    currencyScale: raw.currencyScale ?? 2,
    vendors: (raw.vendors ?? []).map((vendor) => ({ ...vendor })),
    categories: (raw.categories ?? []).map((category) => ({ ...category })),
    taxes: (raw.taxes ?? []).map((tax) => ({ ...tax })),
    paymentMethods: [...(raw.paymentMethods ?? [])],
  };
}

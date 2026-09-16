// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import type { FormGroup } from '@angular/forms';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { ExpensePage } from './expense-page';
import { ExpensesFacade } from './expenses-facade';
import type {
  ExpenseAttachment,
  ExpenseOptions,
  ExpenseRow,
  ExpensesError,
} from './expenses-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      expenses: {
        saved: 'La dépense a été enregistrée.',
        errors: { not_draft: 'Seule une dépense en brouillon peut être modifiée.' },
      },
    });
  }
}

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
  categories: [
    { id: 'k1', name: 'Véhicules', parentId: null },
    { id: 'k2', name: 'Carburant', parentId: 'k1' },
  ],
  taxes: [{ id: 't1', code: 'TVA19', name: 'TVA', rate: '19.000' }],
  paymentMethods: ['transfer', 'cash'],
};
const draft: ExpenseRow = {
  id: 'e1',
  status: 'draft',
  date: '2026-09-10',
  reference: null,
  description: 'Gasoil',
  vendorId: 'v1',
  vendorName: 'Sotumag',
  categoryId: 'k2',
  categoryName: 'Carburant',
  amountNet: '100.000',
  taxComponentId: 't1',
  taxRate: '19.000',
  taxAmount: '19.000',
  amountGross: '119.000',
  currency: 'TND',
  dueDate: '2026-10-10',
  paymentMethod: null,
  paidOn: null,
  notes: null,
  attachmentCount: 1,
};
const receipt: ExpenseAttachment = {
  id: 'a1',
  name: 'recu.pdf',
  mime: 'application/pdf',
  size: 2048,
  createdAt: '2026-09-15T08:00:00+00:00',
};

describe('ExpensePage', () => {
  const error = signal<ExpensesError | null>(null);
  const expense = signal<ExpenseRow | null>(null);
  const attachments = signal<readonly ExpenseAttachment[]>([]);
  const facade = {
    options: signal<ExpenseOptions | null>(options).asReadonly(),
    expense: expense.asReadonly(),
    attachments: attachments.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadExpense: vi.fn(),
    createExpense: vi.fn(),
    reviseExpense: vi.fn(),
    recordExpense: vi.fn(),
    payExpense: vi.fn(),
    deleteExpense: vi.fn(),
    attach: vi.fn(),
    detach: vi.fn(),
    attachmentUrl: (companyId: string, expenseId: string, attachmentId: string) =>
      `/api/companies/${companyId}/expenses/${expenseId}/attachments/${attachmentId}/content`,
  };
  const auth = {
    // New York is behind both UTC and Europe/Paris, so the company's day cannot tie with the browser's by luck
    // whatever clock the runner keeps — nothing pins TZ for `ng test`.
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme', timezone: 'America/New_York' },
    }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<ExpensePage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  /** The expense form as the page holds it; a mat-select is set through its control. */
  const form = (): FormGroup =>
    (fixture.componentInstance as unknown as { form: () => FormGroup }).form();

  /** The payment form, which exists only once a recorded expense has been read. */
  const payment = (): FormGroup =>
    (
      fixture.componentInstance as unknown as { paymentFormGroup: () => FormGroup }
    ).paymentFormGroup();

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(testId: string, value: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  async function open(expenseId: string | undefined): Promise<void> {
    fixture = TestBed.createComponent(ExpensePage);
    if (expenseId !== undefined) {
      fixture.componentRef.setInput('expenseId', expenseId);
    }
    await settle();
  }

  beforeEach(() => {
    error.set(null);
    expense.set(null);
    attachments.set([]);
    facade.loadExpense.mockReset().mockResolvedValue(undefined);
    facade.createExpense.mockReset().mockResolvedValue({ ...draft, id: 'e9' });
    facade.reviseExpense.mockReset().mockResolvedValue(draft);
    facade.recordExpense.mockReset().mockResolvedValue({ ...draft, status: 'recorded' });
    facade.payExpense.mockReset().mockResolvedValue({ ...draft, status: 'paid' });
    facade.deleteExpense.mockReset().mockResolvedValue(true);
    facade.attach.mockReset().mockResolvedValue(true);
    facade.detach.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [ExpensePage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ExpensesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it("creates an expense filed under the vendor's usual category, then opens it by its identifier", async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadExpense).toHaveBeenCalledWith('c1', null);

    type('field-description', 'Gasoil');
    type('field-amountNet', '100,5');
    form().get('vendorId')!.setValue('v1');
    expect(form().get('categoryId')!.value).toBe('k2');
    q('expense-save')!.click();
    await settle();

    expect(facade.createExpense).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        description: 'Gasoil',
        amountNet: '100.5',
        vendorId: 'v1',
        categoryId: 'k2',
        taxComponentId: null,
      }),
    );
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/expenses', 'e9'], { replaceUrl: true }),
    );
  });

  it('keeps a category already chosen when the vendor changes, and sends no amount of the wrong shape', async () => {
    await open(undefined);
    form().get('categoryId')!.setValue('k1');
    form().get('vendorId')!.setValue('v1');
    expect(form().get('categoryId')!.value).toBe('k1');

    type('field-description', 'Gasoil');
    type('field-amountNet', '100.5555');
    q('expense-save')!.click();
    await settle();
    expect(facade.createExpense).not.toHaveBeenCalled();
  });

  it('saves what the form shows before recording a draft, and stops if that is refused', async () => {
    expense.set(draft);
    await open('e1');
    expect(q('expense-gross')?.textContent).toContain('TND');

    type('field-amountNet', '120');
    q('expense-record')!.click();
    await settle();
    expect(facade.reviseExpense).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ amountNet: '120' }),
    );
    expect(facade.recordExpense).toHaveBeenCalledWith('c1', 'e1');
    expect(facade.reviseExpense.mock.invocationCallOrder[0]).toBeLessThan(
      facade.recordExpense.mock.invocationCallOrder[0]!,
    );

    facade.recordExpense.mockClear();
    facade.reviseExpense.mockResolvedValue(null);
    q('expense-record')!.click();
    await settle();
    expect(facade.recordExpense).not.toHaveBeenCalled();
  });

  it('pays a recorded expense, which can no longer be edited or deleted', async () => {
    expense.set({ ...draft, status: 'recorded' });
    await open('e1');

    expect(q('expense-fixed')).not.toBeNull();
    expect(q('expense-save')).toBeNull();
    expect(q('expense-delete')).toBeNull();
    expect((q('field-description') as HTMLInputElement).disabled).toBe(true);

    q('expense-pay')!.click();
    await settle();
    expect(facade.payExpense).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ paymentMethod: 'transfer' }),
    );
  });

  it("proposes the company's day, not the browser's, for an expense's date and its payment", async () => {
    // 02:00 UTC is still 15 September in New York, while UTC and Europe/Paris have both turned to the 16th. The
    // API takes a day up to the company's today and answers 422 otherwise, so a browser-day default is refused
    // outright for anyone whose own day runs ahead — the defect docs/SPEC.md § 8 row 24 records.
    // Only `Date` is faked: a faked `setTimeout` never fires and `fixture.whenStable()` would hang on it.
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-09-16T02:00:00Z'));
    try {
      await open(undefined);
      expect(form().get('date')!.value).toBe('2026-09-15');

      expense.set({ ...draft, status: 'recorded' });
      await open('e1');
      expect(payment().get('paidOn')!.value).toBe('2026-09-15');
    } finally {
      vi.useRealTimers();
    }
  });

  it('deletes a draft only on the second click', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    expense.set(draft);
    await open('e1');

    q('expense-delete')!.click();
    await settle();
    expect(facade.deleteExpense).not.toHaveBeenCalled();
    q('expense-delete')!.click();
    await settle();
    expect(facade.deleteExpense).toHaveBeenCalledWith('c1', 'e1');
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/expenses'], { replaceUrl: true }),
    );
  });

  it('attaches a chosen file, links each receipt to its content, and removes one only from a draft', async () => {
    expense.set(draft);
    attachments.set([receipt]);
    await open('e1');

    expect(q('expense-attachment-open-recu.pdf')?.getAttribute('href')).toBe(
      '/api/companies/c1/expenses/e1/attachments/a1/content',
    );
    const file = new File(['%PDF-1.4'], 'facture.pdf', { type: 'application/pdf' });
    const input = q('expense-attach') as HTMLInputElement;
    Object.defineProperty(input, 'files', { value: { item: () => file, length: 1 } });
    input.dispatchEvent(new Event('change'));
    await settle();
    expect(facade.attach).toHaveBeenCalledWith('c1', 'e1', file);

    q('expense-detach-recu.pdf')!.click();
    await settle();
    expect(facade.detach).toHaveBeenCalledWith('c1', 'e1', 'a1');

    expense.set({ ...draft, status: 'recorded' });
    await settle();
    expect(q('expense-detach-recu.pdf')).toBeNull();
    expect(q('expense-attach')).not.toBeNull();
  });

  it('says why a change was refused', async () => {
    expense.set(draft);
    await open('e1');
    facade.reviseExpense.mockResolvedValue(null);
    error.set('not_draft');
    q('expense-save')!.click();
    await settle();
    expect(q('expense-saved')).toBeNull();
    expect(q('expense-error')?.textContent).toContain('brouillon');
  });

  it('shows a reader the expense and its receipts without a way to change them', async () => {
    auth.hasPermission.mockReturnValue(false);
    expense.set(draft);
    attachments.set([receipt]);
    await open('e1');

    expect(q('expense-read-only')).not.toBeNull();
    expect(q('expense-save')).toBeNull();
    expect(q('expense-attach')).toBeNull();
    expect(q('expense-detach-recu.pdf')).toBeNull();
    expect(q('expense-attachment-open-recu.pdf')).not.toBeNull();
  });
});

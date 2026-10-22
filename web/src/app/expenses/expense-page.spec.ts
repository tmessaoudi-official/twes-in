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
import type { BillGroup } from './expense-forms';
import { ExpensePage } from './expense-page';
import { ExpensesFacade } from './expenses-facade';
import { RecurringExpensesApi } from '../recurring-expenses/recurring-expenses-api';
import type {
  ExpenseAttachment,
  ExpenseOptions,
  ExpenseRow,
  ExpensesError,
  ExpenseVendorOption,
} from './expenses-types';
import type { PickAsked } from '../shared/form/pick-api';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, RecordedFeedback, successToasts } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { TextsApi } from '../shared/i18n/texts-api';
import { RecordDeletes } from '../shared/records/record-deletes';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      expenses: {
        saved: 'La dépense a été enregistrée.',
        withheld: 'Retenue à la source {{rate}} % : {{amount}} · versé {{paid}}',
        errors: { not_draft: 'Seule une dépense en brouillon peut être modifiée.' },
        bill: { worked_out: 'Calculé\u00a0: {{amount}}' },
      },
      import: {
        rejections: {
          value_required: 'Cette case doit être remplie.',
          invalid_amount:
            'Montant refusé\u00a0: un nombre au-dessus de 0, {{decimals}} décimales au plus.',
          one_vat_rate:
            'Une ligne porte un seul taux de TVA\u00a0: pour deux taux, faites deux lignes.',
          printed_tax_off: 'Ce montant s’écarte de plus de {{tolerance}} de ce que donne le taux.',
          paid_before_expense: 'Date de règlement refusée : le jour de la dépense au plus tôt.',
          invalid_withholding_rate:
            'Retenue refusée : un pourcentage de 0 à 100, trois décimales au plus.',
        },
      },
    });
  }
}

/** What the picker answers: the page holds no book of suppliers, so a vendor exists here only once it is picked. */
const vendors: ExpenseVendorOption[] = [
  {
    id: 'v1',
    number: 'FRN-1',
    name: 'Sotumag',
    paymentTermsDays: 30,
    defaultExpenseCategoryId: 'k2',
  },
];
const options: ExpenseOptions = {
  currency: 'TND',
  currencyScale: 3,
  categories: [
    { id: 'k1', name: 'Véhicules', parentId: null },
    { id: 'k2', name: 'Carburant', parentId: 'k1' },
  ],
  taxes: [{ id: 't1', code: 'TVA19', name: 'TVA', rate: '19.000', entersVatBase: false }],
  charges: [],
  paymentMethods: ['transfer', 'cash'],
  withholdingOperationCodes: [],
  withholdings: [
    { code: 'RS1', name: 'Retenue à la source', rate: '1.000', threshold: '1000.000' },
  ],
};
const tunisian: ExpenseOptions = {
  ...options,
  withholdingOperationCodes: [{ code: 'RS7_000006', label: 'Honoraires exonérés' }],
};
const draft: ExpenseRow = {
  id: 'e1',
  version: 1,
  status: 'draft',
  date: '2026-09-10',
  reference: null,
  description: 'Gasoil',
  vendorId: 'v1',
  vendorName: 'Sotumag',
  payee: null,
  categoryId: 'k2',
  categoryName: 'Carburant',
  lines: [{ amountNet: '100.000', taxComponentIds: ['t1'], taxCodes: ['TVA19'] }],
  documentTaxComponentIds: [],
  printedTaxes: [],
  taxes: [
    {
      code: 'TVA19',
      kind: 'percentage_line',
      rate: '19.000',
      base: '100.000',
      workedOut: '19.000',
      amount: '19.000',
    },
  ],
  amountNet: '100.000',
  taxAmount: '19.000',
  fixedAmount: '0.000',
  amountGross: '119.000',
  currency: 'TND',
  dueDate: '2026-10-10',
  paymentMethod: null,
  paidOn: null,
  notes: null,
  withholdingRate: null,
  withholdingAmount: null,
  withholdingOperationCode: null,
  amountPaid: '0.000',
  suggestedWithholdingRate: null,
  attachmentCount: 1,
  amountToCheck: false,
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
  const refusals = signal<readonly FieldRefusal[]>([]);
  const expense = signal<ExpenseRow | null>(null);
  const attachments = signal<readonly ExpenseAttachment[]>([]);
  const optionsState = signal<ExpenseOptions | null>(options);
  const facade = {
    options: optionsState.asReadonly(),
    expense: expense.asReadonly(),
    attachments: attachments.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    refusals: refusals.asReadonly(),
    pickVendors: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? vendors.filter((each) => asked.ids.includes(each.id)) : vendors,
    ),
    loadExpense: vi.fn(),
    createExpense: vi.fn(),
    reviseExpense: vi.fn(),
    recordExpense: vi.fn(),
    clearError: vi.fn(),
    payExpense: vi.fn(),
    classifyWithholding: vi.fn(),
    attach: vi.fn(),
    detach: vi.fn(),
    restoreAttachment: vi.fn(),
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
    hasModule: vi.fn(),
  };
  const recurring = { create: vi.fn() };
  const deletes = { remove: vi.fn() };
  let fixture: ComponentFixture<ExpensePage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  /** An entry of the bar's « ⋮ », where a destructive action always sits; null when the page offers none. */
  async function menuEntry(id: string): Promise<HTMLElement | null> {
    q('record-more')?.click();
    await fixture.whenStable();
    fixture.detectChanges();
    const entry = document.body.querySelector<HTMLElement>(`[data-testid="record-menu-${id}"]`);
    return entry;
  }

  /** The expense form as the page holds it; a mat-select is set through its control. */
  const form = (): FormGroup =>
    (fixture.componentInstance as unknown as { form: () => FormGroup }).form();

  /** The TEJ operation form of a paid expense, where the company declares to TEJ. */
  const classification = (): FormGroup | null =>
    (
      fixture.componentInstance as unknown as { classifyFormGroup: () => FormGroup | null }
    ).classifyFormGroup();

  /** The bill beside the form: its lines, its fixed charges and the amounts it prints. */
  const bill = (): BillGroup =>
    (fixture.componentInstance as unknown as { bill: () => BillGroup }).bill();

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

  async function pick(testId: string, label: string): Promise<void> {
    (q(testId) as HTMLInputElement).dispatchEvent(new Event('focusin'));
    await settle();
    const option = Array.from(document.body.querySelectorAll<HTMLElement>('mat-option')).find(
      (each) => each.textContent?.trim() === label,
    );
    expect(option, label).toBeDefined();
    option!.click();
    await settle();
  }

  function type(testId: string, value: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  /** Switches the bill between amounts before tax and amounts taxes included, as a click on the radio does. */
  async function chooseMode(mode: 'gross' | 'net'): Promise<void> {
    (q('bill-mode-' + mode)?.querySelector('input') as HTMLInputElement).click();
    await settle();
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
    refusals.set([]);
    expense.set(null);
    attachments.set([]);
    optionsState.set(options);
    facade.loadExpense.mockReset().mockResolvedValue(undefined);
    facade.createExpense.mockReset().mockResolvedValue({ ...draft, id: 'e9' });
    facade.reviseExpense.mockReset().mockResolvedValue(draft);
    facade.recordExpense.mockReset().mockResolvedValue({ ...draft, status: 'recorded' });
    facade.payExpense.mockReset().mockResolvedValue({ ...draft, status: 'paid' });
    facade.classifyWithholding.mockReset().mockResolvedValue({ ...draft, status: 'paid' });
    deletes.remove.mockReset().mockResolvedValue(true);
    facade.attach.mockReset().mockResolvedValue(true);
    facade.detach.mockReset().mockResolvedValue(true);
    facade.restoreAttachment.mockReset().mockResolvedValue(null);
    auth.hasPermission.mockReset().mockReturnValue(true);
    auth.hasModule.mockReset().mockReturnValue(true);
    recurring.create.mockReset().mockResolvedValue({ id: 'r1' });
    TestBed.configureTestingModule({
      imports: [ExpensePage],
      providers: [
        ...provideQuietFeedback(),
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
        { provide: RecurringExpensesApi, useValue: recurring },
        { provide: RecordDeletes, useValue: deletes },
        { provide: TextsApi, useValue: { part: () => Promise.resolve({}) } },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: a page names nothing it has not loaded.
  it('puts the person on the first field in error when the bar saves a form that cannot be sent', async () => {
    await open(undefined);
    type('field-reference', 'FAC-77');
    await settle();
    q('record-save')!.click();
    await settle();

    const focused = document.activeElement as HTMLElement;
    expect(focused.getAttribute('data-testid')).toMatch(/^field-/);
    fixture.detectChanges();
    // Its error is shown and is what is said with it.
    expect(focused.classList).toContain('ng-invalid');
    expect(focused.getAttribute('aria-describedby')).toMatch(/mat-mdc-error/);
  });

  it('titles an expense still loading as nothing, never as a new one', async () => {
    await open('x1');

    const title = q('expense-title');
    expect(title?.textContent?.trim()).toBe('');
    expect(title?.getAttribute('aria-hidden')).toBe('true');
  });

  it('titles the page for a new expense as new', async () => {
    await open(undefined);

    expect(q('expense-title')?.textContent).toContain('expenses.new_title');
    expect(q('expense-title')?.getAttribute('aria-hidden')).toBeNull();
  });

  it("creates an expense filed under the vendor's usual category, then opens it by its identifier", async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadExpense).toHaveBeenCalledWith('c1', null);

    type('field-description', 'Gasoil');
    type('bill-line-0-gross', '100,5');
    await pick('field-vendorId', 'FRN-1 · Sotumag');
    expect(form().get('categoryId')!.value).toBe('k2');
    q('record-save')!.click();
    await settle();

    expect(facade.createExpense).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        description: 'Gasoil',
        lines: [{ amountNet: '100.500', taxComponentIds: [] }],
        vendorId: 'v1',
        categoryId: 'k2',
      }),
    );
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/expenses', 'e9'], { replaceUrl: true }),
    );
    expect(successToasts()).toContain('expenses.saved');
  });

  // docs/SPEC.md § 7, 2026-09-27 18:34: expenses stand without the Vendors module.
  it('asks who was paid in words while Vendors is off, and keeps the vendor a draft already names', async () => {
    auth.hasModule.mockImplementation((module: string) => module !== 'vendors');
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(q('field-vendorId')).toBeNull();
    type('field-description', 'Stationnement');
    type('bill-line-0-gross', '4');
    type('field-payee', 'Parking Lafayette');
    await settle();
    q('record-save')!.click();
    await settle();
    expect(facade.createExpense).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ vendorId: null, payee: 'Parking Lafayette' }),
    );

    expense.set(draft);
    await open('e1');
    expect(q('expense-status')?.textContent).toContain('Sotumag');
    type('bill-line-0-net', '120');
    await settle();
    q('record-save')!.click();
    await settle();
    expect(facade.reviseExpense).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({
        lines: [{ amountNet: '120', taxComponentIds: ['t1'] }],
        vendorId: 'v1',
        payee: null,
      }),
      1,
    );
  });

  it('keeps a category already chosen when the vendor changes, and sends no amount of the wrong shape', async () => {
    await open(undefined);
    form().get('categoryId')!.setValue('k1');
    form().get('vendorId')!.setValue('v1');
    expect(form().get('categoryId')!.value).toBe('k1');

    type('field-description', 'Gasoil');
    type('bill-line-0-gross', '100.5555');
    await settle();
    q('record-save')!.click();
    await settle();
    expect(facade.createExpense).not.toHaveBeenCalled();
  });

  it('saves what the form shows before recording a draft, and stops if that is refused', async () => {
    expense.set(draft);
    await open('e1');
    expect(q('expense-gross')?.textContent).toContain('TND');

    type('bill-line-0-net', '120');
    q('expense-record')!.click();
    await settle();
    expect(facade.reviseExpense).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ lines: [{ amountNet: '120', taxComponentIds: ['t1'] }] }),
      1,
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

  it('after a save refused as made over a version saved since, merges what was saved, says nothing was, and saves on it next', async () => {
    expense.set(draft);
    await open('e1');
    facade.reviseExpense.mockImplementationOnce(async () => {
      error.set('record_changed');
      return null;
    });
    facade.clearError.mockImplementationOnce(() => error.set(null));
    facade.loadExpense.mockImplementationOnce(async () =>
      expense.set({ ...draft, version: 2, reference: 'FA-77' }),
    );

    type('field-description', 'Gasoil octobre');
    await settle();
    q('record-save')!.click();
    await settle();
    await vi.waitFor(() => expect((q('field-reference') as HTMLInputElement).value).toBe('FA-77'));

    expect((q('field-description') as HTMLInputElement).value).toBe('Gasoil octobre');
    expect(error()).toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });

    q('record-save')!.click();
    await settle();
    expect(facade.reviseExpense).toHaveBeenLastCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ description: 'Gasoil octobre', reference: 'FA-77' }),
      2,
    );
  });

  it('records nothing over a version saved since: it merges it first', async () => {
    expense.set(draft);
    await open('e1');
    facade.reviseExpense.mockImplementationOnce(async () => {
      error.set('record_changed');
      return null;
    });
    facade.clearError.mockImplementationOnce(() => error.set(null));
    facade.loadExpense.mockImplementationOnce(async () =>
      expense.set({ ...draft, version: 2, reference: 'FA-77' }),
    );

    q('expense-record')!.click();
    await settle();
    await vi.waitFor(() => expect((q('field-reference') as HTMLInputElement).value).toBe('FA-77'));
    expect(facade.recordExpense).not.toHaveBeenCalled();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });
  });

  it('pays a recorded expense, which can no longer be edited or deleted', async () => {
    expense.set({ ...draft, status: 'recorded' });
    await open('e1');

    expect(q('expense-fixed')).not.toBeNull();
    expect(q('record-save')).toBeNull();
    expect(await menuEntry('delete')).toBeNull();
    expect((q('field-description') as HTMLInputElement).disabled).toBe(true);

    q('expense-pay')!.click();
    await settle();
    expect(facade.payExpense).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ paymentMethod: 'transfer' }),
    );
  });

  it('keeps what is typed into the payment when the expense is read again unchanged', async () => {
    // Recording an expense reads it back once more: that answer must not rebuild the payment over what was typed.
    expense.set({ ...draft, status: 'recorded' });
    await open('e1');
    type('field-withholdingRate', '150');
    await settle();

    expense.set({ ...draft, status: 'recorded' });
    await settle();

    expect((q('field-withholdingRate') as HTMLInputElement).value).toBe('150');
    q('expense-pay')!.click();
    await settle();
    expect(facade.payExpense).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ withholdingRate: '150' }),
    );
  });

  it('keeps what is typed into the payment when the options are read again unchanged', async () => {
    // Recording an expense reads the options again too: equal options are no reason to rebuild the payment.
    expense.set({ ...draft, status: 'recorded' });
    await open('e1');
    type('field-withholdingRate', '150');
    await settle();

    optionsState.set({ ...options });
    await settle();

    expect((q('field-withholdingRate') as HTMLInputElement).value).toBe('150');
  });

  it('says a refused payment under the field it names, and puts the person on it', async () => {
    expense.set({ ...draft, status: 'recorded' });
    await open('e1');
    facade.payExpense.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([
        { field: 'paidOn', reason: 'paid_before_expense', params: {} },
        { field: 'withholdingRate', reason: 'invalid_withholding_rate', params: {} },
      ]);
      return null;
    });

    q('expense-pay')!.click();
    await settle();

    expect(q('field-error-paidOn')?.textContent?.trim()).toBe(
      'Date de règlement refusée : le jour de la dépense au plus tôt.',
    );
    expect(q('field-error-withholdingRate')?.textContent?.trim()).toBe(
      'Retenue refusée : un pourcentage de 0 à 100, trois décimales au plus.',
    );
    expect(document.activeElement).toBe(q('field-paidOn'));
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

  // docs/SPEC.md § 7, 2026-09-24 11:40 (RPT-09).
  it('proposes the withholding the API suggests, sends it, and shows what a paid expense withheld', async () => {
    expense.set({ ...draft, status: 'recorded', suggestedWithholdingRate: '1.000' });
    await open('e1');
    expect(payment().get('withholdingRate')!.value).toBe('1');

    q('expense-pay')!.click();
    await settle();
    expect(facade.payExpense).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ withholdingRate: '1' }),
    );

    expense.set({
      ...draft,
      status: 'paid',
      paymentMethod: 'transfer',
      paidOn: '2026-09-12',
      withholdingRate: '1.000',
      withholdingAmount: '11.900',
      amountPaid: '1178.100',
    });
    await settle();
    const withheld = q('expense-withheld')?.textContent?.replace(/\s/g, '');
    expect(withheld).toContain('1%:11,900');
    expect(withheld).toContain('versé1178,100');
  });

  // docs/SPEC.md § 7, 2026-09-26 00:22: the TEJ operation, given at payment or afterwards, never worked out here.
  it('asks a Tunisian company for the TEJ operation when paying, and sends the one chosen', async () => {
    optionsState.set(tunisian);
    expense.set({ ...draft, status: 'recorded' });
    await open('e1');
    payment().get('withholdingOperationCode')!.setValue('RS7_000006');
    q('expense-pay')!.click();
    await settle();
    expect(facade.payExpense).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ withholdingOperationCode: 'RS7_000006' }),
    );
  });

  it('shows the TEJ operation of a paid expense, and gives one where none was said', async () => {
    optionsState.set(tunisian);
    const paid: ExpenseRow = { ...draft, status: 'paid', paidOn: '2026-09-12' };
    expense.set(paid);
    await open('e1');
    expect(q('expense-tej')?.getAttribute('data-code')).toBe('');

    classification()!.get('withholdingOperationCode')!.setValue('RS7_000006');
    q('expense-classify')!.click();
    await settle();
    expect(facade.classifyWithholding).toHaveBeenCalledWith('c1', 'e1', 'RS7_000006');
    expect(successToasts()).toContain('expenses.tej.classified');

    expense.set({ ...paid, withholdingOperationCode: 'RS7_000006' });
    await settle();
    expect(q('expense-tej')?.getAttribute('data-code')).toBe('RS7_000006');
    expect(q('expense-tej')?.textContent).toContain('RS7_000006 — Honoraires exonérés');
  });

  it('keeps the TEJ operation being chosen when the options are read again unchanged', async () => {
    optionsState.set(tunisian);
    expense.set({ ...draft, status: 'paid', paidOn: '2026-09-12' });
    await open('e1');
    classification()!.get('withholdingOperationCode')!.setValue('RS7_000006');

    optionsState.set({ ...tunisian });
    await settle();

    expect(classification()!.get('withholdingOperationCode')!.value).toBe('RS7_000006');
  });

  it('says nothing of TEJ outside Tunisia, nor offers a reader to change it', async () => {
    expense.set({ ...draft, status: 'paid', paidOn: '2026-09-12' });
    await open('e1');
    expect(q('expense-tej')).toBeNull();
    expect(q('expense-classify')).toBeNull();

    optionsState.set(tunisian);
    auth.hasPermission.mockImplementation((permission: string) => permission !== 'expense.write');
    await open('e1');
    expect(q('expense-tej')).not.toBeNull();
    expect(q('expense-classify')).toBeNull();
  });

  it('deletes a draft without asking, said annulable, and goes back to the list once it is gone', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    expense.set(draft);
    await open('e1');

    const entry = await menuEntry('delete');
    expect(entry?.getAttribute('data-kind')).toBe('annulable');
    entry!.click();
    await settle();

    expect(deletes.remove).toHaveBeenCalledWith('c1', 'expense', [
      { id: 'e1', label: draft.description },
    ]);
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/expenses'], { replaceUrl: true }),
    );
  });

  it('stays on the draft when the delete is refused, and offers none to whoever does not write expenses', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    deletes.remove.mockResolvedValue(false);
    expense.set(draft);
    await open('e1');
    (await menuEntry('delete'))!.click();
    await settle();
    expect(deletes.remove).toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();

    auth.hasPermission.mockImplementation((permission: string) => permission !== 'expense.write');
    await open('e1');
    expect(await menuEntry('delete')).toBeNull();
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

  it('says a file is taken off and puts it back where it was on « Annuler »', async () => {
    expense.set(draft);
    attachments.set([receipt]);
    await open('e1');
    const feedback = TestBed.inject(Feedback) as RecordedFeedback;

    q('expense-detach-recu.pdf')!.click();
    await settle();
    const removed = feedback.said.at(-1);
    expect([removed?.key, removed?.params, removed?.action?.key]).toEqual([
      'expenses.attachments.removed',
      { name: 'recu.pdf' },
      'expenses.attachments.undo',
    ]);
    expect(facade.restoreAttachment).not.toHaveBeenCalled();

    removed?.action?.run();
    await settle();
    expect(facade.restoreAttachment).toHaveBeenCalledWith('c1', 'e1', 'a1');
    expect(feedback.said.at(-1)).toEqual({
      kind: 'success',
      key: 'expenses.attachments.restored',
      params: { name: 'recu.pdf' },
    });

    // Refused, it is said as a toast too, since the page it came from may be gone.
    facade.restoreAttachment.mockResolvedValue('files_full');
    removed?.action?.run();
    await settle();
    expect(feedback.said.at(-1)).toEqual({ kind: 'failure', key: 'expenses.errors.files_full' });
  });

  it('takes what another person saved into the open draft', async () => {
    expense.set(draft);
    await open('e1');
    facade.loadExpense.mockImplementation(async () => {
      expense.set({ ...draft, description: 'Gasoil et péage' });
    });

    await announceSaved('expense', 'e1');
    await settle();

    expect(facade.loadExpense).toHaveBeenLastCalledWith('c1', 'e1');
    expect((q('field-description') as HTMLInputElement).value).toBe('Gasoil et péage');
  });

  it('says on its line what the bill was refused for, puts the person on it, and lets a new value be sent', async () => {
    expense.set(draft);
    await open('e1');
    facade.reviseExpense.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([
        { field: 'lines[0].amountNet', reason: 'invalid_amount', params: { decimals: 3 } },
      ]);
      return null;
    });
    type('bill-line-0-net', '120.5');
    await settle();
    q('record-save')!.click();
    await settle();

    expect(q('bill-line-0-net-error')?.textContent).toContain(
      'Montant refusé\u00a0: un nombre au-dessus de 0, 3 décimales au plus.',
    );
    expect(document.activeElement).toBe(q('bill-line-0-net'));

    type('bill-line-0-net', '121');
    await settle();
    expect(q('bill-line-0-net-error')).toBeNull();
    q('record-save')!.click();
    await settle();
    expect(facade.reviseExpense).toHaveBeenCalledTimes(2);
  });

  it('lets a bill refused on one field be sent again once anything on it changes', async () => {
    expense.set(draft);
    await open('e1');
    facade.reviseExpense.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([{ field: 'lines[0].taxComponentIds', reason: 'one_vat_rate', params: {} }]);
      return null;
    });
    type('bill-line-0-net', '120');
    await settle();
    q('record-save')!.click();
    await settle();
    expect(q('bill-line-0-taxes-error')?.textContent).toContain('un seul taux de TVA');

    // The rate stays as it was; the net beside it changes, and that is enough to ask the API again.
    type('bill-line-0-net', '121');
    await settle();
    expect(q('bill-line-0-taxes-error')).toBeNull();
    q('record-save')!.click();
    await settle();
    expect(facade.reviseExpense).toHaveBeenCalledTimes(2);
  });

  it('says on its field that recording needs a category', async () => {
    expense.set(draft);
    await open('e1');
    facade.recordExpense.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([{ field: 'categoryId', reason: 'value_required', params: {} }]);
      return null;
    });
    q('expense-record')!.click();
    // Saved first, then recorded: two answers come back before the refusal is said.
    await settle();
    await settle();

    expect(facade.recordExpense).toHaveBeenCalled();
    expect(q('field-error-categoryId')?.textContent).toContain('Cette case doit être remplie.');
  });

  it('says why a change was refused', async () => {
    expense.set(draft);
    await open('e1');
    facade.reviseExpense.mockResolvedValue(null);
    error.set('not_draft');
    q('record-save')!.click();
    await settle();
    expect(successToasts()).toEqual([]);
    expect(q('expense-error')?.textContent).toContain('brouillon');
  });

  it('shows a reader the expense and its receipts without a way to change them', async () => {
    auth.hasPermission.mockReturnValue(false);
    expense.set(draft);
    attachments.set([receipt]);
    await open('e1');

    expect(q('expense-read-only')).not.toBeNull();
    expect(q('record-save')).toBeNull();
    expect(q('expense-attach')).toBeNull();
    expect(q('expense-detach-recu.pdf')).toBeNull();
    expect(q('expense-attachment-open-recu.pdf')).not.toBeNull();
  });

  describe('the bill', () => {
    const offered: ExpenseOptions = {
      ...options,
      taxes: [
        { id: 't1', code: 'TVA19', name: 'TVA', rate: '19.000', entersVatBase: false },
        { id: 't7', code: 'TVA7', name: 'TVA', rate: '7.000', entersVatBase: false },
        { id: 'tf', code: 'FODEC', name: 'FODEC', rate: '1.000', entersVatBase: true },
      ],
      charges: [{ id: 's1', code: 'TIMBRE', name: 'Timbre fiscal', amount: '1.000' }],
    };

    it('says a printed amount refused under it, its tolerance as the screen writes amounts', async () => {
      optionsState.set(offered);
      expense.set(draft);
      await open('e1');
      facade.reviseExpense.mockImplementationOnce(async () => {
        error.set('invalid');
        refusals.set([
          {
            field: 'printedTaxes',
            reason: 'printed_tax_off',
            params: { code: 'TVA19', tolerance: '0.002' },
          },
        ]);
        return null;
      });
      type('bill-printed-TVA19', '29');
      await settle();
      q('record-save')!.click();
      await settle();

      expect(q('bill-printed-TVA19-error')?.textContent).toContain(
        'Ce montant s’écarte de plus de 0,002 de ce que donne le taux.',
      );
      expect(document.activeElement).toBe(q('bill-printed-TVA19'));
    });

    it('enters a bill at two rates with FODEC and the stamp, and saves the amount it prints', async () => {
      optionsState.set(offered);
      expense.set(draft);
      await open('e1');

      type('bill-line-0-net', '1000');
      bill().controls.lines.at(0).controls.taxComponentIds.setValue(['t1', 'tf']);
      q('bill-add-line')!.click();
      await settle();
      type('bill-line-1-net', '200');
      bill().controls.lines.at(1).controls.taxComponentIds.setValue(['t7']);
      (q('bill-charge-s1')?.querySelector('input') as HTMLInputElement).click();
      type('bill-printed-TVA19', '191,9');
      await settle();
      // Each net, each line's rates, the line added, the stamp and the printed amount.
      expect(q('record-changes')?.getAttribute('data-count')).toBe('6');

      q('record-save')!.click();
      await settle();
      expect(facade.reviseExpense).toHaveBeenCalledWith(
        'c1',
        'e1',
        expect.objectContaining({
          lines: [
            { amountNet: '1000', taxComponentIds: ['t1', 'tf'] },
            { amountNet: '200', taxComponentIds: ['t7'] },
          ],
          documentTaxComponentIds: ['s1'],
          printedTaxes: [{ code: 'TVA19', amount: '191.9' }],
        }),
        1,
      );
    });

    it('starts a new expense with the stamp unticked, and creates it with its lines', async () => {
      optionsState.set(offered);
      vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      await open(undefined);
      expect(bill().controls.charges.value).toEqual([]);
      expect(q('bill-printed-TVA19')).toBeNull();

      type('field-description', 'Fournitures');
      type('bill-line-0-gross', '50');
      await settle();
      q('record-save')!.click();
      await settle();
      expect(facade.createExpense).toHaveBeenCalledWith(
        'c1',
        expect.objectContaining({
          lines: [{ amountNet: '50.000', taxComponentIds: [] }],
          documentTaxComponentIds: [],
          printedTaxes: [],
        }),
      );
    });

    // docs/SPEC.md § 7: a new expense asks its bill's amounts taxes included, a saved draft opens on its nets.
    it("works a new expense's net out of the receipt's total, the stamp included, whatever is typed first", async () => {
      optionsState.set(offered);
      vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      await open(undefined);
      expect(q('bill-mode')?.getAttribute('data-mode')).toBe('gross');
      expect(q('bill-hint')?.textContent?.trim()).toBe('expenses.bill.hint_gross');
      expect(q('bill-line-0-net')).toBeNull();

      type('field-description', 'Café');
      type('bill-line-0-gross', '12,9');
      bill().controls.lines.at(0).controls.taxComponentIds.setValue(['t1']);
      (q('bill-charge-s1')?.querySelector('input') as HTMLInputElement).click();
      await settle();
      expect(bill().controls.lines.at(0).controls.amountNet.value).toBe('10.000');
      expect(q('bill-line-0-gross-hint')?.textContent?.trim()).toBe('expenses.bill.gross_hint');

      // A second line takes the stamp out of the first one's total; its net stays, and comes back with it.
      q('bill-add-line')!.click();
      await settle();
      expect((q('bill-line-0-gross') as HTMLInputElement).value).toBe('11,900');
      q('bill-line-1-remove')!.click();
      await settle();
      expect((q('bill-line-0-gross') as HTMLInputElement).value).toBe('12,900');
      expect(bill().controls.lines.at(0).controls.amountNet.value).toBe('10.000');

      q('record-save')!.click();
      await settle();
      expect(facade.createExpense).toHaveBeenCalledWith(
        'c1',
        expect.objectContaining({
          lines: [{ amountNet: '10.000', taxComponentIds: ['t1'] }],
          documentTaxComponentIds: ['s1'],
        }),
      );
    });

    it('takes each rate of a bill at several rates taxes included, the stamp left out', async () => {
      optionsState.set(offered);
      vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      await open(undefined);
      (q('bill-charge-s1')?.querySelector('input') as HTMLInputElement).click();
      q('bill-add-line')!.click();
      await settle();
      type('bill-line-0-gross', '1201,9');
      bill().controls.lines.at(0).controls.taxComponentIds.setValue(['t1', 'tf']);
      type('bill-line-1-gross', '214');
      bill().controls.lines.at(1).controls.taxComponentIds.setValue(['t7']);
      await settle();
      expect(bill().controls.lines.at(0).controls.amountNet.value).toBe('1000.000');
      expect(bill().controls.lines.at(1).controls.amountNet.value).toBe('200.000');
    });

    it('opens a saved draft on its nets, and switching to taxes included and back changes nothing', async () => {
      optionsState.set(offered);
      expense.set({ ...draft, documentTaxComponentIds: ['s1'] });
      await open('e1');
      expect(q('bill-mode')?.getAttribute('data-mode')).toBe('net');
      expect(q('bill-hint')?.textContent?.trim()).toBe('expenses.bill.hint');
      expect((q('bill-line-0-net') as HTMLInputElement).value).toBe('100,000');

      await chooseMode('gross');
      // One line: the receipt's total, the stamp in it.
      expect((q('bill-line-0-gross') as HTMLInputElement).value).toBe('120,000');
      await chooseMode('net');
      expect(bill().controls.lines.at(0).controls.amountNet.value).toBe('100.000');
      expect(q('record-changes')).toBeNull();

      // A net changed before tax is what the total says once switched back.
      type('bill-line-0-net', '200');
      await chooseMode('gross');
      expect((q('bill-line-0-gross') as HTMLInputElement).value).toBe('239,000');

      // Another person's save rebuilds the bill: the amounts stay typed taxes included.
      await chooseMode('net');
      q('record-revert')!.click();
      await chooseMode('gross');
      facade.loadExpense.mockImplementation(async () => {
        expense.set({
          ...draft,
          documentTaxComponentIds: ['s1'],
          lines: [{ ...draft.lines[0]!, amountNet: '150.000' }],
        });
      });
      await announceSaved('expense', 'e1');
      await settle();
      expect(q('bill-mode')?.getAttribute('data-mode')).toBe('gross');
      expect((q('bill-line-0-gross') as HTMLInputElement).value).toBe('179,500');
    });

    it('refuses a total the stamp alone reaches, and one that is not an amount, before asking the API', async () => {
      optionsState.set(offered);
      await open(undefined);
      (q('bill-charge-s1')?.querySelector('input') as HTMLInputElement).click();
      type('field-description', 'Café');
      type('bill-line-0-gross', '12,9');
      type('bill-line-0-gross', '1');
      await settle();
      q('record-save')!.click();
      await settle();
      expect(facade.createExpense).not.toHaveBeenCalled();
      expect(q('bill-line-0-gross-error')?.textContent).toContain('expenses.bill.gross_charges');

      type('bill-line-0-gross', '');
      await settle();
      expect(q('bill-line-0-gross-error')?.textContent).toContain('expenses.bill.gross_required');
    });

    it('refuses two VAT rates on one line, and a line removed, before asking the API', async () => {
      optionsState.set(offered);
      expense.set(draft);
      await open('e1');
      bill().controls.lines.at(0).controls.taxComponentIds.setValue(['t1', 't7']);
      bill().controls.lines.at(0).controls.taxComponentIds.markAsTouched();
      await settle();
      expect(q('bill-line-0-taxes-error')?.textContent).toContain('expenses.bill.one_vat');

      q('record-save')!.click();
      await settle();
      expect(facade.reviseExpense).not.toHaveBeenCalled();

      // The last line stays: a bill is never left with none.
      expect(q('bill-line-0-remove')).toBeNull();
      q('bill-add-line')!.click();
      await settle();
      q('bill-line-1-remove')!.click();
      await settle();
      expect(bill().controls.lines.length).toBe(1);
    });

    it('puts the saved bill back on « Rétablir »', async () => {
      expense.set(draft);
      await open('e1');
      type('bill-line-0-net', '250');
      await settle();
      expect(q('record-changes')?.getAttribute('data-count')).toBe('1');

      q('record-revert')!.click();
      await settle();
      expect((q('bill-line-0-net') as HTMLInputElement).value).toBe('100,000');
      expect(q('record-changes')).toBeNull();
    });

    it('shows each tax the bill charges, and the amount worked out where the bill prints another', async () => {
      optionsState.set(offered);
      expense.set({
        ...draft,
        printedTaxes: [{ code: 'TVA19', amount: '19.001' }],
        documentTaxComponentIds: ['s1'],
        taxes: [
          { ...draft.taxes[0]!, amount: '19.001' },
          {
            code: 'TIMBRE',
            kind: 'fixed_document',
            rate: null,
            base: null,
            workedOut: '1.000',
            amount: '1.000',
          },
        ],
      });
      await open('e1');
      const vat = q('expense-tax-TVA19')?.textContent?.replace(/\s/g, '');
      expect(vat).toContain('19,001');
      expect(vat).toContain('19,000');
      expect(q('expense-tax-TIMBRE')?.textContent).toContain('Timbre fiscal');
    });

    it('takes the saved bill another person changed while nothing is typed here, and asks when both changed it', async () => {
      expense.set(draft);
      await open('e1');
      facade.loadExpense.mockImplementation(async () => {
        expense.set({ ...draft, lines: [{ ...draft.lines[0]!, amountNet: '150.000' }] });
      });
      await announceSaved('expense', 'e1');
      await settle();
      expect((q('bill-line-0-net') as HTMLInputElement).value).toBe('150,000');

      type('bill-line-0-net', '175');
      await settle();
      facade.loadExpense.mockImplementation(async () => {
        expense.set({ ...draft, lines: [{ ...draft.lines[0]!, amountNet: '160.000' }] });
      });
      await announceSaved('expense', 'e1');
      await settle();
      expect(q('field-conflict-bill')).not.toBeNull();
      expect((q('bill-line-0-net') as HTMLInputElement).value).toBe('175');
    });

    it('shows a recorded bill as it reads, with nothing to change', async () => {
      expense.set({ ...draft, status: 'recorded' });
      await open('e1');
      expect(q('bill-line-0-net')).toBeNull();
      expect(q('bill-add-line')).toBeNull();
      expect(q('bill-line-0')?.textContent).toContain('100,000');
      expect(q('bill-line-0')?.textContent).toContain('TVA');
    });
  });

  // docs/SPEC.md § 7, 2026-10-15 17:46: a variable one drafts flagged « montant à vérifier » until someone records it.
  it('flags a draft whose amount is to check against the bill, and says what to do with it', async () => {
    expense.set({ ...draft, amountToCheck: true });
    await open('e1');

    expect(q('expense-amount-to-check')?.textContent).toContain('expenses.amount_to_check');
    expect(q('expense-amount-to-check-hint')?.textContent).toContain(
      'expenses.amount_to_check_hint',
    );

    expense.set({ ...draft, amountToCheck: false });
    await settle();
    expect(q('expense-amount-to-check')).toBeNull();
  });

  it('makes an expense recurring from its bar, each month on its first day’s date unless told otherwise', async () => {
    expense.set({ ...draft, status: 'recorded' });
    await open('e1');

    // A button beside the bar on a wide screen; the bar folds it into its « ⋮ » on a phone.
    q('record-make-recurring')!.click();
    // The schedule's words are opened before the dialog: it waits for them.
    const confirm = await vi.waitFor(() => {
      const button = document.body.querySelector<HTMLElement>(
        '[data-testid="recurring-expense-dialog-confirm"]',
      );
      expect(button).not.toBeNull();
      return button!;
    });
    confirm.click();
    await settle();

    await vi.waitFor(() => expect(recurring.create).toHaveBeenCalled());
    const [companyId, made] = recurring.create.mock.calls[0] as [string, Record<string, unknown>];
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York' }).format(
      new Date(),
    );
    expect(companyId).toBe('c1');
    expect(made).toEqual({
      modelExpenseId: 'e1',
      schedule: {
        unit: 'month',
        every: 1,
        anchor: 'date',
        monthDay: Number(today.slice(8, 10)),
        ordinal: null,
        weekday: null,
      },
      startsOn: today,
      endsOn: null,
      variableAmount: false,
    });
    await vi.waitFor(() => expect(successToasts()).toContain('expenses.recurring.made'));
  });

  it('offers no « Rendre récurrente » while the company has no recurring expenses, nor to a reader', async () => {
    auth.hasModule.mockImplementation((module: string) => module !== 'recurring_expenses');
    expense.set(draft);
    await open('e1');

    expect(q('record-make-recurring')).toBeNull();

    auth.hasModule.mockReturnValue(true);
    auth.hasPermission.mockReturnValue(false);
    fixture.destroy();
    await open('e1');
    expect(q('record-make-recurring')).toBeNull();
  });
});

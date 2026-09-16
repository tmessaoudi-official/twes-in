// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { todayIn } from '../shared/i18n/format';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { InvoicePage } from './invoice-page';
import { InvoicesFacade } from './invoices-facade';
import type { InvoiceOptions, InvoiceRow, InvoicesError } from './invoices-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      invoices: {
        errors: { invalid: 'Refusé.' },
        statuses: { draft: 'Brouillon', issued: 'Émise', overdue: 'En retard', paid: 'Payée' },
        types: { credit_note: 'Avoir' },
        credit_note_draft_title: 'Avoir en brouillon',
        fixed: { issued: 'Émis, il se corrige par un avoir.' },
        totals: {
          tax: '{{code}} {{rate}} % · base {{base}}',
          withholding: 'Retenue {{code}} {{rate}} %',
        },
      },
    });
  }
}

const options: InvoiceOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [{ id: 'e1', code: 'SIEGE', name: 'Siège', isDefault: true }],
  customers: [
    {
      id: 'k1',
      number: 'CLI-1',
      name: 'Carthage',
      excludedFamilies: [],
      defaultDiscountRate: null,
      defaultTaxComponentIds: [],
    },
    {
      id: 'k2',
      number: 'CLI-2',
      name: 'Méditerranée',
      excludedFamilies: [],
      defaultDiscountRate: '5',
      defaultTaxComponentIds: ['w1'],
    },
  ],
  products: [
    {
      id: 'p1',
      reference: 'ART-1',
      name: 'Conception',
      unitId: 'u1',
      unitPriceNet: '1800.0000',
      defaultTaxComponentIds: ['t1'],
    },
  ],
  units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
  taxes: [
    {
      id: 't1',
      code: 'TVA19',
      name: 'TVA 19 %',
      kind: 'percentage_line',
      family: 'vat',
      rate: '19',
      amount: null,
      threshold: null,
      isDefault: false,
    },
    {
      id: 's1',
      code: 'TIMBRE',
      name: 'Timbre fiscal',
      kind: 'fixed_document',
      family: 'stamp',
      rate: null,
      amount: '1.000',
      threshold: null,
      isDefault: true,
    },
    {
      id: 'w1',
      code: 'RS1',
      name: 'Retenue à la source 1 %',
      kind: 'withholding_total',
      family: 'withholding',
      rate: '1',
      amount: null,
      threshold: '1000.000',
      isDefault: false,
    },
  ],
};
const draft: InvoiceRow = {
  id: 'i1',
  type: 'invoice',
  correctsInvoiceId: null,
  number: null,
  status: 'draft',
  customerId: 'k1',
  customerName: null,
  establishmentId: 'e1',
  issueDate: null,
  dueDate: null,
  supplyDate: null,
  paymentTermsDays: null,
  customerReference: null,
  notesPrinted: null,
  notesInternal: null,
  discountAmount: null,
  documentTaxComponentIds: ['s1'],
  lines: [
    {
      productId: 'p1',
      description: 'Conception',
      quantity: '1.000',
      unitId: 'u1',
      unitPriceNet: '1800.0000',
      discountRate: null,
      taxComponentIds: ['t1'],
      sourceDeliveryNoteLineId: null,
      net: '1800.000',
    },
  ],
  subtotalNet: '1800.000',
  documentDiscount: '0.000',
  totalNet: '1800.000',
  taxes: [{ code: 'TVA19', rate: '19.000', base: '1800.000', amount: '342.000' }],
  totalTax: '342.000',
  fixedTaxes: [{ code: 'TIMBRE', amount: '1.000' }],
  total: '2143.000',
  withholdings: [{ code: 'RS1', rate: '1.000', base: '2143.000', amount: '21.430' }],
  amountDue: '2121.570',
  amountPaid: '0.000',
  amountCredited: '0.000',
  payments: [],
};
const issued: InvoiceRow = {
  ...draft,
  number: 'FAC-2026-00045',
  status: 'partially_paid',
  customerName: 'Carthage SA',
  issueDate: '2026-09-10',
  dueDate: '2999-10-10',
  amountPaid: '1000.000',
  amountDue: '1121.570',
  payments: [
    {
      id: 'y1',
      date: '2026-09-12',
      amount: '1000.000',
      method: 'transfer',
      reference: 'VIR 882104',
      notes: null,
    },
  ],
};

describe('InvoicePage', () => {
  const error = signal<InvoicesError | null>(null);
  const invoice = signal<InvoiceRow | null>(null);
  const facade = {
    options: signal<InvoiceOptions | null>(options).asReadonly(),
    invoice: invoice.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadInvoice: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    reviseAndIssue: vi.fn(),
    cancel: vi.fn(),
    creditNote: vi.fn(),
    recordPayment: vi.fn(),
    deletePayment: vi.fn(),
    clearError: vi.fn(),
    pdfUrl: (companyId: string, id: string) => `/api/companies/${companyId}/invoices/${id}/pdf`,
  };
  const granted = new Set<string>();
  const auth = {
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme', timezone: 'Africa/Tunis' },
    }),
    hasPermission: (permission: string) => granted.has(permission),
  };
  let fixture: ComponentFixture<InvoicePage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const text = (testId: string): string => (q(testId)?.textContent ?? '').replace(/\s+/g, ' ');
  const checked = (testId: string): boolean =>
    (q(testId)?.querySelector('input[type="checkbox"]') as HTMLInputElement | null)?.checked ??
    false;

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

  async function choose(testId: string, label: string): Promise<void> {
    (q(testId)?.querySelector('.mat-mdc-select-trigger') as HTMLElement).click();
    await settle();
    const option = Array.from(document.body.querySelectorAll<HTMLElement>('mat-option')).find(
      (each) => each.textContent?.trim() === label,
    );
    expect(option, label).toBeDefined();
    option!.click();
    await settle();
  }

  async function open(invoiceId: string | undefined): Promise<void> {
    fixture = TestBed.createComponent(InvoicePage);
    if (invoiceId !== undefined) {
      fixture.componentRef.setInput('invoiceId', invoiceId);
    }
    await settle();
  }

  beforeEach(() => {
    error.set(null);
    invoice.set(null);
    granted.clear();
    ['invoice.read', 'invoice.write', 'invoice.issue', 'payment.write'].forEach((each) =>
      granted.add(each),
    );
    facade.loadInvoice.mockReset().mockResolvedValue(undefined);
    facade.create.mockReset().mockResolvedValue({ ...draft, id: 'i9' });
    facade.revise.mockReset().mockResolvedValue(draft);
    facade.reviseAndIssue.mockReset().mockResolvedValue(issued);
    facade.cancel.mockReset().mockResolvedValue({ ...draft, status: 'cancelled' });
    facade.creditNote.mockReset();
    facade.recordPayment.mockReset().mockResolvedValue(true);
    facade.deletePayment.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      imports: [InvoicePage],
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
        { provide: InvoicesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('drafts an invoice for a customer, with the document taxes it would be charged, then opens it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadInvoice).toHaveBeenCalledWith('c1', null);
    expect(q('invoice-issue')).toBeNull();

    await choose('field-customerId', 'CLI-2 · Méditerranée');
    expect(checked('document-tax-TIMBRE')).toBe(true);
    expect(checked('document-tax-RS1')).toBe(true);
    await choose('line-0-product', 'ART-1 · Conception');
    expect((q('line-0-discount') as HTMLInputElement).value).toBe('5');
    q('invoice-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        customerId: 'k2',
        establishmentId: 'e1',
        documentTaxComponentIds: ['s1', 'w1'],
        lines: [
          {
            productId: 'p1',
            description: 'Conception',
            quantity: '1',
            unitId: 'u1',
            unitPriceNet: '1800.000',
            discountRate: '5',
            taxComponentIds: ['t1'],
            sourceDeliveryNoteLineId: null,
          },
        ],
      }),
    );
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/invoices', 'i9'], { replaceUrl: true }),
    );
  });

  it('gives lines whose discount nobody typed the discount of the customer chosen', async () => {
    invoice.set(draft);
    await open('i1');
    q('line-add')!.click();
    await settle();
    type('line-1-discount', '12');
    await choose('field-customerId', 'CLI-2 · Méditerranée');
    expect((q('line-0-discount') as HTMLInputElement).value).toBe('5');
    expect((q('line-1-discount') as HTMLInputElement).value).toBe('12');
  });

  it('keeps the document taxes a draft names, and resets them when its customer changes', async () => {
    invoice.set({ ...draft, documentTaxComponentIds: [] });
    await open('i1');
    expect(checked('document-tax-TIMBRE')).toBe(false);

    await choose('field-customerId', 'CLI-2 · Méditerranée');
    expect(checked('document-tax-TIMBRE')).toBe(true);
    expect(checked('document-tax-RS1')).toBe(true);
  });

  it('shows a draft’s totals as the API works them out, withholding and net payable included', async () => {
    invoice.set(draft);
    await open('i1');
    const totals = text('invoice-totals');
    expect(totals).toContain('TVA19 19 % · base 1 800,000');
    expect(totals).toContain('342,000');
    expect(totals).toContain('TIMBRE');
    expect(totals).toContain('2 143,000');
    expect(totals).toContain('Retenue RS1 1 %');
    expect(totals).toMatch(/[−-]21,430/);
    expect(text('invoice-net-due')).toContain('2 121,570');
    expect(text('line-0-net')).toContain('1 800,000');
  });

  it('issues exactly what is on screen', async () => {
    invoice.set(draft);
    await open('i1');
    type('line-0-quantity', '3');
    q('invoice-issue')!.click();
    await settle();
    expect(facade.reviseAndIssue).toHaveBeenCalledWith(
      'c1',
      'i1',
      expect.objectContaining({
        documentTaxComponentIds: ['s1'],
        lines: [expect.objectContaining({ quantity: '3' })],
      }),
    );
  });

  it('cancels a draft only once confirmed', async () => {
    invoice.set(draft);
    await open('i1');
    q('invoice-cancel')!.click();
    await settle();
    expect(facade.cancel).not.toHaveBeenCalled();
    q('invoice-cancel-confirm')!.click();
    await settle();
    expect(facade.cancel).toHaveBeenCalledWith('c1', 'i1');
  });

  it('shows an issued invoice fixed, with what is left to collect and its payments', async () => {
    invoice.set(issued);
    await open('i1');

    expect(text('invoice-title')).toContain('FAC-2026-00045');
    expect(text('invoice-fixed')).toContain('avoir');
    expect(text('invoice-amount-due')).toContain('1 121,570');
    expect(text('payment-y1')).toContain('VIR 882104');
    expect(text('payment-y1')).toContain('1 000,000');
    expect((q('line-0-quantity') as HTMLInputElement).disabled).toBe(true);
    expect(q('invoice-save')).toBeNull();
    expect(q('invoice-issue')).toBeNull();
    expect(q('invoice-cancel')).toBeNull();
    expect(q('invoice-pdf')?.getAttribute('href')).toBe('/api/companies/c1/invoices/i1/pdf');
  });

  it('records a payment on the company’s today, for what is still due unless changed', async () => {
    invoice.set(issued);
    await open('i1');
    expect((q('field-amount') as HTMLInputElement).value).toBe('1121.570');
    expect((q('field-date') as HTMLInputElement).value).toBe(todayIn('Africa/Tunis'));

    type('field-amount', '500');
    type('field-reference', 'CHQ 12');
    q('invoice-payment-record')!.click();
    await settle();
    expect(facade.recordPayment).toHaveBeenCalledWith('c1', 'i1', {
      date: todayIn('Africa/Tunis'),
      amount: '500',
      method: 'transfer',
      reference: 'CHQ 12',
      notes: null,
    });
    expect(q('invoice-payment-recorded')).not.toBeNull();
  });

  it('deletes a payment only once confirmed', async () => {
    invoice.set(issued);
    await open('i1');
    q('payment-y1-delete')!.click();
    await settle();
    expect(facade.deletePayment).not.toHaveBeenCalled();
    q('payment-y1-delete-confirm')!.click();
    await settle();
    expect(facade.deletePayment).toHaveBeenCalledWith('c1', 'i1', 'y1');
  });

  it('offers no payment on an invoice with nothing left due, nor to someone who may not record one', async () => {
    invoice.set({ ...issued, status: 'paid', amountDue: '0.000' });
    await open('i1');
    expect(q('invoice-payments')).not.toBeNull();
    expect(q('invoice-payment-record')).toBeNull();
    expect(q('invoice-credit-note')).toBeNull();

    granted.delete('payment.write');
    invoice.set(issued);
    await open('i1');
    expect(q('invoice-payment-record')).toBeNull();
    expect(q('payment-y1-delete')).toBeNull();
  });

  it('shows an issued invoice past its due day as overdue', async () => {
    invoice.set({ ...issued, status: 'issued', dueDate: '2000-01-01' });
    await open('i1');
    expect(text('invoice-status')).toContain('En retard');
  });

  it('drafts a credit note from an issued invoice and opens it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    facade.creditNote.mockResolvedValue({
      ...draft,
      id: 'cn1',
      type: 'credit_note',
      correctsInvoiceId: 'i1',
    });
    invoice.set(issued);
    await open('i1');
    q('invoice-credit-note')!.click();
    await settle();
    expect(facade.creditNote).toHaveBeenCalledWith('c1', 'i1');
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/invoices', 'cn1']));
  });

  it('shows a credit note as one: its title, its invoice, no payments and no credit note of its own', async () => {
    invoice.set({ ...draft, type: 'credit_note', correctsInvoiceId: 'i0' });
    await open('i1');
    expect(text('invoice-title')).toContain('Avoir en brouillon');
    expect(q('invoice-corrects')?.getAttribute('href')).toBe('/invoices/i0');
    expect(q('invoice-issue')).not.toBeNull();

    invoice.set({ ...issued, type: 'credit_note', correctsInvoiceId: 'i0', status: 'issued' });
    await settle();
    expect(q('invoice-payments')).toBeNull();
    expect(q('invoice-due')).toBeNull();
    expect(q('invoice-credit-note')).toBeNull();
  });

  it('shows a reader the invoice and its PDF without a way to change it', async () => {
    granted.clear();
    granted.add('invoice.read');
    invoice.set(draft);
    await open('i1');
    expect(q('invoice-read-only')).not.toBeNull();
    expect(q('invoice-save')).toBeNull();
    expect(q('invoice-issue')).toBeNull();
    expect(q('invoice-cancel')).toBeNull();
    expect(q('line-add')).toBeNull();
    expect(q('invoice-pdf')).not.toBeNull();
  });

  it('says why the API refused', async () => {
    error.set('invalid');
    invoice.set(draft);
    await open('i1');
    expect(text('invoice-error')).toContain('Refusé.');
  });
});

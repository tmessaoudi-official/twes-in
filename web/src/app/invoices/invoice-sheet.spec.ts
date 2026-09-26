// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
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
import { InvoiceSheet } from './invoice-sheet';
import { InvoicesFacade } from './invoices-facade';
import type { InvoiceOptions, InvoiceRow } from './invoices-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      invoices: {
        statuses: { partially_paid: 'Partiellement payée', paid: 'Soldée', overdue: 'En retard' },
        fields: { issueDate: 'Émise le', dueDate: 'Échéance', establishmentId: 'Établissement' },
        payments: { title: 'Paiements', methods: { transfer: 'Virement' } },
        due: { left: 'Reste à encaisser', paid_of: '{{paid}} payés sur {{total}}' },
        home: {
          late: '{{days}} j de retard',
          due_today: 'Échéance aujourd’hui',
          due_in: 'Échéance dans {{days}} j',
        },
        actions: { pdf: 'Télécharger le PDF' },
        sheet: {
          label: 'Aperçu de {{number}}',
          terms: '{{days}} jours',
          open: 'Ouvrir',
          pay: 'Encaisser',
          close: 'Fermer l’aperçu',
          unreadable: 'Ce document ne peut pas être lu.',
        },
      },
    });
  }
}

const invoice: InvoiceRow = {
  id: 'i1',
  type: 'invoice',
  correctsInvoiceId: null,
  creditNoteReason: null,
  number: 'FAC-2026-00045',
  status: 'partially_paid',
  customerId: 'k1',
  recordedCustomerName: 'Groupe Carthage Médias',
  customerName: 'Carthage (today)',
  establishmentId: 'e1',
  issueDate: '2026-09-10',
  dueDate: '2026-10-10',
  supplyDate: null,
  paymentTermsDays: 30,
  customerReference: null,
  notesPrinted: null,
  notesInternal: null,
  discountAmount: null,
  documentTaxComponentIds: [],
  lines: [],
  subtotalNet: '10000.000',
  documentDiscount: '0.000',
  totalNet: '10000.000',
  taxes: [],
  totalTax: '1900.000',
  fixedTaxes: [],
  total: '11900.000',
  withholdings: [],
  amountDue: '5950.000',
  amountPaid: '5950.000',
  amountCredited: '0.000',
  payments: [
    {
      id: 'p1',
      date: '2026-09-12',
      amount: '5950.000',
      method: 'transfer',
      reference: 'VIR 882104',
      notes: null,
    },
  ],
};

const options: InvoiceOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [{ id: 'e1', code: 'S', name: 'Siège — Tunis', isDefault: true }],
  units: [],
  taxes: [],
};

// docs/SPEC.md § 7, 2026-09-24 22:51 and 2026-09-26: from a tablet up, a document opens as a sheet over its list.
describe('InvoiceSheet', () => {
  const facade = {
    options: signal<InvoiceOptions | null>(options).asReadonly(),
    peek: vi.fn(),
    pdfUrl: (companyId: string, id: string) => `/api/companies/${companyId}/invoices/${id}/pdf`,
  };
  const granted = new Set(['payment.write']);
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', timezone: 'Africa/Tunis' } }),
    hasPermission: (permission: string) => granted.has(permission),
    hasModule: (module: string) => modules.has(module),
  };
  const modules = new Set(['customers']);
  let fixture: ComponentFixture<InvoiceSheet>;
  let closed = 0;

  const q = (id: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${id}"]`);
  const text = (id: string) => (q(id)?.textContent ?? '').replace(/\s+/g, ' ').trim();

  async function open(row: InvoiceRow | null = invoice, today = '2026-09-16'): Promise<void> {
    facade.peek.mockReset().mockResolvedValue(row);
    fixture = TestBed.createComponent(InvoiceSheet);
    fixture.componentRef.setInput('invoiceId', 'i1');
    fixture.componentRef.setInput('today', today);
    fixture.componentInstance.closed.subscribe(() => (closed += 1));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    closed = 0;
    granted.clear();
    granted.add('payment.write');
    modules.clear();
    modules.add('customers');
    TestBed.configureTestingModule({
      imports: [InvoiceSheet],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: InvoicesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('reads the document it is given and says what is still to collect, and by when', async () => {
    await open();
    expect(facade.peek).toHaveBeenCalledWith('c1', 'i1');

    expect(text('invoice-sheet-title')).toBe('FAC-2026-00045');
    expect(text('invoice-sheet-status')).toContain('Partiellement payée');
    // The customer as the document recorded them, leading to their record.
    expect(text('invoice-sheet-customer')).toContain('Groupe Carthage Médias');
    expect(q('invoice-sheet-customer')?.getAttribute('href')).toBe('/customers/k1');
    expect(text('invoice-sheet-due')).toContain('Reste à encaisser');
    expect(text('invoice-sheet-due')).toContain('5 950,000');
    expect(text('invoice-sheet-due')).toContain('TND');
    expect(text('invoice-sheet-due')).toContain('5 950,000 payés sur 11 900,000');
    expect(text('invoice-sheet-due')).toContain('Échéance dans 24 j');
    expect(q('invoice-sheet-progress')?.getAttribute('aria-valuenow')).toBe('50');
  });

  it('names the customer without a link where the customers module is off', async () => {
    modules.clear();
    await open();
    expect(text('invoice-sheet-customer')).toContain('Groupe Carthage Médias');
    expect(q('invoice-sheet-customer')?.getAttribute('href')).toBeNull();
  });

  it('lays out when it was issued, its terms, its establishment and its payments', async () => {
    await open();
    expect(text('invoice-sheet-details')).toContain('10/09/2026');
    expect(text('invoice-sheet-details')).toContain('10/10/2026 · 30 jours');
    expect(text('invoice-sheet-details')).toContain('Siège — Tunis');
    expect(text('invoice-sheet-payment-p1')).toContain('Virement');
    expect(text('invoice-sheet-payment-p1')).toContain('12/09/2026');
    expect(text('invoice-sheet-payment-p1')).toContain('VIR 882104');
    expect(text('invoice-sheet-payment-p1')).toContain('5 950,000');
  });

  it('says how late a document is once its due day has passed', async () => {
    await open(invoice, '2026-10-13');
    expect(text('invoice-sheet-due')).toContain('3 j de retard');
  });

  it('opens the record, the payment and the PDF where the record page has them', async () => {
    await open();
    expect(q('invoice-sheet-open')?.getAttribute('href')).toBe('/invoices/i1');
    expect(q('invoice-sheet-pay')?.getAttribute('href')).toBe('/invoices/i1?pay=1');
    expect(q('invoice-sheet-pdf')?.getAttribute('href')).toBe('/api/companies/c1/invoices/i1/pdf');
  });

  it('offers no payment to somebody who may not record one, nor on a settled document', async () => {
    granted.clear();
    await open();
    expect(q('invoice-sheet-pay')).toBeNull();

    granted.add('payment.write');
    await open({ ...invoice, status: 'paid', amountDue: '0.000', amountPaid: '11900.000' });
    expect(q('invoice-sheet-pay')).toBeNull();
    expect(q('invoice-sheet-due')).toBeNull();
  });

  it('closes when asked, from its button or with Escape', async () => {
    await open();
    q('invoice-sheet-close')!.click();
    q('invoice-sheet')!.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    );
    expect(closed).toBe(2);
  });

  it('says so when the document cannot be read', async () => {
    await open(null);
    expect(q('invoice-sheet-unreadable')).not.toBeNull();
    expect(q('invoice-sheet-title')).toBeNull();
  });
});

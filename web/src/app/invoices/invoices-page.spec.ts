// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
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
import { InvoicesFacade } from './invoices-facade';
import { InvoicesPage } from './invoices-page';
import type { InvoiceOptions, InvoiceRow, InvoicesError } from './invoices-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      invoices: {
        draft: 'Brouillon',
        types: { credit_note: 'Avoir' },
        statuses: {
          draft: 'Brouillon',
          issued: 'Émise',
          overdue: 'En retard',
          partially_paid: 'Partiellement payée',
          paid: 'Payée',
        },
        errors: { network: 'Le serveur est injoignable.' },
      },
    });
  }
}

const issued: InvoiceRow = {
  id: 'i1',
  type: 'invoice',
  correctsInvoiceId: null,
  number: 'FAC-2026-00045',
  status: 'partially_paid',
  customerId: 'k1',
  customerName: 'Groupe Carthage Médias',
  establishmentId: 'e1',
  issueDate: '2026-09-10',
  dueDate: '2999-10-10',
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
  total: '11900',
  withholdings: [],
  amountDue: '5950',
  amountPaid: '5950.000',
  amountCredited: '0.000',
  payments: [],
};

describe('InvoicesPage', () => {
  const error = signal<InvoicesError | null>(null);
  const facade = {
    invoices: signal<readonly InvoiceRow[]>([
      issued,
      { ...issued, id: 'i2', number: 'FAC-2026-00043', status: 'issued', dueDate: '2000-01-01' },
      { ...issued, id: 'i3', number: null, status: 'draft', customerName: null, dueDate: null },
      { ...issued, id: 'i4', number: 'AV-2026-00001', type: 'credit_note', status: 'issued' },
    ]).asReadonly(),
    options: signal<InvoiceOptions | null>({
      currency: 'TND',
      currencyScale: 3,
      establishments: [],
      customers: [
        {
          id: 'k1',
          number: 'CLI-1',
          name: 'Carthage',
          excludedFamilies: [],
          defaultDiscountRate: null,
          defaultTaxComponentIds: [],
        },
      ],
      products: [],
      units: [],
      taxes: [],
    }).asReadonly(),
    error: error.asReadonly(),
    total: signal(2).asReadonly(),
    loadListContext: vi.fn(),
    loadPage: vi.fn(),
  };
  const auth = {
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme', timezone: 'Africa/Tunis' },
    }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<InvoicesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const text = (testId: string): string => (q(testId)?.textContent ?? '').replace(/\s/g, ' ');
  const tone = (testId: string) =>
    q(testId)?.querySelector('app-status-badge')?.getAttribute('data-tone');

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    error.set(null);
    facade.loadListContext.mockReset().mockResolvedValue(undefined);
    facade.loadPage.mockReset().mockResolvedValue(undefined);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [InvoicesPage],
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
    fixture = TestBed.createComponent(InvoicesPage);
    await settle();
  });

  it('lists each invoice with its number, customer, total, amount still due and status', () => {
    expect(facade.loadListContext).toHaveBeenCalledWith('c1');
    const row = text('invoice-i1');
    expect(row).toContain('FAC-2026-00045');
    expect(row).toContain('Groupe Carthage Médias');
    expect(row).toContain('11 900,000');
    expect(row).toContain('5 950,000');
    expect(row).toContain('Partiellement payée');
    expect(tone('invoice-i1')).toBe('warning');
    expect(q('invoice-open-i1')?.getAttribute('href')).toBe('/invoices/i1');
  });

  it('shows an issued invoice past its due day as overdue, in the danger tone', () => {
    expect(text('invoice-i2')).toContain('En retard');
    expect(tone('invoice-i2')).toBe('danger');
  });

  it('names a draft by today’s customer and marks a credit note as one', () => {
    expect(text('invoice-i3')).toContain('Brouillon');
    expect(text('invoice-i3')).toContain('Carthage');
    expect(tone('invoice-i3')).toBe('neutral');
    expect(text('invoice-i4')).toContain('Avoir');
  });

  it('offers a new invoice to a writer only', async () => {
    expect(q('invoice-add')).not.toBeNull();

    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(InvoicesPage);
    await settle();
    expect(q('invoice-add')).toBeNull();
  });

  it('says why the list could not be read', async () => {
    error.set('network');
    await settle();
    expect(q('invoices-error')?.textContent).toContain('injoignable');
  });
});

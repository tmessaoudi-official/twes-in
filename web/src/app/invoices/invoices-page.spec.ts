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
import { WINDOW_CLASS, type WindowClass } from '../shared/ui/window-class';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { InvoicesFacade } from './invoices-facade';
import { InvoicesPage } from './invoices-page';
import type {
  InvoiceOptions,
  InvoiceRow,
  InvoicesError,
  InvoiceStatusCounts,
} from './invoices-types';

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
          paid: 'Soldée',
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
  creditNoteReason: null,
  number: 'FAC-2026-00045',
  status: 'partially_paid',
  customerId: 'k1',
  recordedCustomerName: 'Groupe Carthage Médias',
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
  const statusCounts = signal<InvoiceStatusCounts | null>(null);
  const facade = {
    invoices: signal<readonly InvoiceRow[]>([
      issued,
      { ...issued, id: 'i2', number: 'FAC-2026-00043', status: 'issued', dueDate: '2000-01-01' },
      {
        ...issued,
        id: 'i3',
        number: null,
        status: 'draft',
        recordedCustomerName: null,
        dueDate: null,
      },
      { ...issued, id: 'i4', number: 'AV-2026-00001', type: 'credit_note', status: 'issued' },
    ]).asReadonly(),
    options: signal<InvoiceOptions | null>({
      currency: 'TND',
      currencyScale: 3,
      establishments: [],
      units: [],
      taxes: [],
    }).asReadonly(),
    error: error.asReadonly(),
    total: signal(2).asReadonly(),
    statusCounts: statusCounts.asReadonly(),
    loadListContext: vi.fn(),
    loadPage: vi.fn(),
    loadStatusCounts: vi.fn(),
    peek: vi.fn(),
    pdfUrl: (companyId: string, id: string) => `/api/companies/${companyId}/invoices/${id}/pdf`,
  };
  const windowClass = signal<WindowClass>('expanded');
  const auth = {
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme', timezone: 'Africa/Tunis' },
    }),
    hasPermission: vi.fn(),
    hasModule: () => true,
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
    facade.loadStatusCounts.mockReset().mockResolvedValue(undefined);
    statusCounts.set(null);
    windowClass.set('expanded');
    facade.peek.mockReset().mockResolvedValue(issued);
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
        { provide: WINDOW_CLASS, useValue: windowClass },
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
    // The row's number IS the link now; "Ouvrir" is gone (design review finding 1).
    expect(q('list-link-i1')?.textContent).toContain('FAC-2026-00045');
    expect(q('invoice-open-i1')).toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-24 22:51 (row 123) and 2026-09-26: from a tablet up, an issued document opens as a sheet
  // over its list, named in the list's own address; a draft opens its editor, and a phone the record itself.
  it('opens an issued document as a sheet over the list, and a draft in its editor', async () => {
    expect(q('list-link-i1')?.getAttribute('href')).toBe('/invoices?open=i1');
    expect(q('list-link-i4')?.getAttribute('href')).toBe('/invoices?open=i4');
    expect(q('list-link-i3')?.getAttribute('href')).toBe('/invoices/i3');

    windowClass.set('medium');
    await settle();
    expect(q('list-link-i1')?.getAttribute('href')).toBe('/invoices?open=i1');
  });

  it('opens every document at its own address on a phone, where a sheet would cover the list', async () => {
    windowClass.set('compact');
    await settle();
    expect(q('list-link-i1')?.getAttribute('href')).toBe('/invoices/i1');
    expect(q('list-link-i3')?.getAttribute('href')).toBe('/invoices/i3');
  });

  it('shows the sheet of the document its address names, and forgets it when the sheet is closed', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    expect(q('invoice-sheet')).toBeNull();
    fixture.componentRef.setInput('open', 'i1');
    await settle();
    expect(facade.peek).toHaveBeenCalledWith('c1', 'i1');
    expect(q('invoice-sheet')).not.toBeNull();
    // The list stays beside it, still usable.
    expect(q('invoice-i2')).not.toBeNull();

    q('invoice-sheet-close')!.click();
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { open: null }, queryParamsHandling: 'merge' }),
    );
  });

  it('opens at its own address a sheet named on a phone, as a link copied from a wider screen', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    windowClass.set('compact');
    fixture.componentRef.setInput('open', 'i1');
    await settle();
    expect(q('invoice-sheet')).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/invoices', 'i1'], { replaceUrl: true });
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

  // docs/SPEC.md § 7, 2026-09-26: each status chip says how many it would list, as the API counts them.
  it('asks the API what each status would list, and says it on the chips', async () => {
    expect(facade.loadStatusCounts).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ q: '', documentType: null }),
    );
    statusCounts.set({
      all: 48,
      statuses: { draft: 2, issued: 6, overdue: 3, partially_paid: 1, paid: 34, cancelled: 2 },
    });
    await settle();

    const count = (id: string) => q(id)?.querySelector('.twes-chip-count')?.textContent;
    expect(count('list-facet-status-all')).toBe('48');
    expect(count('list-facet-status-overdue')).toBe('3');
    expect(count('list-facet-status-paid')).toBe('34');
    // The kind has no counts from the API, so it shows none rather than the page's.
    expect(count('list-facet-type-invoice')).toBeUndefined();
  });

  it('names the key that opens a new invoice from here (docs/SPEC.md § 7, 2026-09-24 22:51)', () => {
    expect(q('invoice-add')?.getAttribute('aria-keyshortcuts')).toBe('n');
    expect(q('invoice-add')?.querySelector('kbd')?.textContent).toBe('N');
  });

  it('says why the list could not be read', async () => {
    error.set('network');
    await settle();
    expect(q('invoices-error')?.textContent).toContain('injoignable');
  });
});

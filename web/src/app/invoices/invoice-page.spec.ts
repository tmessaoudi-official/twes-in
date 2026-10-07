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
import { formatDay, todayIn } from '../shared/i18n/format';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { InvoicePage } from './invoice-page';
import { InvoicesFacade } from './invoices-facade';
import { PREVIEW_DELAY, type PreviewBody } from '../shared/documents/document-figures';
import { ProductScans } from '../products/product-scans';
import { ScanBus } from '../shared/scan/scan-bus';
import { ScreenActions } from '../shared/actions/screen-actions';
import { CustomerDisplay } from '../shared/customer-display/customer-display';
import type {
  CustomerOption,
  InvoiceOptions,
  InvoiceRow,
  InvoicesError,
  ProductOption,
} from './invoices-types';
import type { PickAsked } from '../shared/form/pick-api';
import {
  effectToasts,
  offeredNext,
  provideQuietFeedback,
  successToasts,
} from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { UnsavedChanges } from '../shared/form/unsaved-changes';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      invoices: {
        errors: { invalid: 'Refusé.' },
        statuses: { draft: 'Brouillon', issued: 'Émise', overdue: 'En retard', paid: 'Soldée' },
        types: { credit_note: 'Avoir' },
        credit_note_draft_title: 'Avoir en brouillon',
        credit_note: { reason_shown: 'Motif : {{reason}}' },
        fixed: { issued: 'Émis, il se corrige par un avoir.' },
        due: { left: 'Reste à encaisser', paid_of: '{{paid}} payés sur {{total}}' },
        totals: {
          tax: '{{code}} {{rate}} % · base {{base}}',
          tax_named: '{{name}} · base {{base}}',
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
/** What the pickers answer; the page is never handed either list whole. */
const customers: CustomerOption[] = [
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
  {
    id: 'k3',
    number: 'CLI-3',
    name: 'Ambassade',
    excludedFamilies: ['vat', 'stamp'],
    defaultDiscountRate: null,
    defaultTaxComponentIds: [],
  },
];
const products: ProductOption[] = [
  {
    id: 'p1',
    reference: 'ART-1',
    name: 'Conception',
    unitId: 'u1',
    unitPriceNet: '1800.0000',
    defaultTaxComponentIds: ['t1'],
    tracking: 'none',
  },
];

const draft: InvoiceRow = {
  id: 'i1',
  type: 'invoice',
  correctsInvoiceId: null,
  creditNoteReason: null,
  deposit: false,
  quoteId: null,
  number: null,
  status: 'draft',
  customerId: 'k1',
  recordedCustomerName: null,
  customerName: 'Carthage',
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
      sourceLeft: null,
      productReference: 'ART-1',
      productName: 'Conception',
      productTracking: 'none',
      lotCode: null,
      returned: false,
      deductsInvoiceId: null,
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
  netToPay: '2121.570',
  amountDue: '2121.570',
  amountPaid: '0.000',
  amountCredited: '0.000',
  payments: [],
};
const issued: InvoiceRow = {
  ...draft,
  number: 'FAC-2026-00045',
  status: 'partially_paid',
  recordedCustomerName: 'Carthage SA',
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
    pickCustomers: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? customers.filter((each) => asked.ids.includes(each.id)) : customers,
    ),
    productPrice: vi.fn(),
    pickProducts: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? products.filter((each) => asked.ids.includes(each.id)) : products,
    ),
    create: vi.fn(),
    revise: vi.fn(),
    reviseAndIssue: vi.fn(),
    cancel: vi.fn(),
    creditNote: vi.fn(),
    duplicate: vi.fn(),
    recordPayment: vi.fn(),
    customerCredit: vi.fn(),
    applyCredit: vi.fn(),
    overpay: vi.fn(),
    deletePayment: vi.fn(),
    preview: vi.fn(),
    clearError: vi.fn(),
    pdfUrl: (companyId: string, id: string) => `/api/companies/${companyId}/invoices/${id}/pdf`,
    pdfCopyUrl: (companyId: string, id: string, kind: string) =>
      `/api/companies/${companyId}/invoices/${id}/pdf/${kind}`,
  };
  const scans = { piecesPerScan: vi.fn(), named: vi.fn() };
  const display = { show: vi.fn(), total: vi.fn(), clear: vi.fn(), openWindow: vi.fn() };
  const granted = new Set<string>();
  const modulesOn = new Set<string>();
  const auth = {
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme', timezone: 'Africa/Tunis' },
      plannedModules: [
        { key: 'mailing', planned: 'v1' },
        { key: 'whatsapp', planned: 'v1' },
        { key: 'recurring', planned: 'v1' },
      ],
    }),
    hasPermission: (permission: string) => granted.has(permission),
    hasModule: (module: string) => modulesOn.has(module),
  };
  let fixture: ComponentFixture<InvoicePage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  // A menu and a dialog open in the CDK overlay, which hangs off the body rather than the component.
  const over = (testId: string): HTMLElement | null =>
    document.body.querySelector(
      `.cdk-overlay-container [data-testid="${testId}"]`,
    ) as HTMLElement | null;
  const text = (testId: string): string => (q(testId)?.textContent ?? '').replace(/\s+/g, ' ');
  /** What a multiple Select shows as chosen: the chips of its trigger. */
  const chosen = (testId: string): (string | undefined)[] =>
    Array.from(q(testId)?.querySelectorAll('[data-chip]') ?? []).map((chip) =>
      chip.textContent?.trim(),
    );

  /** What a multiple Select offers: opened, its options' test ids read, closed. Nothing when it is not there. */
  async function offered(testId: string): Promise<string[]> {
    const trigger = q(testId);
    if (trigger === null) return [];
    trigger.click();
    await settle();
    const listbox = document.body.querySelector('[role="listbox"]');
    const ids = Array.from(listbox?.querySelectorAll('[role="option"]') ?? []).map(
      (option) => option.getAttribute('data-testid') ?? '',
    );
    listbox?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await settle();
    return ids;
  }

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(testId: string, value: string): void {
    typeIn(q(testId) as HTMLInputElement, value);
  }

  function typeIn(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  /**
   * Picks a row in a picker. Nothing is typed: the field asks the API once as it opens, on no words at all, so what
   * a person sees before typing is already there — which is exactly what the screen does.
   */
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

  async function open(invoiceId: string | undefined): Promise<void> {
    fixture = TestBed.createComponent(InvoicePage);
    if (invoiceId !== undefined) {
      fixture.componentRef.setInput('invoiceId', invoiceId);
    }
    await settle();
    // The customer an open document names is resolved by id, one turn after the document itself. Flushing the
    // microtasks is enough and costs nothing: a second whenStable() per open makes this suite time out.
    await Promise.resolve();
    await Promise.resolve();
    fixture.detectChanges();
  }

  beforeEach(() => {
    error.set(null);
    invoice.set(null);
    granted.clear();
    modulesOn.clear();
    facade.productPrice.mockReset().mockResolvedValue(null);
    ['invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'payment.write'].forEach(
      (each) => granted.add(each),
    );
    facade.loadInvoice.mockReset().mockResolvedValue(undefined);
    facade.pickCustomers.mockClear();
    facade.pickProducts.mockClear();
    scans.piecesPerScan.mockReset().mockResolvedValue(null);
    scans.named.mockReset().mockResolvedValue(null);
    Object.values(display).forEach((each) => each.mockReset());
    facade.create.mockReset().mockResolvedValue({ ...draft, id: 'i9' });
    facade.revise.mockReset().mockResolvedValue(draft);
    facade.reviseAndIssue.mockReset().mockResolvedValue(issued);
    facade.cancel.mockReset().mockResolvedValue({ ...draft, status: 'cancelled' });
    facade.creditNote.mockReset();
    facade.duplicate.mockReset();
    facade.recordPayment.mockReset().mockResolvedValue(true);
    facade.customerCredit.mockReset().mockResolvedValue('0.000');
    facade.applyCredit.mockReset().mockResolvedValue(true);
    facade.overpay.mockReset().mockResolvedValue(true);
    facade.deletePayment.mockReset().mockResolvedValue(true);
    facade.preview.mockReset().mockResolvedValue(null);
    TestBed.configureTestingModule({
      imports: [InvoicePage],
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
        { provide: InvoicesFacade, useValue: facade },
        { provide: ProductScans, useValue: scans },
        { provide: CustomerDisplay, useValue: display },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: PREVIEW_DELAY, useValue: 0 },
      ],
    });
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: a page names nothing it has not loaded.
  it('titles an invoice still loading as nothing, never as a new one', async () => {
    await open('i1');

    const title = q('invoice-title');
    expect(title?.textContent?.trim()).toBe('');
    expect(title?.getAttribute('aria-hidden')).toBe('true');
  });

  it('titles the page for a new invoice as new', async () => {
    await open(undefined);

    expect(q('invoice-title')?.textContent).toContain('invoices.new_title');
    expect(q('invoice-title')?.getAttribute('aria-hidden')).toBeNull();
  });

  it('asks for the customer under the heading of its own section', async () => {
    await open(undefined);
    q('document-action-save')!.click();
    await settle();

    const section = q('invoice-customer')?.closest('fieldset');
    expect(section?.querySelector('.twes-form-section-title')?.textContent).toContain(
      'invoices.sections.parties',
    );
    expect(q('invoice-customer-error')?.closest('fieldset')).toBe(section);
  });

  it('drafts an invoice for a customer, with the document taxes it would be charged, then opens it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadInvoice).toHaveBeenCalledWith('c1', null);
    expect(q('document-action-issue')).toBeNull();

    await pick('invoice-customer', 'CLI-2 · Méditerranée');
    expect(chosen('document-taxes')).toEqual(
      expect.arrayContaining(['Timbre fiscal', 'Retenue à la source 1 %']),
    );
    await pick('line-0-product', 'ART-1 · Conception');
    expect((q('line-0-discount') as HTMLInputElement).value).toBe('5');
    q('document-action-save')!.click();
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
            lotCode: null,
            returned: false,
            deductsInvoiceId: null,
          },
        ],
      }),
    );
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/invoices', 'i9'], { replaceUrl: true }),
    );
    expect(successToasts()).toContain('invoices.saved');
  });

  // docs/SPEC.md § 7, 2026-09-23: a carton scanned into a line enters the pieces it holds.
  // docs/SPEC.md § 7, 2026-09-26, row 139: « Facturer ce client » from a customer just created.
  it('drafts an invoice for the customer its address names, then forgets the address', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    fixture = TestBed.createComponent(InvoicePage);
    fixture.componentRef.setInput('billTo', 'k2');
    await settle();
    await vi.waitFor(() => expect(chosen('document-taxes')).toContain('Timbre fiscal'));
    expect((q('invoice-customer') as HTMLInputElement).value).toContain('Méditerranée');
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { billTo: null } }),
    );

    await pick('line-0-product', 'ART-1 · Conception');
    q('document-action-save')!.click();
    await settle();
    expect(facade.create).toHaveBeenCalledWith('c1', expect.objectContaining({ customerId: 'k2' }));
  });

  it('puts on a line the pieces a scanned pack holds, and leaves a unit scan to the quantity typed', async () => {
    await open(undefined);
    const field = q('line-0-product') as HTMLInputElement;
    const scanInto = async (code: string) => {
      field.dispatchEvent(new Event('focusin'));
      for (let at = 1; at <= code.length; at += 1) typeIn(field, code.slice(0, at));
      field.dispatchEvent(
        new KeyboardEvent('keydown', {
          key: 'Enter',
          keyCode: 13,
          bubbles: true,
          cancelable: true,
        }),
      );
      await settle();
      await vi.waitFor(() => expect(scans.piecesPerScan).toHaveBeenCalled());
      await settle();
    };

    scans.piecesPerScan.mockResolvedValue(12);
    await scanInto('13017620422000');
    expect(scans.piecesPerScan).toHaveBeenCalledWith('13017620422000', 'p1');
    expect((q('line-0-quantity') as HTMLInputElement).value).toBe('12');
    expect((q('line-0-description') as HTMLInputElement).value).toBe('Conception');
  });

  it('leaves the quantity typed on a line when the code scanned into it is a single piece', async () => {
    await open(undefined);
    type('line-0-quantity', '3');
    const field = q('line-0-product') as HTMLInputElement;
    field.dispatchEvent(new Event('focusin'));
    for (const at of [...'3017620422003'].keys()) typeIn(field, '3017620422003'.slice(0, at + 1));
    field.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true, cancelable: true }),
    );
    await settle();
    await vi.waitFor(() => expect(scans.piecesPerScan).toHaveBeenCalledWith('3017620422003', 'p1'));
    await settle();

    expect((q('line-0-description') as HTMLInputElement).value).toBe('Conception');
    expect((q('line-0-quantity') as HTMLInputElement).value).toBe('3');
  });

  describe('price lists', () => {
    it('starts a line at the price of the customer’s list and says which list set it', async () => {
      modulesOn.add('price_lists');
      facade.productPrice.mockResolvedValue({ unitPriceNet: '1700.0000', priceListName: 'Gros' });
      await open(undefined);
      await pick('invoice-customer', 'CLI-2 · Méditerranée');
      await pick('line-0-product', 'ART-1 · Conception');

      expect(facade.productPrice).toHaveBeenCalledWith('c1', 'p1', 'k2', '1');
      expect((q('line-0-price') as HTMLInputElement).value).toBe('1700,000');
      expect(q('line-0-price-list')?.textContent).toContain('invoices.lines.price_list');
    });

    it('prices the lines again for another customer, who may have another list', async () => {
      modulesOn.add('price_lists');
      facade.productPrice
        .mockResolvedValueOnce({ unitPriceNet: '1700.0000', priceListName: 'Gros' })
        .mockResolvedValueOnce({ unitPriceNet: '1800.0000', priceListName: null });
      await open(undefined);
      await pick('invoice-customer', 'CLI-2 · Méditerranée');
      await pick('line-0-product', 'ART-1 · Conception');
      expect((q('line-0-price') as HTMLInputElement).value).toBe('1700,000');

      await pick('invoice-customer', 'CLI-1 · Carthage');
      await settle();

      expect(facade.productPrice).toHaveBeenLastCalledWith('c1', 'p1', 'k1', '1');
      expect((q('line-0-price') as HTMLInputElement).value).toBe('1800,000');
      expect(q('line-0-price-list')).toBeNull();
    });

    it('asks again for the new quantity, whose break may price it lower', async () => {
      modulesOn.add('price_lists');
      facade.productPrice
        .mockResolvedValueOnce({ unitPriceNet: '1700.0000', priceListName: 'Gros' })
        .mockResolvedValueOnce({ unitPriceNet: '1500.0000', priceListName: 'Gros' });
      await open(undefined);
      await pick('invoice-customer', 'CLI-2 · Méditerranée');
      await pick('line-0-product', 'ART-1 · Conception');

      type('line-0-quantity', '10');
      (q('line-0-quantity') as HTMLInputElement).dispatchEvent(new Event('blur'));
      await settle();

      expect(facade.productPrice).toHaveBeenLastCalledWith('c1', 'p1', 'k2', '10');
      expect((q('line-0-price') as HTMLInputElement).value).toBe('1500,000');
    });

    // Audit 2026-10-06, G-4: a wrong price on a fiscal document is the expensive failure, so a price the list could not
    // be asked for is marked, and an earlier quantity's answer arriving late never prices a later quantity.
    it('marks the price as not checked when the customer’s list could not be read', async () => {
      modulesOn.add('price_lists');
      facade.productPrice.mockResolvedValue(null);
      await open(undefined);
      await pick('invoice-customer', 'CLI-2 · Méditerranée');
      await pick('line-0-product', 'ART-1 · Conception');

      expect(q('line-0-price-unchecked')?.textContent).toContain('invoices.lines.price_unchecked');
      expect(q('line-0-price-list')).toBeNull();

      facade.productPrice.mockResolvedValue({ unitPriceNet: '1700.0000', priceListName: 'Gros' });
      type('line-0-quantity', '2');
      (q('line-0-quantity') as HTMLInputElement).dispatchEvent(new Event('blur'));
      await settle();
      expect(q('line-0-price-unchecked')).toBeNull();
      expect((q('line-0-price') as HTMLInputElement).value).toBe('1700,000');
    });

    it('keeps the answer for the latest quantity when an earlier one answers after it', async () => {
      modulesOn.add('price_lists');
      facade.productPrice.mockResolvedValueOnce({
        unitPriceNet: '1700.0000',
        priceListName: 'Gros',
      });
      await open(undefined);
      await pick('invoice-customer', 'CLI-2 · Méditerranée');
      await pick('line-0-product', 'ART-1 · Conception');
      let answerTen!: (price: unknown) => void;
      facade.productPrice
        .mockReturnValueOnce(new Promise((resolve) => (answerTen = resolve)))
        .mockResolvedValueOnce({ unitPriceNet: '1200.0000', priceListName: 'Gros' });

      type('line-0-quantity', '10');
      (q('line-0-quantity') as HTMLInputElement).dispatchEvent(new Event('blur'));
      type('line-0-quantity', '100');
      (q('line-0-quantity') as HTMLInputElement).dispatchEvent(new Event('blur'));
      await settle();
      answerTen({ unitPriceNet: '1500.0000', priceListName: 'Gros' });
      await settle();

      expect((q('line-0-price') as HTMLInputElement).value).toBe('1200,000');
    });

    it('leaves a price the person typed alone', async () => {
      modulesOn.add('price_lists');
      facade.productPrice.mockResolvedValueOnce({
        unitPriceNet: '1700.0000',
        priceListName: 'Gros',
      });
      await open(undefined);
      await pick('invoice-customer', 'CLI-2 · Méditerranée');
      await pick('line-0-product', 'ART-1 · Conception');
      type('line-0-price', '1234');
      facade.productPrice.mockResolvedValue({ unitPriceNet: '1500.0000', priceListName: 'Gros' });

      type('line-0-quantity', '10');
      (q('line-0-quantity') as HTMLInputElement).dispatchEvent(new Event('blur'));
      await settle();

      expect((q('line-0-price') as HTMLInputElement).value).toBe('1234');
    });

    it('keeps the shelf price, and asks nothing, where the company has no price lists', async () => {
      await open(undefined);
      await pick('line-0-product', 'ART-1 · Conception');

      expect(facade.productPrice).not.toHaveBeenCalled();
      expect((q('line-0-price') as HTMLInputElement).value).toBe('1800,000');
    });

    it('keeps the shelf price where the list gives none', async () => {
      modulesOn.add('price_lists');
      await open(undefined);
      await pick('line-0-product', 'ART-1 · Conception');

      expect((q('line-0-price') as HTMLInputElement).value).toBe('1800,000');
      expect(q('line-0-price-list')).toBeNull();
    });
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: a line's figures show the French decimal comma and take a comma or a point.
  it('shows a line’s figures with a decimal comma, and sends a typed comma as a point', async () => {
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    await pick('invoice-customer', 'CLI-2 · Méditerranée');
    await pick('line-0-product', 'ART-1 · Conception');
    expect((q('line-0-price') as HTMLInputElement).value).toBe('1800,000');

    type('line-0-quantity', '2,000');
    type('line-0-price', '1800,5');
    type('line-0-discount', '7,5');
    q('document-action-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        lines: [
          expect.objectContaining({
            quantity: '2.000',
            unitPriceNet: '1800.5',
            discountRate: '7.5',
          }),
        ],
      }),
    );
  });

  it('gives lines whose discount nobody typed the discount of the customer chosen', async () => {
    invoice.set(draft);
    await open('i1');
    q('line-add')!.click();
    await settle();
    type('line-1-discount', '12');
    await pick('invoice-customer', 'CLI-2 · Méditerranée');
    expect((q('line-0-discount') as HTMLInputElement).value).toBe('5');
    expect((q('line-1-discount') as HTMLInputElement).value).toBe('12');
  });

  it('keeps the document taxes a draft names, and resets them when its customer changes', async () => {
    invoice.set({ ...draft, documentTaxComponentIds: [] });
    await open('i1');
    expect(chosen('document-taxes')).not.toContain('Timbre fiscal');

    await pick('invoice-customer', 'CLI-2 · Méditerranée');
    expect(chosen('document-taxes')).toEqual(
      expect.arrayContaining(['Timbre fiscal', 'Retenue à la source 1 %']),
    );
  });

  /** The picked row carries the regime, and nothing else does: no list is held to look one up in. */
  it('stops offering a line tax and a document charge once a customer whose regime refuses them is named', async () => {
    await open(undefined);
    await pick('invoice-customer', 'CLI-2 · Méditerranée');
    expect(await offered('line-0-taxes')).toContain('line-0-tax-TVA19');
    expect(await offered('document-taxes')).toContain('document-tax-TIMBRE');

    await pick('invoice-customer', 'CLI-3 · Ambassade');

    expect(await offered('line-0-taxes')).not.toContain('line-0-tax-TVA19');
    expect(await offered('document-taxes')).not.toContain('document-tax-TIMBRE');
  });

  it('shows a draft’s totals as the API works them out, withholding and net payable included', async () => {
    invoice.set(draft);
    await open('i1');
    const totals = text('invoice-totals');
    // Each tax by the name the company gave it, its code left to the tax settings (audit 2026-10-06, V-31).
    expect(totals).toContain('TVA 19 % · base 1 800,000');
    expect(totals).toContain('342,000');
    expect(totals).toContain('Timbre fiscal');
    expect(totals).toContain('2 143,000');
    expect(totals).toContain('Retenue à la source 1 %');
    expect(totals).not.toMatch(/TVA19|TIMBRE|RS1/);
    expect(totals).toMatch(/[−-]21,430/);
    expect(text('invoice-net-due')).toContain('2 121,570');
    expect(text('line-0-net')).toContain('1 800,000');
  });

  it('names a tax by its code and rate when the company no longer has it at that rate', async () => {
    invoice.set({
      ...draft,
      taxes: [{ code: 'TVA19', rate: '18.000', base: '1800.000', amount: '324.000' }],
    });
    await open('i1');

    expect(text('invoice-totals')).toContain('TVA19 18 % · base 1 800,000');
  });

  it('shows an issued invoice’s net payable after its withholding, so the figures add up', async () => {
    invoice.set(issued);
    await open('i1');

    // 2 143,000 less the 21,430 withheld: 1 000,000 paid and 1 121,570 left make exactly that.
    expect(text('invoice-net-due')).toContain('2 121,570');
    expect(text('invoice-due')).toContain('1 000,000');
    expect(text('invoice-due')).toContain('sur 2 121,570');
  });

  // Audit 2026-10-06, C-12: the API counts « net à payer »; the screen shows its figure and never redoes the sum.
  it('shows the net payable the API counted, not a sum of its own', async () => {
    invoice.set({ ...issued, netToPay: '2121.000' });
    await open('i1');

    expect(text('invoice-net-due')).toContain('2 121,000');
    expect(text('invoice-due')).toContain('sur 2 121,000');
  });

  it('issues exactly what is on screen, once the consequence is confirmed', async () => {
    invoice.set(draft);
    await open('i1');
    type('line-0-quantity', '3');
    q('document-action-issue')!.click();
    await settle();
    // Issuing numbers a fiscal document for good, so neither a click nor the bare key E does it unasked (EFF-01).
    expect(facade.reviseAndIssue).not.toHaveBeenCalled();
    expect(over('confirm-run')).not.toBeNull();
    over('confirm-run')!.click();
    await settle();
    expect(facade.reviseAndIssue).toHaveBeenCalledWith(
      'c1',
      'i1',
      expect.objectContaining({
        documentTaxComponentIds: ['s1'],
        lines: [expect.objectContaining({ quantity: '3' })],
      }),
    );
    // What was confirmed as corrigeable is said so once done (docs/SPEC.md § 7, 2026-09-25 22:17).
    await vi.waitFor(() => expect(effectToasts()).toEqual(['invoices.issued:corrigeable']));
  });

  it('asks where the money already paid goes when a credit note is refused for it, and issues with the answer', async () => {
    facade.reviseAndIssue.mockImplementationOnce(() => {
      error.set('excess_to');
      return Promise.resolve(null);
    });
    facade.reviseAndIssue.mockResolvedValue(issued);
    invoice.set(draft);
    await open('i1');
    q('document-action-issue')!.click();
    await settle();
    over('confirm-run')!.click();
    await settle();

    await vi.waitFor(() => expect(over('credit-excess-title')).not.toBeNull());
    const refund = over('credit-excess-refund')!.querySelector('input')!;
    refund.checked = true;
    refund.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    over('credit-excess-issue')!.click();
    await settle();

    expect(facade.reviseAndIssue).toHaveBeenCalledTimes(2);
    expect(facade.reviseAndIssue).toHaveBeenLastCalledWith('c1', 'i1', expect.anything(), 'refund');
    error.set(null);
  });

  it('leaves the credit note a draft when no destination is chosen', async () => {
    facade.reviseAndIssue.mockImplementationOnce(() => {
      error.set('excess_to');
      return Promise.resolve(null);
    });
    invoice.set(draft);
    await open('i1');
    q('document-action-issue')!.click();
    await settle();
    over('confirm-run')!.click();
    await settle();
    await vi.waitFor(() => expect(over('credit-excess-title')).not.toBeNull());
    over('credit-excess-keep')!.click();
    await settle();

    expect(facade.reviseAndIssue).toHaveBeenCalledTimes(1);
    error.set(null);
  });

  // docs/SPEC.md § 7, 2026-09-26, row 139: what was just done offers its next step, to whoever may take it.
  it('offers to record a payment once an invoice is issued with money owed, and opens it', async () => {
    facade.reviseAndIssue.mockImplementation(() => {
      invoice.set(issued);
      return Promise.resolve(issued);
    });
    invoice.set(draft);
    await open('i1');
    q('document-action-issue')!.click();
    await settle();
    over('confirm-run')!.click();
    await settle();
    await vi.waitFor(() => expect(offeredNext()?.key).toBe('invoices.suggest.record_payment'));

    offeredNext()!.run();
    await settle();
    await vi.waitFor(() => expect(over('payment-dialog-title')).not.toBeNull());
  });

  // docs/SPEC.md § 7, 2026-09-26: the sheet's « Encaisser » opens the record with its payment asked, once.
  it('asks for the payment its address names once the document is read, then forgets the address', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    invoice.set(issued);
    fixture = TestBed.createComponent(InvoicePage);
    fixture.componentRef.setInput('invoiceId', 'i1');
    fixture.componentRef.setInput('pay', '1');
    await settle();
    await vi.waitFor(() => expect(over('payment-dialog-title')).not.toBeNull());
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { pay: null } }),
    );
  });

  it('asks for no payment its address names where nothing is owed or the member may not record one', async () => {
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    granted.delete('payment.write');
    invoice.set(issued);
    fixture = TestBed.createComponent(InvoicePage);
    fixture.componentRef.setInput('invoiceId', 'i1');
    fixture.componentRef.setInput('pay', '1');
    await settle();
    await Promise.resolve();
    fixture.detectChanges();
    expect(over('payment-dialog-title')).toBeNull();
  });

  it('offers no payment to a member who may not record one', async () => {
    granted.delete('payment.write');
    invoice.set(draft);
    await open('i1');
    q('document-action-issue')!.click();
    await settle();
    over('confirm-run')!.click();
    await settle();
    await vi.waitFor(() => expect(effectToasts()).toEqual(['invoices.issued:corrigeable']));
    expect(offeredNext()).toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-24 22:51: E runs the state's next step, Émettre on a draft and Encaisser once issued.
  it('gives E the next step of its state: issuing a draft, then recording a payment', async () => {
    invoice.set(draft);
    await open('i1');
    const screen = TestBed.inject(ScreenActions);
    expect(screen.next()?.id).toBe('issue');
    // E is the shell's now, not the screen's: issuing keeps no key of its own.
    expect(screen.actions().find((each) => each.id === 'issue')?.shortcut).toBeUndefined();

    invoice.set(issued);
    await settle();
    expect(screen.next()?.id).toBe('record-payment');
    expect(screen.forKey('p')?.id).toBe('record-payment');
    // Named as the sheet over the list names it (audit 2026-10-06 V-4 c): one action, one word.
    expect(screen.next()?.label).toBe('invoices.payments.collect');
  });

  it('titles a deposit as one, and leads back to the quote it was drawn from for whoever reads quotes', async () => {
    invoice.set({ ...draft, deposit: true, quoteId: 'q1' });
    await open('i1');

    expect(q('invoice-title')?.textContent).toContain('invoices.deposit_draft_title');
    expect(q('invoice-deposit')?.textContent).toContain('invoices.types.deposit');
    expect(q('invoice-quote')).toBeNull();

    granted.add('quote.read');
    modulesOn.add('quotes');
    fixture.destroy();
    await open('i1');
    expect(q('invoice-quote')?.getAttribute('href')).toBe('/quotes/q1');
  });

  it('shows a line giving a deposit back as written, never edited, and sends it back as it came', async () => {
    invoice.set({
      ...draft,
      quoteId: 'q1',
      lines: [
        draft.lines[0]!,
        {
          ...draft.lines[0]!,
          productId: null,
          productReference: null,
          productName: null,
          productTracking: null,
          description: 'Acompte déjà facturé : facture F-2026-0003 du 07/10/2026',
          unitPriceNet: '500.0000',
          deductsInvoiceId: 'd1',
          net: '-500.000',
        },
      ],
    });
    await open('i1');

    expect(q('line-1-gives-back')?.textContent).toContain('invoices.lines.gives_back');
    expect(q('line-1-description')?.textContent).toContain('F-2026-0003');
    expect(q('line-1')?.querySelector('input')).toBeNull();
    expect(q('line-0')?.querySelector('input')).not.toBeNull();

    q('document-action-save')!.click();
    await settle();
    await vi.waitFor(() => expect(facade.revise).toHaveBeenCalled());
    const sent = facade.revise.mock.calls.at(-1)![2] as {
      lines: { deductsInvoiceId: string | null }[];
    };
    expect(sent.lines.map((line) => line.deductsInvoiceId)).toEqual([null, 'd1']);
  });

  // docs/SPEC.md § 7, audit 2026-10-06 A-16: a line taken from a delivery note keeps its product and its cap.
  it('locks the product of a line taken from a delivery note, shows what the note leaves and refuses more', async () => {
    invoice.set({
      ...draft,
      lines: [
        {
          ...draft.lines[0],
          quantity: '6.000',
          sourceDeliveryNoteLineId: 'dl1',
          sourceLeft: '6.000',
        },
      ],
    });
    await open('i1');

    expect((q('line-0-product') as HTMLInputElement).disabled).toBe(true);
    expect(text('line-0-source-left')).toContain('invoices.lines.source_left');
    expect(q('line-0-quantity-error')).toBeNull();

    type('line-0-quantity', '7');
    (q('line-0-quantity') as HTMLInputElement).dispatchEvent(new Event('blur'));
    await settle();
    expect(text('line-0-quantity-error')).toContain('invoices.lines.errors.quantity_above_source');
    expect(q('line-0-source-left')).toBeNull();

    type('line-0-quantity', '6');
    await settle();
    expect(q('line-0-quantity-error')).toBeNull();
  });

  it('takes the header and lines another person saved into a quiet draft', async () => {
    invoice.set(draft);
    await open('i1');
    const before = (q('line-0-quantity') as HTMLInputElement).value;
    facade.loadInvoice.mockImplementation(async () => {
      invoice.set({
        ...draft,
        customerReference: 'BC-7',
        lines: [{ ...draft.lines[0], quantity: '2.000' }],
      });
    });

    await announceSaved('invoice', 'i1');
    await settle();

    expect(facade.loadInvoice).toHaveBeenLastCalledWith('c1', 'i1');
    expect((q('field-customerReference') as HTMLInputElement).value).toBe('BC-7');
    expect((q('line-0-quantity') as HTMLInputElement).value).not.toBe(before);
    expect((q('line-0-quantity') as HTMLInputElement).value).toMatch(/^2/);
    expect(q('record-changed')).toBeNull();
  });

  it('keeps lines being edited when another person saved other lines, and offers theirs', async () => {
    invoice.set(draft);
    await open('i1');
    type('line-0-quantity', '3');
    facade.loadInvoice.mockImplementation(async () => {
      invoice.set({
        ...draft,
        customerReference: 'BC-7',
        lines: [{ ...draft.lines[0], quantity: '2.000' }],
      });
    });

    await announceSaved('invoice', 'i1');
    await settle();

    expect((q('line-0-quantity') as HTMLInputElement).value).toBe('3');
    expect((q('field-customerReference') as HTMLInputElement).value).toBe('BC-7');
    expect(q('record-changed')).not.toBeNull();
    q('field-take-theirs-lines')!.click();
    await settle();
    expect((q('line-0-quantity') as HTMLInputElement).value).toMatch(/^2/);
    expect(q('field-conflict-lines')).toBeNull();
  });

  it('counts what is typed on a document, so leaving it asks first (RCH-01)', async () => {
    const unsaved = TestBed.inject(UnsavedChanges);
    invoice.set(draft);
    await open('i1');
    // A document just opened holds what was saved: leaving it is not a question.
    expect(unsaved.count()).toBe(0);
    type('line-0-quantity', '3');
    await settle();
    expect(unsaved.count()).toBeGreaterThan(0);
    type('line-0-quantity', '1');
    await settle();
    expect(unsaved.count()).toBe(0);
    type('field-customerReference', 'BC-9');
    await settle();
    expect(unsaved.count()).toBeGreaterThan(0);
  });

  it('counts a new document once a customer is picked or a line is typed', async () => {
    const unsaved = TestBed.inject(UnsavedChanges);
    await open(undefined);
    expect(unsaved.count()).toBe(0);
    await pick('invoice-customer', 'CLI-1 · Carthage');
    expect(unsaved.count()).toBeGreaterThan(0);
  });

  it('cancels a draft only once confirmed', async () => {
    invoice.set(draft);
    await open('i1');
    // Cancelling is destructive, so it is behind "⋮" and asks before it runs.
    expect(q('document-action-cancel')).toBeNull();
    q('document-more')!.click();
    await settle();
    over('document-menu-cancel')!.click();
    await settle();
    expect(facade.cancel).not.toHaveBeenCalled();
    over('confirm-run')!.click();
    await settle();
    expect(facade.cancel).toHaveBeenCalledWith('c1', 'i1');
    await vi.waitFor(() => expect(effectToasts()).toEqual(['invoices.cancelled:definitif']));
  });

  it('reads a locked document rather than showing a form nobody may fill in', async () => {
    // Design review finding 3: an issued invoice was a form with every control disabled, which reads as "you may
    // not change this" where the truth is "this no longer changes" — with an empty box per unfilled field.
    invoice.set(issued);
    await open('i1');

    expect(q('invoice-view')).not.toBeNull();
    expect(q('invoice-form')).toBeNull();
    expect(q('invoice-customer')).toBeNull();
    expect(q('field-customerReference')).toBeNull();

    // A draft is still a form: it is what a person came to fill in.
    invoice.set(draft);
    await open('i1');
    expect(q('invoice-form')).not.toBeNull();
    expect(q('invoice-view')).toBeNull();
  });

  it('names the customer of a locked document as the document recorded it', async () => {
    // The customer is a picker beside the form, not a field of its descriptor, so the read view left it out and an
    // issued invoice never said whom it was for (visual audit 2026-10-06, V-25).
    invoice.set(issued);
    await open('i1');

    expect(text('invoice-view-customer-label')).toContain('invoices.fields.customerId');
    expect(text('invoice-view-customer')).toContain('Carthage SA');

    invoice.set(draft);
    await open('i1');
    expect(q('invoice-view-customer')).toBeNull();
  });

  it('shows an issued invoice fixed, with what is left to collect and its payments', async () => {
    invoice.set(issued);
    await open('i1');

    expect(text('invoice-title')).toContain('FAC-2026-00045');
    expect(text('invoice-fixed')).toContain('avoir');
    expect(text('invoice-amount-due')).toContain('1 121,570');
    expect(text('payment-y1')).toContain('VIR 882104');
    expect(text('payment-y1')).toContain('1 000,000');
    // Its lines are read, as the PDF lays them out, never fields drawn disabled.
    expect(q('invoice-lines-issued')?.querySelector('table')).not.toBeNull();
    expect(q('invoice-lines')?.querySelector('input, app-select, app-pick-field')).toBeNull();
    expect(q('line-0-quantity')?.tagName).toBe('TD');
    expect(q('line-add')).toBeNull();
    // Its document taxes are read in the totals, which list each one: no field drawn disabled for them.
    expect(q('invoice-document-taxes')).toBeNull();
    expect(q('document-action-save')).toBeNull();
    expect(q('document-action-issue')).toBeNull();
    expect(q('invoice-cancel')).toBeNull();
    expect(q('document-action-pdf')?.getAttribute('href')).toBe(
      '/api/companies/c1/invoices/i1/pdf',
    );
  });

  it('offers a duplicate and an up-to-date copy of an issued invoice, and none of a draft', async () => {
    invoice.set(issued);
    await open('i1');
    q('document-more')!.click();
    await settle();
    expect(over('document-menu-print-duplicate')?.getAttribute('href')).toBe(
      '/api/companies/c1/invoices/i1/pdf/duplicate',
    );
    expect(over('document-menu-print-current')?.getAttribute('href')).toBe(
      '/api/companies/c1/invoices/i1/pdf/current',
    );

    invoice.set(draft);
    await open('i1');
    q('document-more')!.click();
    await settle();
    expect(over('document-menu-print-duplicate')).toBeNull();
    expect(over('document-menu-print-current')).toBeNull();
  });

  it('records a payment on the company’s today, for what is still due unless changed', async () => {
    invoice.set(issued);
    await open('i1');
    q('document-action-record-payment')!.click();
    await settle();
    expect((over('field-amount') as HTMLInputElement).value).toBe('1121,570');
    expect((over('field-date') as HTMLInputElement).value).toBe(
      formatDay(todayIn('Africa/Tunis'), 'fr-TN'),
    );

    typeIn(over('field-amount') as HTMLInputElement, '500,5');
    typeIn(over('field-reference') as HTMLInputElement, 'CHQ 12');
    over('invoice-payment-record')!.click();
    await settle();
    expect(facade.recordPayment).toHaveBeenCalledWith('c1', 'i1', {
      date: todayIn('Africa/Tunis'),
      amount: '500.5',
      method: 'transfer',
      reference: 'CHQ 12',
      notes: null,
    });
    // The dialog's answer adds an await between the click and the record, so the toast lands a tick later.
    await vi.waitFor(() => expect(successToasts()).toContain('invoices.payments.recorded'));
  });

  it('offers to apply the customer’s credit only when they have some and the invoice is due', async () => {
    invoice.set(issued);
    await open('i1');
    q('document-more')!.click();
    await settle();
    expect(over('document-menu-apply-credit')).toBeNull();
  });

  it('applies the credit the customer has to an invoice that is due, and says so', async () => {
    facade.customerCredit.mockResolvedValue('300.000');
    invoice.set(issued);
    await open('i1');
    expect(facade.customerCredit).toHaveBeenCalledWith('c1', 'k1');

    q('document-more')!.click();
    await settle();
    over('document-menu-apply-credit')!.click();
    await settle();

    expect(facade.applyCredit).toHaveBeenCalledWith('c1', 'i1');
    await vi.waitFor(() => expect(successToasts()).toContain('invoices.payments.credit_applied'));
  });

  it('does not offer the credit to someone who may not record a payment', async () => {
    facade.customerCredit.mockResolvedValue('300.000');
    granted.delete('payment.write');
    invoice.set(issued);
    await open('i1');
    expect(facade.customerCredit).not.toHaveBeenCalled();
    q('document-more')?.click();
    await settle();
    expect(over('document-menu-apply-credit')).toBeNull();
  });

  it('keeps what was paid beyond an invoice paid in full to the customer’s credit, never on one still due', async () => {
    invoice.set(issued);
    await open('i1');
    q('document-more')!.click();
    await settle();
    expect(over('document-menu-record-overpayment')).toBeNull();

    invoice.set({ ...issued, status: 'paid', amountDue: '0.000' });
    await open('i1');
    q('document-more')!.click();
    await settle();
    over('document-menu-record-overpayment')!.click();
    await settle();
    expect(over('overpayment-title')).not.toBeNull();
    typeIn(over('field-amount') as HTMLInputElement, '50');
    typeIn(over('field-reference') as HTMLInputElement, 'VIR-9');
    over('overpayment-record')!.click();
    await settle();

    expect(facade.overpay).toHaveBeenCalledWith('c1', 'i1', {
      date: todayIn('Africa/Tunis'),
      amount: '50',
      reference: 'VIR-9',
      notes: null,
    });
    await vi.waitFor(() => expect(successToasts()).toContain('invoices.overpayment.recorded'));
  });

  it('does not offer a trop-perçu to someone who may not record a payment', async () => {
    granted.delete('payment.write');
    invoice.set({ ...issued, status: 'paid', amountDue: '0.000' });
    await open('i1');
    q('document-more')?.click();
    await settle();
    expect(over('document-menu-record-overpayment')).toBeNull();
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
    expect(q('document-action-record-payment')).toBeNull();
    expect(over('document-menu-credit-note')).toBeNull();

    granted.delete('payment.write');
    invoice.set(issued);
    await open('i1');
    expect(q('document-action-record-payment')).toBeNull();
    expect(q('payment-y1-delete')).toBeNull();
  });

  it('shows an issued invoice past its due day as overdue', async () => {
    invoice.set({ ...issued, status: 'issued', dueDate: '2000-01-01' });
    await open('i1');
    expect(text('invoice-status')).toContain('En retard');
  });

  it('keeps a credit note and an invoice of delivery notes with their customer, and says why', async () => {
    invoice.set({ ...draft, type: 'credit_note', correctsInvoiceId: 'i0' });
    await open('i1');
    expect((q('invoice-customer') as HTMLInputElement).disabled).toBe(true);
    expect(document.getElementById('invoice-customer-hint')?.textContent).toContain(
      'invoices.form.customer_locked_credit_note',
    );

    invoice.set({ ...draft, lines: [{ ...draft.lines[0]!, sourceDeliveryNoteLineId: 'dl1' }] });
    await open('i1');
    expect((q('invoice-customer') as HTMLInputElement).disabled).toBe(true);
    expect(document.getElementById('invoice-customer-hint')?.textContent).toContain(
      'invoices.form.customer_locked_delivery_notes',
    );

    invoice.set(draft);
    await open('i1');
    expect((q('invoice-customer') as HTMLInputElement).disabled).toBe(false);
  });

  it('says under the customer that the API refused them, not as a refusal of the whole document', async () => {
    invoice.set(draft);
    await open('i1');
    error.set('customer_unavailable');
    await settle();

    expect(text('invoice-customer-refused')).toContain('invoices.errors.customer_unavailable');
    expect(q('invoice-error')).toBeNull();
    error.set(null);
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
    q('document-more')!.click();
    await settle();
    over('document-menu-credit-note')!.click();
    await settle();
    // The reason is asked first (docs/SPEC.md § 7, 2026-09-24 22:51): nothing is drafted without one.
    over('credit-note-create')!.click();
    await settle();
    expect(facade.creditNote).not.toHaveBeenCalled();
    typeIn(over('field-reason') as HTMLInputElement, '  Retour de marchandise ');
    over('credit-note-create')!.click();
    await settle();
    expect(facade.creditNote).toHaveBeenCalledWith('c1', 'i1', 'Retour de marchandise');
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/invoices', 'cn1']));
  });

  it('copies a document into a new draft, and goes to the copy', async () => {
    facade.duplicate.mockResolvedValue({ ...draft, id: 'i2' });
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    invoice.set(issued);
    await open('i1');

    q('document-action-duplicate')!.click();
    await settle();

    expect(facade.duplicate).toHaveBeenCalledWith('c1', 'i1');
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/invoices', 'i2']));
  });

  it('leaves a credit note to a manager: a clerk who issues invoices is offered neither its drafting nor its issuing', async () => {
    granted.delete('invoice.credit');
    invoice.set(issued);
    await open('i1');
    // The menu holds the copies for this clerk, and no credit note.
    q('document-more')!.click();
    await settle();
    expect(over('document-menu-credit-note')).toBeNull();
    expect(over('document-menu-print-duplicate')).not.toBeNull();

    invoice.set({
      ...draft,
      type: 'credit_note',
      correctsInvoiceId: 'i0',
      creditNoteReason: 'Retour',
    });
    await open('i1');
    expect(q('document-action-issue')).toBeNull();
    // Nor its revising or cancelling: the API answers either as it would a stranger.
    expect(q('document-action-save')).toBeNull();
    q('document-more')?.click();
    await settle();
    expect(over('document-menu-cancel')).toBeNull();

    granted.add('invoice.credit');
    await open('i1');
    expect(q('document-action-issue')).not.toBeNull();
    expect(q('document-action-save')).not.toBeNull();
    q('document-more')!.click();
    await settle();
    expect(over('document-menu-cancel')).not.toBeNull();
  });

  it('shows a credit note as one: its title, its invoice, no payments and no credit note of its own', async () => {
    invoice.set({
      ...draft,
      type: 'credit_note',
      correctsInvoiceId: 'i0',
      creditNoteReason: 'Retour de marchandise',
    });
    await open('i1');
    expect(text('invoice-title')).toContain('Avoir en brouillon');
    expect(q('invoice-corrects')?.getAttribute('href')).toBe('/invoices/i0');
    expect(text('invoice-credit-reason').trim()).toBe('Motif : Retour de marchandise');
    expect(q('document-action-issue')).not.toBeNull();

    invoice.set({ ...issued, type: 'credit_note', correctsInvoiceId: 'i0', status: 'issued' });
    await settle();
    expect(q('invoice-payments')).toBeNull();
    expect(q('invoice-due')).toBeNull();
    expect(over('document-menu-credit-note')).toBeNull();
  });

  it("asks of a credit note's goods, and of nothing else, whether they came back to stock", async () => {
    invoice.set({
      ...draft,
      type: 'credit_note',
      correctsInvoiceId: 'i0',
      creditNoteReason: 'Retour',
    });
    await open('i1');
    expect(q('line-0-returned')).not.toBeNull();

    (q('line-0-returned')?.querySelector('input') as HTMLInputElement).click();
    await settle();
    expect(
      (
        fixture.componentInstance as unknown as {
          lines: () => { getRawValue(): { returned: boolean }[] };
        }
      )
        .lines()
        .getRawValue()[0].returned,
    ).toBe(true);

    invoice.set(draft);
    await open('i1');
    expect(q('line-0-returned')).toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-26 10:08 and 18:17 (row 150, slice 5).

  /** What the « ⋮ » menu lists as coming, in order (audit 2026-10-06 V-3: no longer a group in the bar). */
  async function plannedInMenu(): Promise<string[]> {
    const more = q('document-more');
    if (more === null) return [];
    more.click();
    await settle();
    const listed = [
      ...document.body.querySelectorAll(
        '.cdk-overlay-container [data-testid^="document-planned-"]',
      ),
    ]
      .map((each) => each.getAttribute('data-testid') ?? '')
      .filter((id) => id !== 'document-planned-heading');
    (document.activeElement as HTMLElement | null)?.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, bubbles: true }),
    );
    await settle();
    return listed;
  }

  it('offers nothing planned while an invoice is new', async () => {
    await open(undefined);
    expect(await plannedInMenu()).toEqual([]);
  });

  it('shows what an invoice will offer once its planned modules ship', async () => {
    invoice.set(issued);
    await open('i1');
    expect(await plannedInMenu()).toEqual([
      'document-planned-mailing',
      'document-planned-whatsapp',
      'document-planned-recurring',
    ]);

    // A credit note is never made recurring.
    invoice.set({ ...issued, type: 'credit_note', correctsInvoiceId: 'i0' });
    await settle();
    expect(await plannedInMenu()).toEqual([
      'document-planned-mailing',
      'document-planned-whatsapp',
    ]);
  });

  it('shows a reader the invoice and its PDF without a way to change it', async () => {
    granted.clear();
    granted.add('invoice.read');
    invoice.set(draft);
    await open('i1');
    expect(q('invoice-read-only')).not.toBeNull();
    expect(q('document-action-save')).toBeNull();
    expect(q('document-action-issue')).toBeNull();
    expect(q('invoice-cancel')).toBeNull();
    expect(q('line-add')).toBeNull();
    expect(q('document-action-pdf')).not.toBeNull();
  });

  it('says why the API refused', async () => {
    error.set('invalid');
    invoice.set(draft);
    await open('i1');
    expect(text('invoice-error')).toContain('Refusé.');
  });

  describe('a scan on the screen', () => {
    const coffee = {
      productId: 'p1',
      reference: 'ART-1',
      name: 'Conception',
      isActive: true,
      code: '3017620422003',
      role: 'unit' as const,
      quantity: 1,
      lot: null,
      useBy: null,
      serial: null,
      unitPriceNet: '1.0000',
      unitPriceGross: '1.190',
      priceGross: '1.190',
    };

    const scanned = (code: string) => TestBed.inject(ScanBus).receive(code, 'wedge');
    const quantityOf = (line: number) =>
      (q(`line-${line}-quantity`) as HTMLInputElement | null)?.value ?? null;

    beforeEach(() => granted.add('product.read'));

    it('starts a new document with the product a scan card sent here, once', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      scans.named.mockResolvedValue(coffee);
      fixture = TestBed.createComponent(InvoicePage);
      fixture.componentRef.setInput('scan', '3017620422003');
      await settle();
      await vi.waitFor(() => expect(scans.named).toHaveBeenCalledWith('3017620422003'));
      await settle();

      expect((q('line-0-description') as HTMLInputElement).value).toBe('Conception');
      expect(q('line-1')).toBeNull();
      // The address forgets the scan, so a reload does not add it again.
      expect(navigate).toHaveBeenCalledWith(
        [],
        expect.objectContaining({ queryParams: { scan: null } }),
      );
      await settle();
      expect(scans.named).toHaveBeenCalledTimes(1);

      // Another code sent to the same page is played too.
      fixture.componentRef.setInput('scan', undefined);
      await settle();
      fixture.componentRef.setInput('scan', '5449000000996');
      await vi.waitFor(() => expect(scans.named).toHaveBeenLastCalledWith('5449000000996'));
    });

    it('counts a product already on a draft line like a till, and takes the count back on undo', async () => {
      invoice.set(draft);
      await open('i1');
      scans.named.mockResolvedValue(coffee);

      await scanned('3017620422003');
      const outcome = await scanned('3017620422003');
      await settle();

      expect(outcome).toMatchObject({ kind: 'done', key: 'scan.incremented' });
      expect(quantityOf(0)).toBe('3');
      expect(q('line-1')).toBeNull();

      TestBed.inject(ScanBus).undoLast();
      await settle();
      expect(quantityOf(0)).toBe('2');
    });

    it('adds a line for another product, filled from the product, a pack entering its pieces', async () => {
      invoice.set(draft);
      await open('i1');
      scans.named.mockResolvedValue({
        ...coffee,
        productId: 'p2',
        name: 'Papier',
        role: 'pack',
        quantity: 12,
      });
      facade.pickProducts.mockResolvedValueOnce([
        {
          id: 'p2',
          reference: 'PAP-1',
          name: 'Papier',
          unitId: 'u1',
          unitPriceNet: '4.5000',
          defaultTaxComponentIds: ['t1'],
          tracking: 'none',
        },
      ]);

      TestBed.inject(ScanBus).multiplier.set(2);
      const outcome = await scanned('13017620422000');
      await settle();

      expect(outcome).toMatchObject({
        kind: 'done',
        key: 'scan.added',
        params: { name: 'Papier' },
      });
      expect(facade.pickProducts).toHaveBeenCalledWith('c1', { ids: ['p2'] });
      expect(quantityOf(1)).toBe('24');
      expect((q('line-1-description') as HTMLInputElement).value).toBe('Papier');
      expect((q('line-1-price') as HTMLInputElement).value).toContain('4');
    });

    // docs/SPEC.md § 7, 2026-09-24 12:40 row 5: a line names the lot or serial sold.
    it('puts the serial a GS1 label names on the line, and starts a line for another', async () => {
      invoice.set({ ...draft, lines: [] });
      await open('i1');
      facade.pickProducts.mockResolvedValue([{ ...products[0], tracking: 'serial' }]);
      scans.named.mockResolvedValue({ ...coffee, lot: 'L-1', serial: 'SN-1' });

      await scanned('0103017620422003' + '21SN-1');
      await settle();
      expect((q('line-0-lot') as HTMLInputElement).value).toBe('SN-1');

      scans.named.mockResolvedValue({ ...coffee, lot: 'L-1', serial: 'SN-2' });
      await scanned('0103017620422003' + '21SN-2');
      await settle();
      expect(quantityOf(1)).toBe('1');
      expect((q('line-1-lot') as HTMLInputElement).value).toBe('SN-2');
    });

    it('refuses a serial already on a line instead of counting the same unit twice', async () => {
      invoice.set({ ...draft, lines: [] });
      await open('i1');
      facade.pickProducts.mockResolvedValue([{ ...products[0], tracking: 'serial' }]);
      scans.named.mockResolvedValue({ ...coffee, lot: null, serial: 'SN-1' });

      await scanned('0103017620422003' + '21SN-1');
      await settle();
      expect(await scanned('0103017620422003' + '21SN-1')).toMatchObject({
        kind: 'refused',
        key: 'scan.serial_present',
      });
      await settle();

      expect(quantityOf(0)).toBe('1');
      expect(q('line-1-lot')).toBeNull();
    });

    // docs/SPEC.md § 7, 2026-09-23 slice 6: the customer display.
    it('shows the customer display the line a scan went onto, priced taxes included, then the total once saved', async () => {
      invoice.set(draft);
      await open('i1');
      scans.named.mockResolvedValue(coffee);

      await scanned('3017620422003');
      await settle();

      expect(display.show).toHaveBeenLastCalledWith({
        name: 'Conception',
        quantity: '2',
        unitPrice: '1.190',
        unitPriceNet: '1.0000',
      });

      invoice.set({ ...draft, total: '2.380' });
      await settle();
      expect(display.total).toHaveBeenLastCalledWith('2.380');
    });

    it('empties the customer display when a scan is taken back, and when the sale leaves the screen', async () => {
      invoice.set(draft);
      await open('i1');
      scans.named.mockResolvedValue(coffee);
      await scanned('3017620422003');

      TestBed.inject(ScanBus).undoLast();
      expect(display.clear).toHaveBeenCalledTimes(1);

      fixture.destroy();
      expect(display.clear).toHaveBeenCalledTimes(2);
    });

    it('keeps the customer display through the first save, which opens the sale at its own address', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      await open(undefined);
      await pick('invoice-customer', 'CLI-2 · Méditerranée');
      scans.named.mockResolvedValue(coffee);
      await scanned('3017620422003');
      await settle();

      q('document-action-save')!.click();
      await vi.waitFor(() =>
        expect(navigate).toHaveBeenCalledWith(['/invoices', 'i9'], { replaceUrl: true }),
      );
      fixture.destroy();

      expect(display.clear).not.toHaveBeenCalled();
    });

    it('empties the customer display when the screen goes on to another document', async () => {
      invoice.set(draft);
      await open('i1');
      scans.named.mockResolvedValue(coffee);
      await scanned('3017620422003');
      await settle();
      expect(display.clear).not.toHaveBeenCalled();

      fixture.componentRef.setInput('invoiceId', 'i2');
      await settle();

      expect(display.clear).toHaveBeenCalledTimes(1);
    });

    it('offers to open the customer display on a draft', async () => {
      invoice.set(draft);
      await open('i1');
      const actions = TestBed.inject(ScreenActions).actions();
      const action = actions.find((each) => each.id === 'customer-display');
      expect(action?.shown).not.toBe(false);
      action?.run?.();
      expect(display.openWindow).toHaveBeenCalled();
    });

    it('refuses a product no longer sold, and leaves a code nobody holds to the card', async () => {
      invoice.set(draft);
      await open('i1');

      scans.named.mockResolvedValueOnce({ ...coffee, isActive: false });
      expect(await scanned('3017620422003')).toMatchObject({
        kind: 'refused',
        key: 'scan.retired',
      });
      scans.named.mockResolvedValueOnce(null);
      expect(await scanned('999999')).toEqual({ kind: 'unclaimed' });
      expect(quantityOf(0)).toBe('1');
    });

    it('leaves a scan to the card on an issued invoice, or for somebody who may not read the products', async () => {
      invoice.set(issued);
      await open('i1');
      scans.named.mockResolvedValue(coffee);
      expect(await scanned('3017620422003')).toEqual({ kind: 'unclaimed' });

      invoice.set(draft);
      granted.delete('product.read');
      await settle();
      expect(await scanned('3017620422003')).toEqual({ kind: 'unclaimed' });
      expect(scans.named).not.toHaveBeenCalled();
    });
  });

  // docs/SPEC.md § 7, the live line figures: worked out by the API's one calculator once typing rests, kept nowhere.
  describe('figures as typed', () => {
    const twice: PreviewBody = {
      lines: [
        {
          amount: '3600.000',
          discount: '0.000',
          net: '3600.000',
          documentDiscount: '0.000',
          taxes: [{ code: 'TVA19', base: '3600.000', amount: '684.000' }],
          total: '4284.000',
        },
      ],
      subtotalNet: '3600.000',
      documentDiscount: '0.000',
      totalNet: '3600.000',
      taxes: [{ code: 'TVA19', rate: '19.000', base: '3600.000', amount: '684.000' }],
      totalTax: '684.000',
      fixedTaxes: [{ code: 'TIMBRE', amount: '1.000' }],
      total: '4285.000',
      withholdings: [{ code: 'RS1', rate: '1.000', base: '4285.000', amount: '42.850' }],
      netToPay: '4242.150',
    };

    /** Typing rests (the delay is nought here), the API answers, the screen draws it. */
    async function rested(): Promise<void> {
      await settle();
      await new Promise((resolve) => setTimeout(resolve, 0));
      for (let i = 0; i < 5; i++) await Promise.resolve();
      await settle();
    }

    it('works the draft out as it is typed, and shows each line and the totals as they would be saved', async () => {
      invoice.set(draft);
      await open('i1');
      await rested();
      facade.preview.mockResolvedValue(twice);

      type('line-0-quantity', '2');
      await rested();

      expect(facade.preview).toHaveBeenLastCalledWith(
        'c1',
        'i1',
        expect.objectContaining({
          customerId: 'k1',
          documentTaxComponentIds: ['s1'],
          lines: [expect.objectContaining({ quantity: '2', unitPriceNet: '1800.000' })],
        }),
      );
      expect(text('line-0-net')).toContain('3 600,000');
      expect(text('line-0-total')).toContain('4 284,000');
      expect(text('invoice-total')).toContain('4 285,000');
      expect(text('invoice-net-due')).toContain('4 242,150');
      expect(text('invoice-totals-note')).toContain('document_figures.as_typed');
    });

    it('leaves a line not ready yet out, without figures, and works the others out', async () => {
      invoice.set(draft);
      await open('i1');
      facade.preview.mockResolvedValue(twice);
      q('line-add')!.click();
      await rested();

      const [, , asked] = facade.preview.mock.lastCall!;
      expect(asked.lines).toHaveLength(1);
      expect(q('line-0-toggle')).not.toBeNull();
      expect(q('line-1-toggle')).toBeNull();
    });

    it('shows the saved figures when the API refuses what is typed, never figures of something else', async () => {
      invoice.set(draft);
      await open('i1');
      facade.preview.mockResolvedValue(twice);
      type('line-0-quantity', '2');
      await rested();
      expect(text('invoice-total')).toContain('4 285,000');

      facade.preview.mockResolvedValue(null);
      type('line-0-quantity', '3');
      await rested();
      expect(q('line-0-toggle')).toBeNull();
      expect(text('invoice-total')).toContain('2 143,000');
      expect(text('invoice-totals-note')).toContain('invoices.totals.as_saved');
    });

    it('asks nothing of a document that no longer changes', async () => {
      invoice.set(issued);
      await open('i1');
      await rested();
      expect(facade.preview).not.toHaveBeenCalled();
    });

    it('keeps working the figures out over another document, whose form is a new one', async () => {
      invoice.set(draft);
      await open('i1');
      fixture.componentRef.setInput('invoiceId', 'i2');
      invoice.set({ ...draft, id: 'i2' });
      await rested();
      facade.preview.mockClear();

      type('line-0-quantity', '4');
      await rested();
      expect(facade.preview).toHaveBeenLastCalledWith(
        'c1',
        'i2',
        expect.objectContaining({ lines: [expect.objectContaining({ quantity: '4' })] }),
      );
    });
  });
});

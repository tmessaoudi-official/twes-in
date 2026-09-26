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
      productReference: 'ART-1',
      productName: 'Conception',
      productTracking: 'none',
      lotCode: null,
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
    deletePayment: vi.fn(),
    clearError: vi.fn(),
    pdfUrl: (companyId: string, id: string) => `/api/companies/${companyId}/invoices/${id}/pdf`,
  };
  const scans = { piecesPerScan: vi.fn(), named: vi.fn() };
  const display = { show: vi.fn(), total: vi.fn(), clear: vi.fn(), openWindow: vi.fn() };
  const granted = new Set<string>();
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
  const checked = (testId: string): boolean =>
    (q(testId)?.querySelector('input[type="checkbox"]') as HTMLInputElement | null)?.checked ??
    false;

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
    ['invoice.read', 'invoice.write', 'invoice.issue', 'payment.write'].forEach((each) =>
      granted.add(each),
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
    facade.deletePayment.mockReset().mockResolvedValue(true);
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

  it('drafts an invoice for a customer, with the document taxes it would be charged, then opens it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadInvoice).toHaveBeenCalledWith('c1', null);
    expect(q('document-action-issue')).toBeNull();

    await pick('invoice-customer', 'CLI-2 · Méditerranée');
    expect(checked('document-tax-TIMBRE')).toBe(true);
    expect(checked('document-tax-RS1')).toBe(true);
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
    await vi.waitFor(() => expect(checked('document-tax-TIMBRE')).toBe(true));
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
    expect(checked('document-tax-TIMBRE')).toBe(false);

    await pick('invoice-customer', 'CLI-2 · Méditerranée');
    expect(checked('document-tax-TIMBRE')).toBe(true);
    expect(checked('document-tax-RS1')).toBe(true);
  });

  /** The picked row carries the regime, and nothing else does: no list is held to look one up in. */
  it('stops offering a line tax and a document charge once a customer whose regime refuses them is named', async () => {
    await open(undefined);
    await pick('invoice-customer', 'CLI-2 · Méditerranée');
    expect(q('line-0-tax-TVA19')).not.toBeNull();
    expect(q('document-tax-TIMBRE')).not.toBeNull();

    await pick('invoice-customer', 'CLI-3 · Ambassade');

    expect(q('line-0-tax-TVA19')).toBeNull();
    expect(q('document-tax-TIMBRE')).toBeNull();
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

  it('shows an issued invoice fixed, with what is left to collect and its payments', async () => {
    invoice.set(issued);
    await open('i1');

    expect(text('invoice-title')).toContain('FAC-2026-00045');
    expect(text('invoice-fixed')).toContain('avoir');
    expect(text('invoice-amount-due')).toContain('1 121,570');
    expect(text('payment-y1')).toContain('VIR 882104');
    expect(text('payment-y1')).toContain('1 000,000');
    expect((q('line-0-quantity') as HTMLInputElement).disabled).toBe(true);
    expect(q('document-action-save')).toBeNull();
    expect(q('document-action-issue')).toBeNull();
    expect(q('invoice-cancel')).toBeNull();
    expect(q('document-action-pdf')?.getAttribute('href')).toBe(
      '/api/companies/c1/invoices/i1/pdf',
    );
  });

  it('records a payment on the company’s today, for what is still due unless changed', async () => {
    invoice.set(issued);
    await open('i1');
    q('document-action-record-payment')!.click();
    await settle();
    expect((over('field-amount') as HTMLInputElement).value).toBe('1121,570');
    expect((over('field-date') as HTMLInputElement).value).toBe(todayIn('Africa/Tunis'));

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

  // docs/SPEC.md § 7, 2026-09-26 10:08 and 18:17 (row 150, slice 5).
  it('shows what an invoice will offer once its planned modules ship, and nothing of it while one is drafted', async () => {
    await open(undefined);
    expect(q('planned-actions')).toBeNull();
    fixture.destroy();

    invoice.set(issued);
    await open('i1');
    const drawn = [...fixture.nativeElement.querySelectorAll('[data-testid^="planned-action-"]')];
    expect(drawn.map((each: Element) => each.getAttribute('data-testid'))).toEqual([
      'planned-action-mailing',
      'planned-action-whatsapp',
      'planned-action-recurring',
    ]);
    // Before the working ones, so the filled next step stays last, where the hand ends up.
    const bar = q('document-actions');
    expect(bar?.firstElementChild?.contains(q('planned-actions'))).toBe(true);

    // A credit note is never made recurring.
    invoice.set({ ...issued, type: 'credit_note', correctsInvoiceId: 'i0' });
    await settle();
    expect(q('planned-action-mailing')).not.toBeNull();
    expect(q('planned-action-recurring')).toBeNull();
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
});

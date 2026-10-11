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
import type { CustomerOption, InvoiceOptions, ProductOption } from '../invoices/invoices-types';
import { ProductScans } from '../products/product-scans';
import { InventoryFacade } from '../inventory/inventory-facade';
import type { PickAsked } from '../shared/form/pick-api';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import {
  effectToasts,
  offeredNext,
  provideQuietFeedback,
  RecordedFeedback,
  successToasts,
} from '../shared/testing/feedback';
import { Feedback } from '../shared/feedback/feedback';
import { QuotePage } from './quote-page';
import { QuotesFacade } from './quotes-facade';
import { PREVIEW_DELAY } from '../shared/documents/document-figures';
import type { QuoteAttachment, QuoteRow, QuotesError } from './quotes-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      quotes: {
        statuses: { draft: 'Brouillon', sent: 'Envoyé', expired: 'Expiré', accepted: 'Accepté' },
        fixed: { sent: 'Il attend la réponse du client.' },
      },
    });
  }
}

const options: InvoiceOptions = {
  currency: 'TND',
  currencyScale: 3,
  operationCategory: false,
  establishments: [{ id: 'e1', code: '000', name: 'Siège', isDefault: true }],
  units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
  taxes: [
    {
      id: 't1',
      code: 'TVA19',
      name: 'TVA 19 %',
      kind: 'percentage_line',
      family: 'vat',
      rate: '19.000',
      amount: null,
      threshold: null,
      isDefault: true,
    },
  ],
};
const customers: CustomerOption[] = [
  {
    id: 'k1',
    number: 'CLI-1',
    name: 'Carthage',
    excludedFamilies: [],
    defaultDiscountRate: null,
    defaultTaxComponentIds: [],
  },
];
const products: ProductOption[] = [
  {
    id: 'p1',
    reference: 'ART-1',
    name: 'Perceuse',
    unitId: 'u1',
    unitPriceNet: '250.0000',
    defaultTaxComponentIds: ['t1'],
    tracking: 'lot',
    mainPhotoId: null,
  },
];

const draft: QuoteRow = {
  id: 'q1',
  number: null,
  status: 'draft',
  expired: false,
  customerId: 'k1',
  recordedCustomerName: null,
  customerName: 'Carthage',
  establishmentId: 'e1',
  issueDate: null,
  validUntil: null,
  answeredOn: null,
  refusalReason: null,
  invoiceId: null,
  deposits: [],
  attachmentCount: 0,
  customerReference: null,
  notesPrinted: null,
  notesInternal: null,
  discountAmount: null,
  lines: [
    {
      productId: 'p1',
      description: 'Perceuse',
      quantity: '2.000',
      unitId: 'u1',
      unitPriceNet: '250.0000',
      discountRate: null,
      discountAmount: null,
      taxComponentIds: ['t1'],
      sourceDeliveryNoteLineId: null,
      sourceLeft: null,
      productReference: 'ART-1',
      productName: 'Perceuse',
      productTracking: null,
      lotCode: null,
      returned: false,
      deductsInvoiceId: null,
      section: null,
      net: '500.000',
    },
  ],
  subtotalNet: '500.000',
  documentDiscount: '0.000',
  savings: '0.000',
  taxes: [{ code: 'TVA19', rate: '19.000', base: '500.000', amount: '95.000' }],
  totalTax: '95.000',
  total: '595.000',
};
const sent: QuoteRow = {
  ...draft,
  status: 'sent',
  number: 'DEV-2026-10-00001',
  issueDate: '2026-10-01',
  validUntil: '2026-10-31',
  recordedCustomerName: 'Carthage Conseil',
};
const accepted: QuoteRow = { ...sent, status: 'accepted', answeredOn: '2026-10-05' };

describe('QuotePage', () => {
  const error = signal<QuotesError | null>(null);
  const quote = signal<QuoteRow | null>(null);
  const attachments = signal<readonly QuoteAttachment[]>([]);
  const facade = {
    options: signal<InvoiceOptions | null>(options).asReadonly(),
    quote: quote.asReadonly(),
    attachments: attachments.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadQuote: vi.fn(),
    pickCustomers: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? customers.filter((each) => asked.ids.includes(each.id)) : customers,
    ),
    pickProducts: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? products.filter((each) => asked.ids.includes(each.id)) : products,
    ),
    productPrice: vi.fn(async () => null),
    create: vi.fn(),
    revise: vi.fn(),
    reviseAndSend: vi.fn(),
    accept: vi.fn(),
    refuse: vi.fn(),
    cancel: vi.fn(),
    invoice: vi.fn(),
    deposit: vi.fn(),
    attach: vi.fn(),
    detach: vi.fn(),
    restoreAttachment: vi.fn(),
    preview: vi.fn(),
    clearError: vi.fn(),
    attachmentUrl: (c: string, id: string, a: string) =>
      `/api/companies/${c}/quotes/${id}/attachments/${a}/content`,
    pdfUrl: (c: string, id: string) => `/api/companies/${c}/quotes/${id}/pdf`,
  };
  const scans = { piecesPerScan: vi.fn(), named: vi.fn() };
  const inventory = { onHand: vi.fn() };
  const granted = new Set<string>();
  const modules = new Set<string>();
  const auth = {
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme' },
      plannedModules: [
        { key: 'mailing', planned: 'v1' },
        { key: 'whatsapp', planned: 'v1' },
      ],
    }),
    hasPermission: (permission: string) => granted.has(permission),
    hasModule: (module: string) => modules.has(module),
  };
  let fixture: ComponentFixture<QuotePage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const over = (testId: string): HTMLElement | null =>
    document.body.querySelector(`.cdk-overlay-container [data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function typeIn(input: HTMLInputElement | HTMLTextAreaElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  async function open(quoteId: string | undefined, customerId?: string): Promise<void> {
    fixture = TestBed.createComponent(QuotePage);
    if (quoteId !== undefined) fixture.componentRef.setInput('quoteId', quoteId);
    if (customerId !== undefined) fixture.componentRef.setInput('customerId', customerId);
    await settle();
    // The customer a saved quote names is resolved by id, one turn after the quote itself.
    await Promise.resolve();
    await Promise.resolve();
    fixture.detectChanges();
  }

  beforeEach(() => {
    error.set(null);
    quote.set(null);
    attachments.set([]);
    granted.clear();
    ['quote.read', 'quote.write', 'invoice.read', 'invoice.write', 'product.read'].forEach((each) =>
      granted.add(each),
    );
    modules.clear();
    ['quotes', 'invoices', 'products'].forEach((each) => modules.add(each));
    scans.piecesPerScan.mockReset().mockResolvedValue(null);
    inventory.onHand.mockReset().mockResolvedValue([]);
    facade.loadQuote.mockReset().mockResolvedValue(undefined);
    facade.create.mockReset().mockResolvedValue({ ...draft, id: 'q9' });
    facade.revise.mockReset().mockResolvedValue(draft);
    facade.preview.mockReset().mockResolvedValue(null);
    facade.reviseAndSend.mockReset().mockResolvedValue(sent);
    facade.accept.mockReset().mockResolvedValue(accepted);
    facade.refuse.mockReset().mockResolvedValue({ ...sent, status: 'refused' });
    facade.cancel.mockReset().mockResolvedValue({ ...draft, status: 'cancelled' });
    facade.invoice.mockReset().mockResolvedValue({ ...accepted, invoiceId: 'i7' });
    facade.deposit.mockReset().mockResolvedValue({
      ...accepted,
      deposits: [
        { invoiceId: 'd1', number: 'F-2026-0003', status: 'issued', total: '500.000' },
        { invoiceId: 'd2', number: null, status: 'draft', total: '803.250' },
      ],
    });
    facade.detach.mockReset().mockResolvedValue(true);
    facade.restoreAttachment.mockReset().mockResolvedValue(null);
    facade.pickCustomers.mockClear();
    TestBed.configureTestingModule({
      imports: [QuotePage],
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
        { provide: QuotesFacade, useValue: facade },
        { provide: ProductScans, useValue: scans },
        { provide: InventoryFacade, useValue: inventory },
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

  it('says a file is taken off and puts it back where it was on « Annuler »', async () => {
    quote.set(draft);
    attachments.set([
      {
        id: 'a1',
        name: 'plan.pdf',
        mime: 'application/pdf',
        size: 2048,
        createdAt: '2026-10-01T09:00:00+00:00',
      },
    ]);
    await open('q1');
    const feedback = TestBed.inject(Feedback) as RecordedFeedback;

    q('quote-detach-plan.pdf')!.click();
    await settle();
    expect(facade.detach).toHaveBeenCalledWith('c1', 'q1', 'a1');
    const removed = feedback.said.at(-1);
    expect([removed?.key, removed?.action?.key]).toEqual([
      'quotes.attachments.removed',
      'quotes.attachments.undo',
    ]);

    removed?.action?.run();
    await settle();
    expect(facade.restoreAttachment).toHaveBeenCalledWith('c1', 'q1', 'a1');
    expect(feedback.said.at(-1)).toEqual({
      kind: 'success',
      key: 'quotes.attachments.restored',
      params: { name: 'plan.pdf' },
    });
  });

  it('says under a line what is on hand where the quote is made, for whoever may read stock', async () => {
    modules.add('inventory');
    granted.add('stock.read');
    inventory.onHand.mockResolvedValue([{ productId: 'p1', unitId: 'u1', onHand: '1.000' }]);
    quote.set(draft);
    await open('q1');
    await settle();

    expect(inventory.onHand).toHaveBeenLastCalledWith('c1', 'e1', ['p1']);
    expect(q('line-0-stock')?.textContent).toContain('invoices.lines.stock_left');
    expect(q('line-0-stock')?.classList).toContain('text-error');

    granted.delete('stock.read');
    inventory.onHand.mockClear();
    await open('q1');
    await settle();
    expect(inventory.onHand).not.toHaveBeenCalled();
    expect(q('line-0-stock')).toBeNull();
  });

  it('marks a draft sent only once asked, with the lines shown saved first', async () => {
    quote.set(draft);
    await open('q1');

    typeIn(q('line-0-quantity') as HTMLInputElement, '3');
    q('document-action-send')!.click();
    await settle();
    // The number is for good, so neither a click nor the bare key sends unasked.
    expect(facade.reviseAndSend).not.toHaveBeenCalled();
    over('confirm-run')!.click();
    await settle();

    expect(facade.reviseAndSend).toHaveBeenCalledWith(
      'c1',
      'q1',
      expect.objectContaining({
        customerId: 'k1',
        lines: [expect.objectContaining({ quantity: '3', productId: 'p1' })],
      }),
    );
    await vi.waitFor(() => expect(effectToasts()).toEqual(['quotes.sent:definitif']));
  });

  it('never asks a quote line for a lot, even of a product tracked by one', async () => {
    quote.set(draft);
    await open('q1');
    expect(q('line-0-lot')).toBeNull();

    // The product the line names is tracked by lot; picked again, an invoice line would ask which lot.
    const picker = q('line-0-product') as HTMLInputElement | null;
    expect(picker).not.toBeNull();
    picker!.dispatchEvent(new Event('focusin'));
    await settle();
    const option = Array.from(document.body.querySelectorAll<HTMLElement>('mat-option')).find(
      (each) => each.textContent?.includes('Perceuse'),
    );
    option!.click();
    await settle();
    expect(q('line-0-lot')).toBeNull();
  });

  it('starts a new quote for the customer the address names, and opens it once saved', async () => {
    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    await open(undefined, 'k1');
    await vi.waitFor(() =>
      expect(facade.pickCustomers).toHaveBeenCalledWith('c1', { ids: ['k1'] }),
    );
    await settle();

    typeIn(q('line-0-description') as HTMLInputElement, 'Pose');
    typeIn(q('line-0-price') as HTMLInputElement, '40');
    q('document-action-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ customerId: 'k1', establishmentId: 'e1' }),
    );
    expect(navigate).toHaveBeenCalledWith(['/quotes', 'q9'], { replaceUrl: true });
    expect(successToasts()).toContain('quotes.saved');
  });

  it('shows a sent quote past its day as expired, read rather than filled in', async () => {
    quote.set({ ...sent, expired: true });
    await open('q1');

    expect(q('quote-title')?.textContent).toContain('DEV-2026-10-00001');
    expect(q('quote-status')?.textContent).toContain('Expiré');
    expect(q('quote-fixed')?.textContent).toContain('réponse du client');
    expect(q('quote-view-customer')?.textContent).toContain('Carthage Conseil');
    expect(q('document-action-save')).toBeNull();
    expect(q('document-action-send')).toBeNull();
    expect(q('document-action-accept')).not.toBeNull();
    expect(q('document-action-pdf')?.getAttribute('href')).toBe('/api/companies/c1/quotes/q1/pdf');
  });

  it('records the yes with its day and the signed copy, then offers to invoice it', async () => {
    quote.set(sent);
    await open('q1');

    q('document-action-accept')!.click();
    await settle();
    typeIn(over('quote-answered-on') as HTMLInputElement, '2026-10-05');
    const file = new File(['%PDF'], 'signé.pdf', { type: 'application/pdf' });
    const input = over('quote-signed') as HTMLInputElement;
    Object.defineProperty(input, 'files', { value: [file] });
    input.dispatchEvent(new Event('change'));
    await settle();
    over('quote-answer-confirm')!.click();

    await vi.waitFor(() =>
      expect(facade.accept).toHaveBeenCalledWith('c1', 'q1', { answeredOn: '2026-10-05' }, file),
    );
    await vi.waitFor(() => expect(successToasts()).toContain('quotes.accepted'));
    expect(offeredNext()?.key).toBe('quotes.suggest.invoice');
  });

  it('records the no with why, and asks nothing of a file', async () => {
    quote.set(sent);
    await open('q1');

    q('document-action-refuse')!.click();
    await settle();
    expect(over('quote-signed')).toBeNull();
    typeIn(over('quote-refusal-reason') as HTMLTextAreaElement, ' Trop cher ');
    over('quote-answer-confirm')!.click();

    await vi.waitFor(() =>
      expect(facade.refuse).toHaveBeenCalledWith('c1', 'q1', {
        answeredOn: '',
        refusalReason: 'Trop cher',
      }),
    );
  });

  it('invoices an accepted quote only for whoever may draft an invoice, and opens the invoice', async () => {
    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    quote.set(accepted);
    granted.delete('invoice.write');
    await open('q1');
    expect(q('document-action-invoice')).toBeNull();

    granted.add('invoice.write');
    modules.delete('invoices');
    fixture.destroy();
    await open('q1');
    expect(q('document-action-invoice')).toBeNull();

    modules.add('invoices');
    fixture.destroy();
    await open('q1');
    q('document-action-invoice')!.click();
    await settle();

    expect(facade.invoice).toHaveBeenCalledWith('c1', 'q1');
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/invoices', 'i7']));
  });

  it('draws a deposit for the share asked, and opens the new draft', async () => {
    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    quote.set(accepted);
    await open('q1');

    q('document-action-deposit')!.click();
    await settle();
    const value = over('quote-deposit-value') as HTMLInputElement;
    const confirm = over('quote-deposit-confirm') as HTMLButtonElement;
    typeIn(value, '100');
    await settle();
    expect(confirm.disabled).toBe(true);
    typeIn(value, '30');
    await settle();
    confirm.click();

    await vi.waitFor(() =>
      expect(facade.deposit).toHaveBeenCalledWith('c1', 'q1', { percentage: '30' }),
    );
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/invoices', 'd2']));
    expect(successToasts()).toContain('quotes.deposit_drafted');
  });

  it('asks a deposit as an amount when told, to no more decimals than the currency has', async () => {
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    quote.set(accepted);
    await open('q1');

    q('document-action-deposit')!.click();
    await settle();
    (over('quote-deposit-by-amount')!.querySelector('input') as HTMLInputElement).click();
    await settle();
    const value = over('quote-deposit-value') as HTMLInputElement;
    const confirm = over('quote-deposit-confirm') as HTMLButtonElement;
    typeIn(value, '500.0001');
    await settle();
    expect(confirm.disabled).toBe(true);
    typeIn(value, '500.000');
    await settle();
    confirm.click();

    await vi.waitFor(() =>
      expect(facade.deposit).toHaveBeenCalledWith('c1', 'q1', { amount: '500.000' }),
    );
  });

  it('lists the deposits drawn but a cancelled draft, and draws none once invoiced or without invoices', async () => {
    quote.set({
      ...accepted,
      deposits: [
        { invoiceId: 'd0', number: null, status: 'cancelled', total: '100.000' },
        { invoiceId: 'd1', number: 'F-2026-0003', status: 'issued', total: '500.000' },
      ],
    });
    await open('q1');

    expect(q('quote-deposit-0')?.querySelector('a')?.getAttribute('href')).toBe('/invoices/d1');
    expect(q('quote-deposit-1')).toBeNull();
    expect(q('document-action-deposit')).not.toBeNull();

    quote.set({ ...accepted, invoiceId: 'i7' });
    fixture.destroy();
    await open('q1');
    expect(q('document-action-deposit')).toBeNull();
    expect(q('quote-deposits')).toBeNull();

    quote.set(accepted);
    granted.delete('invoice.write');
    fixture.destroy();
    await open('q1');
    expect(q('document-action-deposit')).toBeNull();
  });

  it('links an invoiced quote to its invoice and invoices it no more', async () => {
    quote.set({ ...accepted, invoiceId: 'i7' });
    await open('q1');

    expect(q('document-action-invoice')).toBeNull();
    expect(q('quote-invoice')?.getAttribute('href')).toBe('/invoices/i7');
  });

  // docs/SPEC.md § 7, the live line figures: a quote is worked out as it is typed, as an invoice is.
  it('works the quote out as it is typed, and shows the line and the totals as they would be saved', async () => {
    quote.set(draft);
    await open('q1');
    facade.preview.mockResolvedValue({
      lines: [
        {
          amount: '750.000',
          discount: '0.000',
          net: '750.000',
          documentDiscount: '0.000',
          taxes: [{ code: 'TVA19', base: '750.000', amount: '142.500' }],
          total: '892.500',
        },
      ],
      subtotalNet: '750.000',
      documentDiscount: '0.000',
      totalNet: '750.000',
      taxes: [{ code: 'TVA19', rate: '19.000', base: '750.000', amount: '142.500' }],
      totalTax: '142.500',
      total: '892.500',
    });

    typeIn(q('line-0-quantity') as HTMLInputElement, '3');
    await settle();
    await new Promise((resolve) => setTimeout(resolve, 0));
    for (let i = 0; i < 5; i++) await Promise.resolve();
    await settle();

    expect(facade.preview).toHaveBeenLastCalledWith(
      'c1',
      'q1',
      expect.objectContaining({ lines: [expect.objectContaining({ quantity: '3' })] }),
    );
    const text = (id: string) => (q(id)?.textContent ?? '').replace(/\s+/g, ' ');
    expect(text('line-0-net')).toContain('750,000');
    expect(text('quote-total')).toContain('892,500');
    expect(text('quote-totals-note')).toContain('document_figures.as_typed');
    expect(q('quote-savings')).toBeNull();
  });

  it('says what the discounts save, saved or as typed', async () => {
    quote.set({ ...draft, savings: '75.000' });
    await open('q1');

    expect((q('quote-savings')?.textContent ?? '').replace(/\s+/g, ' ')).toContain('75,000');
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { RecordDeletes } from '../shared/records/record-deletes';
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
  failureToasts,
  offeredNext,
  noticeToasts,
  provideQuietFeedback,
  RecordedFeedback,
  successToasts,
} from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { Feedback } from '../shared/feedback/feedback';
import { QuotePage } from './quote-page';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { QuotesFacade } from './quotes-facade';
import { PREVIEW_DELAY } from '../shared/documents/document-figures';
import type { QuoteAttachment, QuoteRow, QuotesError } from './quotes-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      import: {
        rejections: {
          lines_required: 'Ajoutez au moins une ligne.',
          discount_rate_or_amount: 'Un pourcentage ou un montant, pas les deux.',
          customer_deactivated: 'Le client « {{number}} » est désactivé.',
        },
      },
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
  version: 1,
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
  receivedBy: null,
  receiverRule: 'optional',
  invoiceId: null,
  deposits: [],
  deliveryNotes: [],
  delivery: 'none',
  deliveries: [],
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
      creditedLineId: null,
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
  const refusals = signal<readonly FieldRefusal[]>([]);
  const quote = signal<QuoteRow | null>(null);
  const attachments = signal<readonly QuoteAttachment[]>([]);
  const facade = {
    options: signal<InvoiceOptions | null>(options).asReadonly(),
    quote: quote.asReadonly(),
    attachments: attachments.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    refusals: refusals.asReadonly(),
    loadQuote: vi.fn(),
    pickCustomers: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? customers.filter((each) => asked.ids.includes(each.id)) : customers,
    ),
    pickProducts: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? products.filter((each) => asked.ids.includes(each.id)) : products,
    ),
    productPrice: vi.fn(
      async (): Promise<{ unitPriceNet: string; priceListName: string | null } | null> => null,
    ),
    create: vi.fn(),
    revise: vi.fn(),
    reviseAndSend: vi.fn(),
    accept: vi.fn(),
    refuse: vi.fn(),
    correctReceiver: vi.fn(),
    receivers: vi.fn(),
    cancel: vi.fn(),
    invoice: vi.fn(),
    addToInvoice: vi.fn(),
    draftsOf: vi.fn(),
    deposit: vi.fn(),
    deliver: vi.fn(),
    deliverPart: vi.fn(),
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
  const deletes = { remove: vi.fn() };
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
    deletes.remove.mockReset().mockResolvedValue(true);
    error.set(null);
    refusals.set([]);
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
    facade.accept.mockReset().mockResolvedValue({ kept: accepted });
    facade.refuse.mockReset().mockResolvedValue({ kept: { ...sent, status: 'refused' } });
    facade.correctReceiver.mockReset().mockResolvedValue({ kept: accepted });
    facade.receivers.mockReset().mockResolvedValue([{ contactId: 'kc1', name: 'Ahmed Ben Salah' }]);
    facade.cancel.mockReset().mockResolvedValue({ ...draft, status: 'cancelled' });
    facade.invoice.mockReset().mockResolvedValue({ ...accepted, invoiceId: 'i7' });
    facade.addToInvoice.mockReset().mockResolvedValue({ ...accepted, invoiceId: 'd1' });
    facade.draftsOf.mockReset().mockResolvedValue([]);
    facade.deposit.mockReset().mockResolvedValue({
      kept: {
        ...accepted,
        deposits: [
          { invoiceId: 'd1', number: 'F-2026-0003', status: 'issued', total: '500.000' },
          { invoiceId: 'd2', number: null, status: 'draft', total: '803.250' },
        ],
      },
    });
    facade.deliver.mockReset().mockResolvedValue('n9');
    facade.deliverPart.mockReset().mockResolvedValue({ kept: 'n9' });
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
        { provide: RecordDeletes, useValue: deletes },
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
      1,
      expect.any(Function),
    );
    await vi.waitFor(() => expect(effectToasts()).toEqual(['quotes.sent:definitif']));
  });

  /** The next save is refused as made over a version saved since, and the read after it brings that version. */
  function savedSinceBy(call: 'revise' | 'reviseAndSend'): void {
    facade[call].mockImplementationOnce(async () => {
      error.set('record_changed');
      return null;
    });
    facade.clearError.mockImplementationOnce(() => error.set(null));
    facade.loadQuote.mockImplementationOnce(async () =>
      quote.set({ ...draft, version: 2, customerReference: 'BC-7' }),
    );
  }

  it('says each refusal of a save under its field: a line’s on its line, the customer under its picker', async () => {
    quote.set(draft);
    await open('q1');
    facade.revise.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([
        { field: 'lines[0].discountAmount', reason: 'discount_rate_or_amount', params: {} },
        { field: 'customerId', reason: 'customer_deactivated', params: { number: 'C-0042' } },
      ]);
      return null;
    });
    typeIn(q('line-0-quantity') as HTMLInputElement, '3');
    await settle();
    q('line-0-discount-kind')!.click();
    await settle();
    q('document-action-save')!.click();
    await settle();

    await vi.waitFor(() =>
      expect(q('line-0-discount-error')?.textContent).toContain(
        'Un pourcentage ou un montant, pas les deux.',
      ),
    );
    expect(q('quote-customer-refused')?.textContent).toContain(
      'Le client « C-0042 » est désactivé.',
    );
    expect(q('quote-error')).toBeNull();
  });

  it('says above the form, in its own words, a refusal no field takes, and nothing there once the fields say it all', async () => {
    quote.set(draft);
    await open('q1');
    facade.revise.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([{ field: 'lines', reason: 'lines_required', params: {} }]);
      return null;
    });
    typeIn(q('line-0-quantity') as HTMLInputElement, '3');
    await settle();
    q('document-action-save')!.click();
    await settle();

    await vi.waitFor(() =>
      expect(q('quote-error')?.textContent?.trim()).toBe('Ajoutez au moins une ligne.'),
    );
  });

  it('after a save refused as made over a version saved since, merges what was saved, says nothing was, and saves on it next', async () => {
    quote.set(draft);
    await open('q1');
    savedSinceBy('revise');

    typeIn(q('line-0-quantity') as HTMLInputElement, '3');
    await settle();
    q('document-action-save')!.click();
    await settle();
    await vi.waitFor(() =>
      expect((q('field-customerReference') as HTMLInputElement).value).toBe('BC-7'),
    );

    expect(facade.revise).toHaveBeenCalledWith('c1', 'q1', expect.anything(), 1);
    expect((q('line-0-quantity') as HTMLInputElement).value).toBe('3');
    expect(error()).toBeNull();
    expect(failureToasts()).toEqual(['live.saved_since']);
    expect(successToasts()).not.toContain('quotes.saved');

    q('document-action-save')!.click();
    await settle();
    expect(facade.revise).toHaveBeenLastCalledWith(
      'c1',
      'q1',
      expect.objectContaining({ customerReference: 'BC-7' }),
      2,
    );
  });

  it('marks nothing sent over a version saved since: it merges it and says so', async () => {
    quote.set(draft);
    await open('q1');
    savedSinceBy('reviseAndSend');

    q('document-action-send')!.click();
    await settle();
    over('confirm-run')!.click();
    await settle();
    await vi.waitFor(() =>
      expect((q('field-customerReference') as HTMLInputElement).value).toBe('BC-7'),
    );

    expect(facade.reviseAndSend).toHaveBeenCalledWith(
      'c1',
      'q1',
      expect.anything(),
      1,
      expect.any(Function),
    );
    expect(error()).toBeNull();
    expect(failureToasts()).toEqual(['live.saved_since']);
    expect(effectToasts()).toEqual([]);
  });

  it('marks it sent again on the version its kept save gave, when sending was refused after it', async () => {
    quote.set(draft);
    await open('q1');
    facade.reviseAndSend.mockImplementationOnce(
      async (_c: string, _q: string, _input: unknown, _v: number, revised: () => void) => {
        quote.set({ ...draft, version: 2 });
        revised();
        error.set('invalid');
        return null;
      },
    );

    q('document-action-send')!.click();
    await settle();
    over('confirm-run')!.click();
    await settle();
    error.set(null);
    q('document-action-send')!.click();
    await settle();
    over('confirm-run')!.click();
    await settle();

    expect(facade.reviseAndSend).toHaveBeenLastCalledWith(
      'c1',
      'q1',
      expect.anything(),
      2,
      expect.any(Function),
    );
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
      expect(facade.accept).toHaveBeenCalledWith(
        'c1',
        'q1',
        { answeredOn: '2026-10-05', receivedBy: null },
        file,
      ),
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
        receivedBy: null,
      }),
    );
  });

  describe('« Reçu par »', () => {
    async function openMenu(): Promise<void> {
      q('document-more')!.click();
      await settle();
    }

    it('asks who answered among the customer’s contacts, empty, and sends the one picked', async () => {
      quote.set({ ...sent, receiverRule: 'required' });
      await open('q1');

      q('document-action-accept')!.click();
      await settle();
      expect(facade.receivers).toHaveBeenCalledWith('c1', 'k1');
      const name = over('quote-receiver-name') as HTMLInputElement;
      expect(name.value).toBe('');
      over('quote-answer-confirm')!.click();
      await settle();
      expect(facade.accept, 'the customer’s quotes name who answered').not.toHaveBeenCalled();

      typeIn(name, 'Sami');
      await settle();
      over('quote-answer-confirm')!.click();
      await vi.waitFor(() =>
        expect(facade.accept).toHaveBeenCalledWith(
          'c1',
          'q1',
          {
            answeredOn: '',
            receivedBy: { contactId: null, name: 'Sami', addToContacts: false },
          },
          null,
        ),
      );
    });

    it('asks nobody where the customer’s quotes name nobody, and reads no contacts for it', async () => {
      quote.set({ ...sent, receiverRule: 'off' });
      await open('q1');

      q('document-action-refuse')!.click();
      await settle();
      expect(over('quote-receiver-name')).toBeNull();
      expect(facade.receivers).not.toHaveBeenCalled();
    });

    it('says who answered beside the answer, the name kept as it reads', async () => {
      quote.set({ ...accepted, receivedBy: { name: 'سامي Trabelsi', contactId: null } });
      await open('q1');

      const shown = q('quote-received-by');
      expect(shown?.textContent).toContain('receivers.received_by');
      expect(shown?.querySelector('bdi')?.textContent).toBe('سامي Trabelsi');

      fixture.destroy();
      quote.set(accepted);
      await open('q1');
      expect(q('quote-received-by')).toBeNull();
    });

    it('puts who answered right on an answered quote, and says it is done', async () => {
      quote.set({ ...accepted, receivedBy: { name: 'Sami', contactId: null } });
      await open('q1');

      await openMenu();
      over('document-menu-correct-receiver')!.click();
      await settle();
      const name = over('receiver-dialog-name') as HTMLInputElement;
      expect(name.value).toBe('Sami');
      typeIn(name, 'Sami Trabelsi');
      await settle();
      over('receiver-dialog-save')!.click();

      await vi.waitFor(() =>
        expect(facade.correctReceiver).toHaveBeenCalledWith('c1', 'q1', {
          contactId: null,
          name: 'Sami Trabelsi',
          addToContacts: false,
        }),
      );
      await vi.waitFor(() =>
        expect(effectToasts()).toEqual(['quotes.receiver_corrected:corrigeable']),
      );

      // « Annuler » names again the name typed before, and is offered once more over what it put back.
      quote.set({ ...accepted, receivedBy: { name: 'Sami Trabelsi', contactId: null } });
      const undo = offeredNext();
      expect(undo?.key).toBe('receivers.undo');
      undo!.run();
      await vi.waitFor(() =>
        expect(facade.correctReceiver).toHaveBeenLastCalledWith('c1', 'q1', {
          contactId: null,
          name: 'Sami',
          addToContacts: false,
        }),
      );
      await vi.waitFor(() =>
        expect(effectToasts()).toEqual([
          'quotes.receiver_corrected:corrigeable',
          'quotes.receiver_corrected:corrigeable',
        ]),
      );
    });

    it('takes back a contact named over nobody by naming nobody again', async () => {
      quote.set(accepted);
      await open('q1');

      await openMenu();
      over('document-menu-correct-receiver')!.click();
      await settle();
      over('receiver-dialog-name')!.focus();
      await settle();
      const contact = over('receiver-dialog-contact');
      expect(contact?.textContent).toContain('Ahmed Ben Salah');
      contact!.click();
      await settle();
      over('receiver-dialog-save')!.click();
      await vi.waitFor(() =>
        expect(facade.correctReceiver).toHaveBeenCalledWith('c1', 'q1', {
          contactId: 'kc1',
          name: null,
          addToContacts: false,
        }),
      );

      await vi.waitFor(() => expect(offeredNext()?.key).toBe('receivers.undo'));
      offeredNext()!.run();
      await vi.waitFor(() =>
        expect(facade.correctReceiver).toHaveBeenLastCalledWith('c1', 'q1', null),
      );
    });

    it('offers to put it right only on an answered quote, to whoever writes quotes, unless nobody is ever named', async () => {
      const offered = async (): Promise<boolean> => {
        if (q('document-more') === null) return false;
        await openMenu();
        const found = over('document-menu-correct-receiver') !== null;
        (document.activeElement as HTMLElement | null)?.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, bubbles: true }),
        );
        await settle();
        return found;
      };

      quote.set(sent);
      await open('q1');
      expect(await offered(), 'a quote not answered yet').toBe(false);

      fixture.destroy();
      quote.set({ ...accepted, status: 'refused' });
      await open('q1');
      expect(await offered(), 'a refused quote').toBe(true);

      fixture.destroy();
      quote.set({ ...accepted, receiverRule: 'off' });
      await open('q1');
      expect(await offered(), 'nobody named, nobody ever asked').toBe(false);

      fixture.destroy();
      quote.set({
        ...accepted,
        receiverRule: 'off',
        receivedBy: { name: 'Sami', contactId: null },
      });
      await open('q1');
      expect(await offered(), 'a name kept from before the switch is taken off').toBe(true);

      fixture.destroy();
      granted.delete('quote.write');
      quote.set(accepted);
      await open('q1');
      expect(await offered(), 'a reader').toBe(false);
    });
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

  describe('where an accepted quote is invoiced', () => {
    const drafts = [
      { id: 'd1', total: '120.000', lineCount: 2, customerReference: 'PO-7' },
      { id: 'd2', total: '40.000', lineCount: 1, customerReference: null },
    ];

    it("reads its customer's drafts in its establishment, and starts a new invoice when there are none", async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      quote.set(accepted);
      await open('q1');

      q('document-action-invoice')!.click();
      await settle();

      expect(facade.draftsOf).toHaveBeenCalledWith('c1', 'k1', 'e1');
      expect(over('add-to-title')).toBeNull();
      expect(facade.addToInvoice).not.toHaveBeenCalled();
      await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/invoices', 'i7']));
      expect((TestBed.inject(Feedback) as RecordedFeedback).said.at(-1)?.key).toBe(
        'quotes.invoiced',
      );
    });

    it('offers the drafts beside a new invoice, and adds the quote to the one chosen', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      facade.draftsOf.mockResolvedValue(drafts);
      quote.set(accepted);
      await open('q1');

      q('document-action-invoice')!.click();
      await settle();
      expect(over('add-to-title')?.textContent).toContain('quotes.invoice_target.title');
      await vi.waitFor(() => expect(over('add-to-draft-d2')).not.toBeNull());
      expect(over('add-to-customer')).toBeNull();
      over('add-to-draft-d1')!.click();

      await vi.waitFor(() => expect(facade.addToInvoice).toHaveBeenCalledWith('c1', 'q1', 'd1'));
      expect(facade.invoice).not.toHaveBeenCalled();
      await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/invoices', 'd1']));
      // The quote joined a draft that was there: it did not become one.
      expect((TestBed.inject(Feedback) as RecordedFeedback).said.at(-1)?.key).toBe(
        'quotes.added_to_invoice',
      );
    });

    it('starts a new invoice when that is the one chosen, and does nothing when the question is left', async () => {
      vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      facade.draftsOf.mockResolvedValue(drafts);
      quote.set(accepted);
      await open('q1');

      q('document-action-invoice')!.click();
      await settle();
      over('add-to-close')!.click();
      await vi.waitFor(() => expect(over('add-to-title')).toBeNull());
      await settle();
      expect(facade.invoice).not.toHaveBeenCalled();

      q('document-action-invoice')!.click();
      await settle();
      over('add-to-new')!.click();
      await vi.waitFor(() => expect(facade.invoice).toHaveBeenCalledWith('c1', 'q1'));
      expect(facade.addToInvoice).not.toHaveBeenCalled();
    });

    it('never offers a draft for a quote with a discount on the whole, which a new invoice alone takes as quoted', async () => {
      vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      facade.draftsOf.mockResolvedValue(drafts);
      quote.set({ ...accepted, discountAmount: '10.000' });
      await open('q1');

      q('document-action-invoice')!.click();
      await settle();

      expect(facade.draftsOf).not.toHaveBeenCalled();
      await vi.waitFor(() => expect(facade.invoice).toHaveBeenCalledWith('c1', 'q1'));
    });
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

  it('keeps the deposit asked when its share is refused, saying why under it, and opens nothing', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    facade.deposit.mockResolvedValueOnce({
      refused: [
        {
          field: 'depositPercentage',
          reason: 'deposits_beyond_quote',
          params: { amount: '500.000' },
        },
      ],
    });
    quote.set(accepted);
    await open('q1');

    q('document-action-deposit')!.click();
    await settle();
    typeIn(over('quote-deposit-value') as HTMLInputElement, '81');
    await settle();
    (over('quote-deposit-confirm') as HTMLButtonElement).click();

    await vi.waitFor(() => expect(over('quote-deposit-value-error')).not.toBeNull());
    expect(over('quote-deposit-title')).not.toBeNull();
    expect(navigate).not.toHaveBeenCalled();
    typeIn(over('quote-deposit-value') as HTMLInputElement, '30');
    await settle();
    expect(over('quote-deposit-value-error')).toBeNull();
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

  describe('delivering an accepted quote by notes', () => {
    const toDeliver: QuoteRow = {
      ...accepted,
      deliveries: [
        {
          lineId: 'ql1',
          description: 'Perceuse',
          quantity: '4.000',
          onNotes: '0.000',
          delivered: '0.000',
          left: '4.000',
        },
        {
          lineId: 'ql2',
          description: 'Foret',
          quantity: '2.000',
          onNotes: '2.000',
          delivered: '2.000',
          left: '0.000',
        },
      ],
    };

    beforeEach(() => {
      granted.add('delivery_note.write');
      granted.add('delivery_note.read');
      modules.add('delivery_notes');
    });

    it('offers it only to whoever may draft a note, then drafts every line and opens the note', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      quote.set(toDeliver);
      modules.delete('delivery_notes');
      await open('q1');
      expect(q('document-action-deliver')).toBeNull();

      modules.add('delivery_notes');
      granted.delete('delivery_note.write');
      fixture.destroy();
      await open('q1');
      expect(q('document-action-deliver')).toBeNull();

      granted.add('delivery_note.write');
      fixture.destroy();
      await open('q1');
      q('document-action-deliver')!.click();
      await settle();

      expect(facade.deliver).toHaveBeenCalledWith('c1', 'q1', null);
      await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/delivery-notes', 'n9']));
      expect(successToasts()).toContain('deliveries.drafted');
    });

    it('delivers a part: only the lines with something left, for the quantity given', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      quote.set(toDeliver);
      await open('q1');

      q('document-action-deliver-part')!.click();
      await settle();
      const quantity = over('deliver-part-quantity-0') as HTMLInputElement;
      expect(quantity.value).toBe('4');
      expect(over('deliver-part-quantity-1'), 'a line with nothing left is not offered').toBeNull();
      typeIn(quantity, '1,5');
      over('deliver-part-confirm')!.click();

      await vi.waitFor(() =>
        expect(facade.deliverPart).toHaveBeenCalledWith('c1', 'q1', { ql1: '1.5' }),
      );
      await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/delivery-notes', 'n9']));
    });

    it('keeps the part asked when a quantity is refused, saying it on its line until it changes', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      facade.deliverPart.mockResolvedValueOnce({
        refused: [
          { field: 'quantities[ql1]', reason: 'left_to_deliver', params: { quantity: '4.000' } },
        ],
      });
      quote.set(toDeliver);
      await open('q1');

      q('document-action-deliver-part')!.click();
      await settle();
      over('deliver-part-confirm')!.click();
      await settle();

      await vi.waitFor(() => expect(over('deliver-part-error-0')).not.toBeNull());
      expect(over('deliver-part-title')).not.toBeNull();
      expect(navigate).not.toHaveBeenCalled();
      typeIn(over('deliver-part-quantity-0') as HTMLInputElement, '2');
      await settle();
      expect(over('deliver-part-error-0')).toBeNull();
    });

    it('drafts nothing when the dialog is dismissed or every quantity is zero', async () => {
      quote.set(toDeliver);
      await open('q1');

      q('document-action-deliver-part')!.click();
      await settle();
      typeIn(over('deliver-part-quantity-0') as HTMLInputElement, '0');
      await settle();
      expect((over('deliver-part-confirm') as HTMLButtonElement).disabled).toBe(true);
      over('deliver-part-cancel')!.click();
      await settle();
      expect(facade.deliver).not.toHaveBeenCalled();
      expect(facade.deliverPart).not.toHaveBeenCalled();
    });

    it('is not offered for a quote kept whole by a discount on its total or a deposit, nor with nothing left', async () => {
      quote.set({ ...toDeliver, discountAmount: '10.000' });
      await open('q1');
      expect(q('document-action-deliver')).toBeNull();
      expect(q('document-action-deliver-part')).toBeNull();

      quote.set({
        ...toDeliver,
        deposits: [{ invoiceId: 'd1', number: 'F-2026-0003', status: 'issued', total: '500.000' }],
      });
      fixture.destroy();
      await open('q1');
      expect(q('document-action-deliver')).toBeNull();

      quote.set({
        ...toDeliver,
        deliveries: toDeliver.deliveries.map((line) => ({ ...line, left: '0.000' })),
      });
      fixture.destroy();
      await open('q1');
      expect(q('document-action-deliver')).toBeNull();
    });

    it('once a note delivers it, invoices it whole no more and shows its notes, its figures and how far it is delivered', async () => {
      quote.set({
        ...toDeliver,
        delivery: 'partly',
        deliveryNotes: [
          { deliveryNoteId: 'n1', number: 'BL-2026-00004', status: 'validated' },
          { deliveryNoteId: 'n2', number: null, status: 'draft' },
        ],
      });
      await open('q1');

      expect(q('document-action-invoice')).toBeNull();
      expect(q('document-action-deposit')).toBeNull();
      expect(q('document-action-deliver')).not.toBeNull();
      expect(q('quote-delivery')?.textContent).toContain('deliveries.statuses.partly');
      expect(q('quote-delivery-note-0')?.querySelector('a')?.getAttribute('href')).toBe(
        '/delivery-notes/n1',
      );
      expect(q('quote-delivery-note-1')?.textContent).toContain('deliveries.card.draft');
      expect(q('quote-delivery-line-0')?.textContent).toContain('Perceuse');

      granted.delete('delivery_note.read');
      fixture.destroy();
      await open('q1');
      expect(
        q('quote-delivery-note-0')?.querySelector('a'),
        'no link for whoever may not open it',
      ).toBeNull();
    });

    // A note's change is announced under the note, never under the quote it delivers.
    it('reads an accepted quote again when one of its notes changes, and says how far it is delivered', async () => {
      quote.set({
        ...toDeliver,
        delivery: 'none',
        deliveryNotes: [{ deliveryNoteId: 'n1', number: null, status: 'draft' }],
      });
      await open('q1');
      expect(q('quote-delivery')).toBeNull();
      facade.loadQuote.mockImplementation(async () =>
        quote.set({
          ...toDeliver,
          delivery: 'whole',
          deliveryNotes: [{ deliveryNoteId: 'n1', number: 'BL-2026-00004', status: 'validated' }],
        }),
      );

      facade.loadQuote.mockClear();
      await announceSaved('delivery_note', 'n1', null, 'delivery_note.validated');
      await settle();

      expect(facade.loadQuote).toHaveBeenCalledWith('c1', 'q1');
      expect(q('quote-delivery')?.textContent).toContain('deliveries.statuses.whole');
      expect(noticeToasts()).toEqual([]);
    });

    it('never reads a draft again for a note, which cannot deliver it, and keeps what was typed', async () => {
      quote.set(draft);
      await open('q1');
      typeIn(q('field-customerReference') as HTMLInputElement, 'BC-9');
      await settle();
      facade.loadQuote.mockClear();

      await announceSaved('delivery_note', 'n9', null, 'delivery_note.validated');
      await settle();

      expect(facade.loadQuote).not.toHaveBeenCalled();
      expect((q('field-customerReference') as HTMLInputElement).value).toBe('BC-9');
    });
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
          sentFrom: 0,
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

  describe('products sent here by « Ajouter à… »', () => {
    const paper: ProductOption = {
      id: 'p2',
      reference: 'PAP-1',
      name: 'Papier',
      unitId: 'u1',
      unitPriceNet: '4.5000',
      defaultTaxComponentIds: [],
      tracking: 'none',
      mainPhotoId: null,
    };
    const readById = () =>
      facade.pickProducts.mock.calls
        .filter(([, asked]) => 'ids' in asked)
        .map(([, asked]) => asked);
    const quantityOf = (line: number) =>
      (q(`line-${line}-quantity`) as HTMLInputElement | null)?.value ?? null;

    beforeEach(() => {
      vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      facade.pickProducts.mockClear();
      facade.pickProducts.mockImplementation(async (_companyId: string, asked: PickAsked) =>
        'ids' in asked ? [...products, paper].filter((each) => asked.ids.includes(each.id)) : [],
      );
    });

    it('puts them on an open quote as a scan would, unsaved', async () => {
      quote.set(draft);
      fixture = TestBed.createComponent(QuotePage);
      fixture.componentRef.setInput('quoteId', 'q1');
      fixture.componentRef.setInput('add', ['p1', 'p2']);
      await settle();
      await vi.waitFor(() => expect(readById()).not.toEqual([]));
      await settle();

      expect(readById()).toEqual([{ ids: ['p1', 'p2'] }]);
      expect(quantityOf(0)).toBe('3');
      expect((q('line-1-description') as HTMLInputElement).value).toBe('Papier');
      expect(facade.revise).not.toHaveBeenCalled();
      expect(noticeToasts()).toEqual([{ key: 'documents.add_to.taken_in', params: { count: 2 } }]);
    });

    it('waits for the customer the draft names, whose regime says the taxes', async () => {
      let customerRead: () => void = () => undefined;
      const usual = facade.pickCustomers.getMockImplementation()!;
      onTestFinished(() => void facade.pickCustomers.mockImplementation(usual));
      facade.pickCustomers.mockImplementation(async (companyId: string, asked: PickAsked) => {
        if ('ids' in asked) await new Promise<void>((resolve) => (customerRead = resolve));
        return usual(companyId, asked);
      });
      quote.set(draft);
      fixture = TestBed.createComponent(QuotePage);
      fixture.componentRef.setInput('quoteId', 'q1');
      fixture.componentRef.setInput('add', ['p2']);
      await settle();
      await settle();
      expect(readById()).toEqual([]);

      customerRead();
      await vi.waitFor(() => expect(readById()).toEqual([{ ids: ['p2'] }]));
    });

    it('prices each one as a scan, from the customer’s list', async () => {
      modules.add('price_lists');
      facade.productPrice.mockResolvedValue({ unitPriceNet: '4.0000', priceListName: 'Gros' });
      quote.set(draft);
      fixture = TestBed.createComponent(QuotePage);
      fixture.componentRef.setInput('quoteId', 'q1');
      fixture.componentRef.setInput('add', ['p2']);
      await settle();
      await vi.waitFor(() =>
        expect(facade.productPrice).toHaveBeenCalledWith('c1', 'p2', 'k1', '1'),
      );
    });

    it('adds nothing to a quote already sent', async () => {
      quote.set(sent);
      fixture = TestBed.createComponent(QuotePage);
      fixture.componentRef.setInput('quoteId', sent.id);
      fixture.componentRef.setInput('add', 'p2');
      await settle();
      await vi.waitFor(() =>
        expect(noticeToasts()).toEqual([{ key: 'documents.add_to.not_draft' }]),
      );
      expect(readById()).toEqual([]);
    });
  });

  describe('deleting a draft', () => {
    async function menuEntry(id: string): Promise<HTMLElement | null> {
      q('document-more')?.click();
      await fixture.whenStable();
      fixture.detectChanges();
      return document.body.querySelector<HTMLElement>(`[data-testid="document-menu-${id}"]`);
    }

    it('deletes the draft without asking, said annulable, and goes back to the list once it is gone', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      quote.set(draft);
      await open('q1');

      const entry = await menuEntry('delete');
      expect(entry?.getAttribute('data-kind')).toBe('annulable');
      entry!.click();
      await settle();

      // Not numbered yet, it is named by its customer.
      expect(deletes.remove).toHaveBeenCalledWith('c1', 'quote', [{ id: 'q1', label: 'Carthage' }]);
      expect(navigate).toHaveBeenCalledWith(['/quotes']);
    });

    it('stays on the draft when the delete is refused, and offers none once it left the draft stage or to a reader', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      deletes.remove.mockResolvedValue(false);
      quote.set(draft);
      await open('q1');
      (await menuEntry('delete'))!.click();
      await settle();
      expect(navigate).not.toHaveBeenCalled();

      fixture.destroy();
      quote.set(sent);
      await open('q1');
      expect(await menuEntry('delete')).toBeNull();

      fixture.destroy();
      granted.delete('quote.write');
      quote.set(draft);
      await open('q1');
      expect(await menuEntry('delete')).toBeNull();
    });
  });
});

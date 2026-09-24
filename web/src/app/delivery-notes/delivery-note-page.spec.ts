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
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { DeliveryNotePage } from './delivery-note-page';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import { ProductScans } from '../products/product-scans';
import { ScanBus } from '../shared/scan/scan-bus';
import { ScreenActions } from '../shared/actions/screen-actions';
import { CustomerDisplay } from '../shared/customer-display/customer-display';
import type {
  CustomerOption,
  DeliveryNoteOptions,
  DeliveryNoteRow,
  DeliveryNotesError,
  ProductOption,
} from './delivery-notes-types';
import type { PickAsked } from '../shared/form/pick-api';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { UnsavedChanges } from '../shared/form/unsaved-changes';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      delivery_notes: {
        errors: { conflict: 'La note a changé d’état entre-temps.' },
        statuses: { draft: 'Brouillon', validated: 'Validé', invoiced: 'Facturé' },
        fixed_notes: {
          validated: 'Numéroté, il peut encore être livré.',
          delivered: 'Livré, il attend sa facture.',
          cancelled: 'Annulé, il peut encore être imprimé.',
        },
        invoiced_note: 'Ce bon est sur une facture.',
      },
    });
  }
}

const options: DeliveryNoteOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [{ id: 'e1', code: 'SIEGE', name: 'Siège', isDefault: true }],
  units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
  taxes: [
    { id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat', rate: '19', entersVatBase: false },
  ],
};
/** What the pickers answer; the page is never handed either list whole. */
const customers: CustomerOption[] = [
  { id: 'k1', number: 'CLI-1', name: 'Carthage', excludedFamilies: [] },
  { id: 'k2', number: 'CLI-2', name: 'Export SA', excludedFamilies: ['vat'] },
];
const products: ProductOption[] = [
  {
    id: 'p1',
    reference: 'ART-1',
    name: 'Portable 14"',
    unitId: 'u1',
    unitPriceNet: '1250.0000',
    defaultTaxComponentIds: ['t1'],
  },
];

const draft: DeliveryNoteRow = {
  id: 'n1',
  number: null,
  status: 'draft',
  customerId: 'k1',
  establishmentId: 'e1',
  recordedCustomerName: null,
  customerName: 'Carthage',
  issueDate: null,
  deliveryDate: null,
  deliveryAddress: { line1: null, line2: null, postalCode: null, city: null, countryCode: null },
  customerReference: null,
  remarksPrinted: null,
  notesInternal: null,
  lines: [
    {
      productId: 'p1',
      description: 'Portable 14"',
      quantity: '2.000',
      unitId: 'u1',
      unitPriceNet: '1250.0000',
      taxComponentIds: ['t1'],
      productReference: 'ART-1',
      productName: 'Portable 14"',
      net: '2500.000',
    },
  ],
  subtotalNet: '2500.000',
  taxes: [{ code: 'TVA19', rate: '19.000', base: '2500.000', amount: '475.000' }],
  totalTax: '475.000',
  total: '2975.000',
};
const validated: DeliveryNoteRow = {
  ...draft,
  status: 'validated',
  number: 'BL-2026-00001',
  issueDate: '2026-09-15',
  recordedCustomerName: 'Carthage Conseil',
};

describe('DeliveryNotePage', () => {
  const error = signal<DeliveryNotesError | null>(null);
  const note = signal<DeliveryNoteRow | null>(null);
  const facade = {
    options: signal<DeliveryNoteOptions | null>(options).asReadonly(),
    note: note.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadNote: vi.fn(),
    pickCustomers: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? customers.filter((each) => asked.ids.includes(each.id)) : customers,
    ),
    pickProducts: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? products.filter((each) => asked.ids.includes(each.id)) : products,
    ),
    create: vi.fn(),
    revise: vi.fn(),
    reviseAndValidate: vi.fn(),
    deliver: vi.fn(),
    cancel: vi.fn(),
    invoice: vi.fn(),
    clearError: vi.fn(),
    pdfUrl: (companyId: string, id: string) =>
      `/api/companies/${companyId}/delivery-notes/${id}/pdf`,
  };
  const scans = { piecesPerScan: vi.fn(), named: vi.fn() };
  const display = { show: vi.fn(), total: vi.fn(), clear: vi.fn(), openWindow: vi.fn() };
  const granted = new Set<string>();
  const modules = new Set<string>(['delivery_notes', 'invoices']);
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: (permission: string) => granted.has(permission),
    hasModule: (module: string) => modules.has(module),
  };
  let fixture: ComponentFixture<DeliveryNotePage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  // A menu and a dialog open in the CDK overlay, which hangs off the body rather than the component.
  const over = (testId: string): HTMLElement | null =>
    document.body.querySelector(
      `.cdk-overlay-container [data-testid="${testId}"]`,
    ) as HTMLElement | null;

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

  async function open(deliveryNoteId: string | undefined): Promise<void> {
    fixture = TestBed.createComponent(DeliveryNotePage);
    if (deliveryNoteId !== undefined) {
      fixture.componentRef.setInput('deliveryNoteId', deliveryNoteId);
    }
    await settle();
    // The customer an open note names is resolved by id, one turn after the note itself. Flushing the
    // microtasks is enough and costs nothing: a second whenStable() per open makes this suite time out.
    await Promise.resolve();
    await Promise.resolve();
    fixture.detectChanges();
  }

  beforeEach(() => {
    scans.piecesPerScan.mockReset().mockResolvedValue(null);
    scans.named.mockReset().mockResolvedValue(null);
    Object.values(display).forEach((each) => each.mockReset());
    error.set(null);
    note.set(null);
    granted.clear();
    [
      'delivery_note.read',
      'delivery_note.write',
      'delivery_note.validate',
      'invoice.write',
    ].forEach((each) => granted.add(each));
    modules.clear();
    ['delivery_notes', 'invoices'].forEach((each) => modules.add(each));
    facade.invoice.mockReset().mockResolvedValue('i7');
    facade.loadNote.mockReset().mockResolvedValue(undefined);
    facade.create.mockReset().mockResolvedValue({ ...draft, id: 'n9' });
    facade.revise.mockReset().mockResolvedValue(draft);
    facade.reviseAndValidate.mockReset().mockResolvedValue(validated);
    facade.deliver.mockReset().mockResolvedValue({ ...validated, status: 'delivered' });
    facade.cancel.mockReset().mockResolvedValue({ ...validated, status: 'cancelled' });
    TestBed.configureTestingModule({
      imports: [DeliveryNotePage],
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
        { provide: DeliveryNotesFacade, useValue: facade },
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
  it('titles a delivery note still loading as nothing, never as a new one', async () => {
    await open('n1');

    const title = q('delivery-note-title');
    expect(title?.textContent?.trim()).toBe('');
    expect(title?.getAttribute('aria-hidden')).toBe('true');
  });

  it('titles the page for a new delivery note as new', async () => {
    await open(undefined);

    expect(q('delivery-note-title')?.textContent).toContain('delivery_notes.new_title');
    expect(q('delivery-note-title')?.getAttribute('aria-hidden')).toBeNull();
  });

  it('drafts a note for a customer with a line filled from a product, then opens it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadNote).toHaveBeenCalledWith('c1', null);
    expect(q('document-action-validate')).toBeNull();
    expect(q('document-action-pdf')).toBeNull();

    await pick('delivery-note-customer', 'CLI-1 · Carthage');
    await pick('line-0-product', 'ART-1 · Portable 14"');
    type('line-0-quantity', '2');
    q('document-action-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        customerId: 'k1',
        establishmentId: 'e1',
        lines: [
          {
            productId: 'p1',
            description: 'Portable 14"',
            quantity: '2',
            unitId: 'u1',
            unitPriceNet: '1250.000',
            taxComponentIds: ['t1'],
          },
        ],
      }),
    );
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/delivery-notes', 'n9'], { replaceUrl: true }),
    );
    expect(successToasts()).toContain('delivery_notes.saved');
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: a line's figures show the French decimal comma and take a comma or a point.
  // docs/SPEC.md § 7, 2026-09-23: a carton scanned into a line delivers the pieces it holds.
  it('puts on a line the pieces a scanned pack holds', async () => {
    await open(undefined);
    scans.piecesPerScan.mockResolvedValue(12);
    const field = q('line-0-product') as HTMLInputElement;
    field.dispatchEvent(new Event('focusin'));
    for (const at of [...'13017620422000'].keys()) typeIn(field, '13017620422000'.slice(0, at + 1));
    field.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true, cancelable: true }),
    );
    await settle();
    await vi.waitFor(() =>
      expect(scans.piecesPerScan).toHaveBeenCalledWith('13017620422000', 'p1'),
    );
    await settle();

    expect((q('line-0-quantity') as HTMLInputElement).value).toBe('12');
  });

  it('shows a line’s price with a decimal comma, and sends a typed comma as a point', async () => {
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    await pick('delivery-note-customer', 'CLI-1 · Carthage');
    await pick('line-0-product', 'ART-1 · Portable 14"');
    expect((q('line-0-price') as HTMLInputElement).value).toBe('1250,000');

    type('line-0-quantity', '2,000');
    type('line-0-price', '1250,5');
    q('document-action-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        lines: [expect.objectContaining({ quantity: '2.000', unitPriceNet: '1250.5' })],
      }),
    );
  });

  it('does not send a line the API would refuse, and says what is wrong with it', async () => {
    await open(undefined);
    await pick('delivery-note-customer', 'CLI-1 · Carthage');
    type('line-0-description', 'Pièce');
    type('line-0-price', '10');
    type('line-0-quantity', '0');
    q('document-action-save')!.click();
    await settle();

    expect(facade.create).not.toHaveBeenCalled();
    expect(q('line-0-quantity-error')).not.toBeNull();
  });

  it('adds and removes lines', async () => {
    note.set(draft);
    await open('n1');
    q('line-add')!.click();
    await settle();
    expect(q('line-1-description')).not.toBeNull();

    q('line-0-remove')!.click();
    await settle();
    expect(q('line-1-description')).toBeNull();
    expect((q('line-0-description') as HTMLInputElement).value).toBe('');
  });

  it('revises a draft and validates what is on screen', async () => {
    note.set(draft);
    await open('n1');
    expect(facade.loadNote).toHaveBeenCalledWith('c1', 'n1');
    expect((q('line-0-quantity') as HTMLInputElement).value).toBe('2');
    expect(q('delivery-note-totals')?.textContent?.replace(/\s+/g, ' ')).toContain('TVA19 19 %');
    expect(q('delivery-note-totals')?.textContent?.replace(/\s/g, ' ')).toContain('2 975,000');

    type('line-0-quantity', '3');
    q('document-action-save')!.click();
    await settle();
    expect(facade.revise).toHaveBeenCalledWith(
      'c1',
      'n1',
      expect.objectContaining({ lines: [expect.objectContaining({ quantity: '3' })] }),
    );
    expect(successToasts()).toContain('delivery_notes.saved');

    type('line-0-quantity', '4');
    q('document-action-validate')!.click();
    await settle();
    // Numbering is for good, so neither a click nor the bare key V validates unasked (EFF-01).
    expect(facade.reviseAndValidate).not.toHaveBeenCalled();
    over('confirm-run')!.click();
    await settle();
    expect(facade.reviseAndValidate).toHaveBeenCalledWith(
      'c1',
      'n1',
      expect.objectContaining({ lines: [expect.objectContaining({ quantity: '4' })] }),
    );
  });

  it('counts what is typed on a note, so leaving it asks first (RCH-01)', async () => {
    const unsaved = TestBed.inject(UnsavedChanges);
    note.set(draft);
    await open('n1');
    expect(unsaved.count()).toBe(0);
    type('line-0-quantity', '3');
    await settle();
    expect(unsaved.count()).toBeGreaterThan(0);
  });

  it('counts a new note once a customer is picked', async () => {
    const unsaved = TestBed.inject(UnsavedChanges);
    await open(undefined);
    expect(unsaved.count()).toBe(0);
    await pick('delivery-note-customer', 'CLI-1 · Carthage');
    expect(unsaved.count()).toBeGreaterThan(0);
  });

  it('takes the header and lines another person saved into a quiet draft', async () => {
    note.set(draft);
    await open('n1');
    facade.loadNote.mockImplementation(async () => {
      note.set({
        ...draft,
        customerReference: 'BC-7',
        lines: [{ ...draft.lines[0], quantity: '5.000' }],
      });
    });

    await announceSaved('delivery_note', 'n1');
    await settle();

    expect(facade.loadNote).toHaveBeenLastCalledWith('c1', 'n1');
    expect((q('field-customerReference') as HTMLInputElement).value).toBe('BC-7');
    expect((q('line-0-quantity') as HTMLInputElement).value).toMatch(/^5/);
    expect(q('record-changed')).toBeNull();
  });

  it('keeps lines being edited when another person saved other lines, and offers theirs', async () => {
    note.set(draft);
    await open('n1');
    type('line-0-quantity', '3');
    facade.loadNote.mockImplementation(async () => {
      note.set({ ...draft, lines: [{ ...draft.lines[0], quantity: '5.000' }] });
    });

    await announceSaved('delivery_note', 'n1');
    await settle();

    expect((q('line-0-quantity') as HTMLInputElement).value).toBe('3');
    expect(q('record-changed')).not.toBeNull();
    q('field-take-theirs-lines')!.click();
    await settle();
    expect((q('line-0-quantity') as HTMLInputElement).value).toMatch(/^5/);
    expect(q('field-conflict-lines')).toBeNull();
  });

  it('reads a locked note rather than showing a form nobody may fill in', async () => {
    // Design review finding 3: a locked note was a form with every control disabled, plus an empty box per
    // unfilled field. A draft stays a form, which is what a person came to fill in.
    note.set({ ...validated, status: 'delivered', deliveryDate: '2026-09-20' });
    await open('n1');
    expect(q('delivery-note-view')).not.toBeNull();
    expect(q('delivery-note-form')).toBeNull();
    expect(q('delivery-note-customer')).toBeNull();

    note.set(draft);
    await open('n1');
    expect(q('delivery-note-form')).not.toBeNull();
    expect(q('delivery-note-view')).toBeNull();
  });

  it('shows a validated note as it was issued, with its PDF, its delivery and its cancellation', async () => {
    note.set(validated);
    await open('n1');

    expect(q('delivery-note-title')?.textContent).toContain('BL-2026-00001');
    expect(q('delivery-note-status')?.textContent).toContain('Validé');
    expect(q('delivery-note-fixed')?.textContent).toContain('peut encore être livré');
    expect((q('line-0-quantity') as HTMLInputElement).disabled).toBe(true);
    expect(q('document-action-save')).toBeNull();
    expect(q('document-action-validate')).toBeNull();
    expect(q('line-add')).toBeNull();
    expect(q('document-action-pdf')?.getAttribute('href')).toBe(
      '/api/companies/c1/delivery-notes/n1/pdf',
    );

    // Delivering asks for its day in a dialog, so the bar carries actions and not a date field.
    expect(q('delivery-note-delivered-on')).toBeNull();
    q('document-action-deliver')!.click();
    await settle();
    typeIn(over('delivery-note-delivered-on') as HTMLInputElement, '2026-09-20');
    over('delivery-note-deliver')!.click();
    await vi.waitFor(() => expect(facade.deliver).toHaveBeenCalledWith('c1', 'n1', '2026-09-20'));

    // Cancelling is destructive, so it is behind "⋮" and asks before it runs.
    expect(q('document-action-cancel')).toBeNull();
    q('document-more')!.click();
    await settle();
    over('document-menu-cancel')!.click();
    await settle();
    expect(facade.cancel).not.toHaveBeenCalled();
    over('confirm-run')!.click();
    await settle();
    expect(facade.cancel).toHaveBeenCalledWith('c1', 'n1');
  });

  it('shows an invoiced note with its delivery day and PDF, neither delivered nor cancelled again', async () => {
    note.set({ ...validated, status: 'invoiced', deliveryDate: '2026-09-20' });
    await open('n1');

    expect(q('delivery-note-status')?.textContent).toContain('Facturé');
    expect(q('delivery-note-status')?.textContent).toContain('20/09/2026');
    expect(q('delivery-note-invoiced')?.textContent).toContain('sur une facture');
    expect(q('delivery-note-fixed')).toBeNull();
    expect(q('document-action-deliver')).toBeNull();
    expect(q('delivery-note-delivered-on')).toBeNull();
    expect(q('document-more')).toBeNull();
    expect(q('document-action-pdf')).not.toBeNull();
  });

  it('tells a delivered or a cancelled note only what it can still become', async () => {
    note.set({ ...validated, status: 'delivered', deliveryDate: '2026-09-20' });
    await open('n1');
    expect(q('delivery-note-fixed')?.textContent).toContain('attend sa facture');
    expect(q('delivery-note-fixed')?.textContent).not.toContain('livré.');
    expect(q('document-more')).toBeNull();

    note.set({ ...validated, status: 'cancelled' });
    await settle();
    expect(q('delivery-note-fixed')?.textContent).toContain('Annulé, il peut encore être imprimé');
    expect(q('document-action-deliver')).toBeNull();
    expect(q('document-more')).toBeNull();
  });

  it('shows a reader the note and its PDF without a way to change it', async () => {
    granted.clear();
    granted.add('delivery_note.read');
    note.set(draft);
    await open('n1');

    expect(q('delivery-note-read-only')).not.toBeNull();
    expect(q('document-action-save')).toBeNull();
    expect(q('document-action-validate')).toBeNull();
    expect(q('document-more')).toBeNull();
    expect(q('line-add')).toBeNull();
    expect(q('document-action-pdf')).not.toBeNull();
  });

  it('says why the API refused', async () => {
    error.set('conflict');
    note.set(draft);
    await open('n1');

    expect(q('delivery-note-error')?.textContent).toContain('changé d’état');
  });
  it('drafts an invoice from a validated or delivered note and opens it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    note.set(validated);
    await open('n1');
    q('document-action-invoice')!.click();
    await settle();
    expect(facade.invoice).toHaveBeenCalledWith('c1', 'n1');
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/invoices', 'i7']));

    note.set({ ...validated, status: 'delivered' });
    await settle();
    expect(q('document-action-invoice')).not.toBeNull();
  });

  /** The picked row carries the regime, and nothing else does: no list is held to look one up in. */
  it('stops offering a line tax once a customer whose regime refuses it is named', async () => {
    await open(undefined);
    await pick('delivery-note-customer', 'CLI-1 · Carthage');
    expect(q('line-0-tax-TVA19')).not.toBeNull();

    await pick('delivery-note-customer', 'CLI-2 · Export SA');

    expect(q('line-0-tax-TVA19')).toBeNull();
  });

  it('offers no invoice for a draft, an invoiced note, without the invoices module or the permission', async () => {
    note.set(draft);
    await open('n1');
    expect(q('document-action-invoice')).toBeNull();

    note.set({ ...validated, status: 'invoiced' });
    await settle();
    expect(q('document-action-invoice')).toBeNull();

    note.set(validated);
    modules.delete('invoices');
    await open('n1');
    expect(q('document-action-invoice')).toBeNull();

    modules.add('invoices');
    granted.delete('invoice.write');
    await open('n1');
    expect(q('document-action-invoice')).toBeNull();
  });

  describe('a scan on the screen', () => {
    const laptop = {
      productId: 'p1',
      reference: 'ART-1',
      name: 'Portable 14"',
      isActive: true,
      code: '3017620422003',
      role: 'unit' as const,
      quantity: 1,
      lot: null,
      useBy: null,
      serial: null,
      unitPriceNet: '1000.0000',
      unitPriceGross: '1190.000',
      priceGross: '1190.000',
    };
    const scanned = (code: string) => TestBed.inject(ScanBus).receive(code, 'wedge');
    const quantityOf = (line: number) =>
      (q(`line-${line}-quantity`) as HTMLInputElement | null)?.value ?? null;

    beforeEach(() => granted.add('product.read'));

    it('starts a new document with the product a scan card sent here, once', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      scans.named.mockResolvedValue(laptop);
      fixture = TestBed.createComponent(DeliveryNotePage);
      fixture.componentRef.setInput('scan', '3017620422003');
      await settle();
      await vi.waitFor(() => expect(scans.named).toHaveBeenCalledWith('3017620422003'));
      await settle();

      expect((q('line-0-description') as HTMLInputElement).value).toBe('Portable 14"');
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

    it('counts a product already on a draft line, and starts a line for another', async () => {
      note.set(draft);
      await open('n1');
      scans.named.mockResolvedValueOnce(laptop);

      expect(await scanned('3017620422003')).toMatchObject({
        kind: 'done',
        key: 'scan.incremented',
      });
      await settle();
      expect(quantityOf(0)).toBe('3');

      scans.named.mockResolvedValueOnce({ ...laptop, productId: 'p2', name: 'Souris' });
      facade.pickProducts.mockResolvedValueOnce([
        {
          id: 'p2',
          reference: 'SOU-1',
          name: 'Souris',
          unitId: 'u1',
          unitPriceNet: '30.0000',
          defaultTaxComponentIds: [],
        },
      ]);
      expect(await scanned('5449000000996')).toMatchObject({ kind: 'done', key: 'scan.added' });
      await settle();
      expect(quantityOf(1)).toBe('1');
      expect((q('line-1-description') as HTMLInputElement).value).toBe('Souris');
    });

    // docs/SPEC.md § 7, 2026-09-23 slice 6: the customer display.
    it('shows the customer display the line a scan went onto, and empties it on undo and on leaving', async () => {
      note.set(draft);
      await open('n1');
      scans.named.mockResolvedValue(laptop);

      await scanned('3017620422003');
      expect(display.show).toHaveBeenLastCalledWith({
        name: 'Portable 14"',
        quantity: '3',
        unitPrice: '1190.000',
      });

      TestBed.inject(ScanBus).undoLast();
      expect(display.clear).toHaveBeenCalledTimes(1);
      fixture.destroy();
      expect(display.clear).toHaveBeenCalledTimes(2);
    });

    it('keeps the customer display through the first save, which opens the note at its own address', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      await open(undefined);
      await pick('delivery-note-customer', 'CLI-1 · Carthage');
      scans.named.mockResolvedValue(laptop);
      await scanned('3017620422003');
      await settle();

      q('document-action-save')!.click();
      await vi.waitFor(() =>
        expect(navigate).toHaveBeenCalledWith(['/delivery-notes', 'n9'], { replaceUrl: true }),
      );
      fixture.destroy();

      expect(display.clear).not.toHaveBeenCalled();
    });

    it('empties the customer display when the screen goes on to another note', async () => {
      note.set(draft);
      await open('n1');
      scans.named.mockResolvedValue(laptop);
      await scanned('3017620422003');
      await settle();
      expect(display.clear).not.toHaveBeenCalled();

      fixture.componentRef.setInput('deliveryNoteId', 'n2');
      await settle();

      expect(display.clear).toHaveBeenCalledTimes(1);
    });

    it('offers to open the customer display on a draft', async () => {
      note.set(draft);
      await open('n1');
      const action = TestBed.inject(ScreenActions)
        .actions()
        .find((each) => each.id === 'customer-display');
      expect(action).toBeDefined();
      action?.run?.();
      expect(display.openWindow).toHaveBeenCalled();
    });

    it('leaves a scan to the card once the note is validated', async () => {
      note.set(validated);
      await open('n1');
      scans.named.mockResolvedValue(laptop);

      expect(await scanned('3017620422003')).toEqual({ kind: 'unclaimed' });
      expect(scans.named).not.toHaveBeenCalled();
    });
  });
});

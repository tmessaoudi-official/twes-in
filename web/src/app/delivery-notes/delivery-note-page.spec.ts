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
import type {
  DeliveryNoteOptions,
  DeliveryNoteRow,
  DeliveryNotesError,
} from './delivery-notes-types';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';

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
  customers: [{ id: 'k1', number: 'CLI-1', name: 'Carthage', excludedFamilies: [] }],
  products: [
    {
      id: 'p1',
      reference: 'ART-1',
      name: 'Portable 14"',
      unitId: 'u1',
      unitPriceNet: '1250.0000',
      defaultTaxComponentIds: ['t1'],
    },
  ],
  units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
  taxes: [
    { id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat', rate: '19', entersVatBase: false },
  ],
};
const draft: DeliveryNoteRow = {
  id: 'n1',
  number: null,
  status: 'draft',
  customerId: 'k1',
  establishmentId: 'e1',
  customerName: null,
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
  customerName: 'Carthage Conseil',
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

  async function open(deliveryNoteId: string | undefined): Promise<void> {
    fixture = TestBed.createComponent(DeliveryNotePage);
    if (deliveryNoteId !== undefined) {
      fixture.componentRef.setInput('deliveryNoteId', deliveryNoteId);
    }
    await settle();
  }

  beforeEach(() => {
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
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('drafts a note for a customer with a line filled from a product, then opens it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadNote).toHaveBeenCalledWith('c1', null);
    expect(q('delivery-note-validate')).toBeNull();
    expect(q('delivery-note-pdf')).toBeNull();

    await choose('field-customerId', 'CLI-1 · Carthage');
    await choose('line-0-product', 'ART-1 · Portable 14"');
    type('line-0-quantity', '2');
    q('delivery-note-save')!.click();
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

  it('does not send a line the API would refuse, and says what is wrong with it', async () => {
    await open(undefined);
    await choose('field-customerId', 'CLI-1 · Carthage');
    type('line-0-description', 'Pièce');
    type('line-0-price', '10');
    type('line-0-quantity', '0');
    q('delivery-note-save')!.click();
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
    q('delivery-note-save')!.click();
    await settle();
    expect(facade.revise).toHaveBeenCalledWith(
      'c1',
      'n1',
      expect.objectContaining({ lines: [expect.objectContaining({ quantity: '3' })] }),
    );
    expect(successToasts()).toContain('delivery_notes.saved');

    type('line-0-quantity', '4');
    q('delivery-note-validate')!.click();
    await settle();
    expect(facade.reviseAndValidate).toHaveBeenCalledWith(
      'c1',
      'n1',
      expect.objectContaining({ lines: [expect.objectContaining({ quantity: '4' })] }),
    );
  });

  it('shows a validated note as it was issued, with its PDF, its delivery and its cancellation', async () => {
    note.set(validated);
    await open('n1');

    expect(q('delivery-note-title')?.textContent).toContain('BL-2026-00001');
    expect(q('delivery-note-status')?.textContent).toContain('Validé');
    expect(q('delivery-note-fixed')?.textContent).toContain('peut encore être livré');
    expect((q('line-0-quantity') as HTMLInputElement).disabled).toBe(true);
    expect(q('delivery-note-save')).toBeNull();
    expect(q('delivery-note-validate')).toBeNull();
    expect(q('line-add')).toBeNull();
    expect(q('delivery-note-pdf')?.getAttribute('href')).toBe(
      '/api/companies/c1/delivery-notes/n1/pdf',
    );

    type('delivery-note-delivered-on', '2026-09-20');
    q('delivery-note-deliver')!.click();
    await settle();
    expect(facade.deliver).toHaveBeenCalledWith('c1', 'n1', '2026-09-20');

    expect(q('delivery-note-cancel-confirm')).toBeNull();
    q('delivery-note-cancel')!.click();
    await settle();
    expect(facade.cancel).not.toHaveBeenCalled();
    q('delivery-note-cancel-confirm')!.click();
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
    expect(q('delivery-note-deliver')).toBeNull();
    expect(q('delivery-note-delivered-on')).toBeNull();
    expect(q('delivery-note-cancel')).toBeNull();
    expect(q('delivery-note-pdf')).not.toBeNull();
  });

  it('tells a delivered or a cancelled note only what it can still become', async () => {
    note.set({ ...validated, status: 'delivered', deliveryDate: '2026-09-20' });
    await open('n1');
    expect(q('delivery-note-fixed')?.textContent).toContain('attend sa facture');
    expect(q('delivery-note-fixed')?.textContent).not.toContain('livré.');
    expect(q('delivery-note-cancel')).toBeNull();

    note.set({ ...validated, status: 'cancelled' });
    await settle();
    expect(q('delivery-note-fixed')?.textContent).toContain('Annulé, il peut encore être imprimé');
    expect(q('delivery-note-deliver')).toBeNull();
    expect(q('delivery-note-cancel')).toBeNull();
  });

  it('shows a reader the note and its PDF without a way to change it', async () => {
    granted.clear();
    granted.add('delivery_note.read');
    note.set(draft);
    await open('n1');

    expect(q('delivery-note-read-only')).not.toBeNull();
    expect(q('delivery-note-save')).toBeNull();
    expect(q('delivery-note-validate')).toBeNull();
    expect(q('delivery-note-cancel')).toBeNull();
    expect(q('line-add')).toBeNull();
    expect(q('delivery-note-pdf')).not.toBeNull();
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
    q('delivery-note-invoice')!.click();
    await settle();
    expect(facade.invoice).toHaveBeenCalledWith('c1', 'n1');
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith(['/invoices', 'i7']));

    note.set({ ...validated, status: 'delivered' });
    await settle();
    expect(q('delivery-note-invoice')).not.toBeNull();
  });

  it('offers no invoice for a draft, an invoiced note, without the invoices module or the permission', async () => {
    note.set(draft);
    await open('n1');
    expect(q('delivery-note-invoice')).toBeNull();

    note.set({ ...validated, status: 'invoiced' });
    await settle();
    expect(q('delivery-note-invoice')).toBeNull();

    note.set(validated);
    modules.delete('invoices');
    await open('n1');
    expect(q('delivery-note-invoice')).toBeNull();

    modules.add('invoices');
    granted.delete('invoice.write');
    await open('n1');
    expect(q('delivery-note-invoice')).toBeNull();
  });
});

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
import { provideQuietFeedback } from '../shared/testing/feedback';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import { DeliveryNotesPage } from './delivery-notes-page';
import type {
  DeliveryNoteOptions,
  DeliveryNoteRow,
  DeliveryNotesError,
  DeliveryNoteStatusCounts,
} from './delivery-notes-types';
import { exportEntry } from '../shared/testing/list-export';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      delivery_notes: {
        draft: 'Brouillon',
        statuses: { draft: 'Brouillon', validated: 'Validé' },
        errors: { network: 'Le serveur est injoignable.' },
      },
    });
  }
}

const numbered: DeliveryNoteRow = {
  id: 'n1',
  version: 1,
  number: 'BL-2026-00001',
  status: 'validated',
  customerId: 'k1',
  establishmentId: 'e1',
  recordedCustomerName: 'Carthage Conseil',
  customerName: 'Carthage Conseil',
  issueDate: '2026-09-15',
  deliveryDate: null,
  deliveredAt: null,
  deliveryAddress: { line1: null, line2: null, postalCode: null, city: null, countryCode: null },
  customerReference: null,
  remarksPrinted: null,
  notesInternal: null,
  lines: [],
  subtotalNet: '2500.000',
  taxes: [],
  totalTax: '0.000',
  total: '2500',
  invoices: [],
  quote: null,
  invoice: null,
  receivedBy: null,
  receiverRule: null,
};

describe('DeliveryNotesPage', () => {
  const error = signal<DeliveryNotesError | null>(null);
  const statusCounts = signal<DeliveryNoteStatusCounts | null>(null);
  const facade = {
    busy: signal(false).asReadonly(),
    notes: signal<readonly DeliveryNoteRow[]>([
      numbered,
      { ...numbered, id: 'n2', number: null, status: 'draft', recordedCustomerName: null },
    ]).asReadonly(),
    options: signal<DeliveryNoteOptions | null>({
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
    exportUrl: vi.fn(
      (companyId: string, _search: unknown, format: string) =>
        `/api/companies/${companyId}/exports/delivery-notes.${format}`,
    ),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
    hasModule: vi.fn(),
  };
  let fixture: ComponentFixture<DeliveryNotesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

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
    auth.hasPermission.mockReset().mockReturnValue(true);
    auth.hasModule.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [DeliveryNotesPage],
      providers: [
        provideQuietFeedback(),
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
    fixture = TestBed.createComponent(DeliveryNotesPage);
    await settle();
  });

  it('asks the API for the page the list wants, rather than reading the whole ledger', () => {
    expect(facade.loadListContext).toHaveBeenCalledWith('c1');
    expect(facade.loadPage).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ page: 1, itemsPerPage: 25, q: '', status: [] }),
    );
  });

  it('offers what the list shows as a CSV or an Excel file', () => {
    expect(exportEntry(fixture, 'delivery-notes-export', 'csv')?.getAttribute('data-address')).toBe(
      '/api/companies/c1/exports/delivery-notes.csv',
    );
    expect(
      exportEntry(fixture, 'delivery-notes-export', 'xlsx')?.getAttribute('data-address'),
    ).toBe('/api/companies/c1/exports/delivery-notes.xlsx');
  });

  it('lists the notes with their number, status, customer and total at the currency scale', () => {
    const row = (q('delivery-note-n1')?.textContent ?? '').replace(/\s/g, ' ');
    expect(row).toContain('BL-2026-00001');
    expect(row).toContain('Validé');
    expect(row).toContain('Carthage Conseil');
    expect(row).toContain('2 500,000');
    expect(row).toContain('15/09/2026');
    expect(q('list-link-n1')?.getAttribute('href')).toBe('/delivery-notes/n1');

    const draft = q('delivery-note-n2')?.textContent ?? '';
    expect(draft).toContain('Brouillon');
    expect(draft).toContain('Carthage');
    const tone = (testId: string) =>
      q(testId)?.querySelector('app-status-badge')?.getAttribute('data-tone');
    expect([tone('delivery-note-n1'), tone('delivery-note-n2')]).toEqual(['info', 'neutral']);
  });

  it('offers a new note to a writer only', async () => {
    expect(q('delivery-note-add')).not.toBeNull();

    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(DeliveryNotesPage);
    await settle();
    expect(q('delivery-note-add')).toBeNull();
  });

  it('lets whoever may draft an invoice select notes, and offers « Facturer » on them', async () => {
    const box = q('list-select-row-n1')?.querySelector('input') as HTMLInputElement;
    expect(box).not.toBeNull();
    // A draft has no number yet: its box is named by its customer.
    expect(q('list-select-row-n2')?.querySelector('input')?.getAttribute('aria-label')).toBe(
      'delivery_notes.select_draft',
    );
    expect(q('delivery-notes-bulk-invoice')).toBeNull();

    box.click();
    await settle();
    expect(q('delivery-notes-bulk-invoice')).not.toBeNull();
  });

  it('selects nothing for whoever may not draft an invoice, or while invoices are off', async () => {
    auth.hasPermission.mockImplementation((permission: string) => permission !== 'invoice.write');
    fixture = TestBed.createComponent(DeliveryNotesPage);
    await settle();
    expect(q('list-select-row-n1')).toBeNull();
    expect(q('delivery-note-add')).not.toBeNull();

    auth.hasPermission.mockReturnValue(true);
    auth.hasModule.mockImplementation((module: string) => module !== 'invoices');
    fixture = TestBed.createComponent(DeliveryNotesPage);
    await settle();
    expect(q('list-select-row-n1')).toBeNull();
  });

  // Each status chip says how many it would list, as the API counts them.
  it('asks the API what each status would list, and says it on the chips', async () => {
    expect(facade.loadStatusCounts).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ q: '', customerIds: [] }),
    );
    statusCounts.set({
      all: 12,
      statuses: { draft: 2, validated: 4, delivered: 3, cancelled: 1, invoiced: 2 },
    });
    await settle();
    // The counts are in the Select's panel: open the facet, read the option, close it.
    const count = async (id: string): Promise<string | null | undefined> => {
      const trigger = q(`list-facet-${id.split('-')[2]}`)!;
      trigger.click();
      await settle();
      const text = document.body
        .querySelector(`[data-testid="${id}"] [data-option-count] [data-count]`)
        ?.textContent?.trim();
      trigger.click();
      await settle();
      return text;
    };
    // Status combines, so it has no « all » entry to carry the total: each option carries its own count.
    expect(await count('list-facet-status-delivered')).toBe('3');
  });

  it('names the key that opens a new note from here', () => {
    expect(q('delivery-note-add')?.getAttribute('aria-keyshortcuts')).toBe('n');
    expect(q('delivery-note-add')?.querySelector('kbd')?.textContent).toBe('N');
  });

  it('says why the list could not be read', async () => {
    error.set('network');
    await settle();

    expect(q('delivery-notes-error')?.textContent).toContain('injoignable');
  });
});

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
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
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
} from './delivery-notes-types';

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
  number: 'BL-2026-00001',
  status: 'validated',
  customerId: 'k1',
  establishmentId: 'e1',
  customerName: 'Carthage Conseil',
  issueDate: '2026-09-15',
  deliveryDate: null,
  deliveryAddress: { line1: null, line2: null, postalCode: null, city: null, countryCode: null },
  customerReference: null,
  remarksPrinted: null,
  notesInternal: null,
  lines: [],
  subtotalNet: '2500.000',
  taxes: [],
  totalTax: '0.000',
  total: '2500',
};

describe('DeliveryNotesPage', () => {
  const error = signal<DeliveryNotesError | null>(null);
  const facade = {
    notes: signal<readonly DeliveryNoteRow[]>([
      numbered,
      { ...numbered, id: 'n2', number: null, status: 'draft', customerName: null },
    ]).asReadonly(),
    options: signal<DeliveryNoteOptions | null>({
      currency: 'TND',
      currencyScale: 3,
      establishments: [],
      customers: [{ id: 'k1', number: 'CLI-1', name: 'Carthage', excludedFamilies: [] }],
      products: [],
      units: [],
      taxes: [],
    }).asReadonly(),
    error: error.asReadonly(),
    loadList: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
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
    facade.loadList.mockReset().mockResolvedValue(undefined);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [DeliveryNotesPage],
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
        { provide: DeliveryNotesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(DeliveryNotesPage);
    await settle();
  });

  it('lists the notes with their number, status, customer and total at the currency scale', () => {
    expect(facade.loadList).toHaveBeenCalledWith('c1');
    const row = (q('delivery-note-n1')?.textContent ?? '').replace(/\s/g, ' ');
    expect(row).toContain('BL-2026-00001');
    expect(row).toContain('Validé');
    expect(row).toContain('Carthage Conseil');
    expect(row).toContain('2 500,000');
    expect(row).toContain('15/09/2026');
    expect(q('delivery-note-open-n1')?.getAttribute('href')).toBe('/delivery-notes/n1');

    const draft = q('delivery-note-n2')?.textContent ?? '';
    expect(draft).toContain('Brouillon');
    expect(draft).toContain('Carthage');
  });

  it('offers a new note to a writer only', async () => {
    expect(q('delivery-note-add')).not.toBeNull();

    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(DeliveryNotesPage);
    await settle();
    expect(q('delivery-note-add')).toBeNull();
  });

  it('says why the list could not be read', async () => {
    error.set('network');
    await settle();

    expect(q('delivery-notes-error')?.textContent).toContain('injoignable');
  });
});

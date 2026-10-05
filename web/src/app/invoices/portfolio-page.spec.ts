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
import { provideQuietFeedback } from '../shared/testing/feedback';
import type { InvoicesError } from './invoices-types';
import { PortfolioFacade } from './portfolio-facade';
import { PortfolioPage } from './portfolio-page';
import type { PortfolioRow } from './portfolio-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      invoices: {
        errors: { network: 'Serveur injoignable' },
        instruments: { kinds: { check: 'Chèque' }, status: { deposited: 'Remis en banque' } },
      },
    });
  }
}

const cheque: PortfolioRow = {
  id: 'i1',
  invoiceId: 'f1',
  invoiceNumber: 'FAC-2026-10-00007',
  customerName: 'Carthage Conseil',
  currency: 'TND',
  kind: 'check',
  amount: '500.000',
  dueOn: '2026-11-15',
  bank: 'BT',
  number: 'CHQ-77',
  status: 'deposited',
  settledOn: null,
};

describe('PortfolioPage', () => {
  const rows = signal<readonly PortfolioRow[]>([cheque]);
  const error = signal<InvoicesError | null>(null);
  const facade = {
    rows: rows.asReadonly(),
    total: signal(1).asReadonly(),
    error: error.asReadonly(),
    loadPage: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<PortfolioPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function create(): Promise<void> {
    fixture = TestBed.createComponent(PortfolioPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    facade.loadPage.mockReset().mockResolvedValue(undefined);
    error.set(null);
    TestBed.configureTestingModule({
      imports: [PortfolioPage],
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
        { provide: PortfolioFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('lists each cheque with its customer, amount and state, the invoice number opening the invoice', async () => {
    await create();
    const row = q('instrument-row-i1')?.textContent ?? '';
    expect(row).toContain('Carthage Conseil');
    expect(row).toContain('CHQ-77');
    expect(row).toContain('TND');
    expect(row).toContain('Chèque');
    expect(row).toContain('Remis en banque');
    expect(q('list-link-i1')?.getAttribute('href')).toBe('/invoices/f1');
  });

  it('asks the API for the page the list wants, and leaves the status to it', async () => {
    await create();
    expect(facade.loadPage).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ page: 1, itemsPerPage: 25, status: [] }),
    );
  });

  it('says so when the page cannot be read', async () => {
    error.set('network');
    await create();
    expect(q('portfolio-error')?.textContent).toContain('Serveur injoignable');
  });
});

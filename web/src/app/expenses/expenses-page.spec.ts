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
import { ExpensesFacade } from './expenses-facade';
import { ExpensesPage } from './expenses-page';
import type { ExpenseRow, ExpensesError } from './expenses-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ expenses: { statuses: { recorded: 'Comptabilisée' } } });
  }
}

const fuel: ExpenseRow = {
  id: 'e1',
  status: 'recorded',
  date: '2026-09-10',
  reference: null,
  description: 'Gasoil',
  vendorId: 'v1',
  vendorName: 'Sotumag',
  categoryId: 'k1',
  categoryName: 'Carburant',
  amountNet: '100.000',
  taxComponentId: 't1',
  taxRate: '19.000',
  taxAmount: '19.000',
  amountGross: '119.000',
  currency: 'TND',
  dueDate: '2026-10-10',
  paymentMethod: null,
  paidOn: null,
  notes: null,
  attachmentCount: 1,
};

describe('ExpensesPage', () => {
  const expenses = signal<readonly ExpenseRow[]>([fuel]);
  const facade = {
    expenses: expenses.asReadonly(),
    total: signal(1).asReadonly(),
    error: signal<ExpensesError | null>(null).asReadonly(),
    loadPage: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<ExpensesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function create(): Promise<void> {
    fixture = TestBed.createComponent(ExpensesPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    facade.loadPage.mockReset().mockResolvedValue(undefined);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [ExpensesPage],
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
        { provide: ExpensesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it("lists the company's expenses with their vendor, total and status, each opened by its identifier", async () => {
    await create();
    const row = q('expense-e1')?.textContent ?? '';
    expect(row).toContain('Gasoil');
    expect(row).toContain('Sotumag');
    expect(row).toContain('TND');
    expect(row).toContain('Comptabilisée');
    expect(q('list-link-e1')?.getAttribute('href')).toBe('/expenses/e1');
    expect(q('expense-add')?.getAttribute('href')).toBe('/expenses/new');
  });

  it('asks the API for the page the list wants, rather than reading the whole ledger', async () => {
    await create();
    expect(facade.loadPage).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ page: 1, itemsPerPage: 25, q: '', status: null }),
    );
  });

  it('offers a reader no new expense', async () => {
    auth.hasPermission.mockReturnValue(false);
    await create();
    expect(q('expense-add')).toBeNull();
    expect(q('list-link-e1')).not.toBeNull();
  });
});

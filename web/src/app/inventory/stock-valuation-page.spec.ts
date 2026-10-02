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
import { InventoryFacade } from './inventory-facade';
import type { InventoryError, StockValuation } from './inventory-types';
import { StockValuationPage } from './stock-valuation-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ inventory: { valuation: { none: 'Aucun stock', unvalued_note: 'Coût inconnu' } } });
  }
}

const worth: StockValuation = {
  total: '12000.000',
  lines: [
    {
      productId: 'p1',
      productReference: 'ART-001',
      productName: 'Portable',
      unitCode: 'C62',
      quantity: '20.000',
      unitCost: '600.0000',
      value: '12000.000',
      unvaluedQuantity: '0.000',
    },
    {
      productId: 'p2',
      productReference: 'ART-002',
      productName: 'Souris',
      unitCode: 'C62',
      quantity: '3.000',
      unitCost: null,
      value: '0.000',
      unvaluedQuantity: '3.000',
    },
  ],
};

// docs/SPEC.md § 7: what the stock is worth, each product at the weighted average of what came in.
describe('StockValuationPage', () => {
  const valuation = signal<StockValuation | null>(worth);
  const facade = {
    valuation: valuation.asReadonly(),
    error: signal<InventoryError | null>(null).asReadonly(),
    busy: signal(false).asReadonly(),
    loadValuation: vi.fn(),
  };
  let mayRead = true;
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: (permission: string) => mayRead || permission !== 'product.cost.read',
  };
  let fixture: ComponentFixture<StockValuationPage>;
  const q = (id: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${id}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(StockValuationPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    mayRead = true;
    facade.loadValuation.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [StockValuationPage],
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
        { provide: InventoryFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('reads the valuation and shows the total and each product at its average cost', async () => {
    await open();

    expect(facade.loadValuation).toHaveBeenCalledWith('c1');
    expect(q('stock-valuation-total')?.textContent).toMatch(/12\D?000/);
    const row = q('stock-valuation-ART-001')?.textContent ?? '';
    expect(row).toContain('Portable');
    expect(row).toMatch(/600/);
    expect(q('stock-valuation-unvalued-note')).not.toBeNull();
  });

  it('asks for nothing and says so when the person may not read what things cost', async () => {
    mayRead = false;
    await open();

    expect(facade.loadValuation).not.toHaveBeenCalled();
    expect(q('stock-valuation-forbidden')).not.toBeNull();
  });
});

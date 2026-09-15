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
import { InventoryFacade } from './inventory-facade';
import type {
  InventoryError,
  StockLevelRow,
  StockLocationRow,
  StockOptions,
} from './inventory-types';
import { StockPage } from './stock-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        stock: { negative: 'Négatif' },
        errors: { invalid: 'La saisie a été refusée.' },
      },
    });
  }
}

const site: StockLocationRow = {
  id: 'l1',
  establishmentId: 'e1',
  parentId: null,
  kind: 'site',
  code: '000',
  name: 'Siège',
  isDefault: true,
  childCount: 0,
  movementCount: 2,
};
const options: StockOptions = {
  products: [{ id: 'p1', reference: 'ART-1', name: 'Portable', unitCode: 'C62', unitDecimals: 0 }],
  establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
};
const shortage: StockLevelRow = {
  productId: 'p1',
  productReference: 'ART-1',
  productName: 'Portable',
  unitCode: 'C62',
  locationId: 'l1',
  locationCode: '000',
  locationName: 'Siège',
  establishmentId: 'e1',
  quantity: '-2.000',
};

describe('StockPage', () => {
  const error = signal<InventoryError | null>(null);
  const facade = {
    options: signal<StockOptions | null>(options).asReadonly(),
    levels: signal<readonly StockLevelRow[]>([shortage]).asReadonly(),
    locations: signal<readonly StockLocationRow[]>([site]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadStock: vi.fn(),
    record: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<StockPage>;

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

  beforeEach(async () => {
    error.set(null);
    facade.loadStock.mockReset().mockResolvedValue(undefined);
    facade.record.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [StockPage],
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
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(StockPage);
    await settle();
  });

  it('lists what is on hand where, in the unit of its product, and marks stock below zero', () => {
    expect(facade.loadStock).toHaveBeenCalledWith('c1');
    const row = q('stock-ART-1-000')?.textContent ?? '';
    expect(row).toContain('000 — Siège');
    expect(row).toMatch(/[-−]2(?![,.\d])/);
    expect(row).toContain('Négatif');
  });

  it("leads to a product's movements, and to the other stock screens through the tabs", () => {
    expect(q('stock-movements-ART-1-000')?.getAttribute('href')).toBe(
      '/stock/movements?productId=p1',
    );
    expect(q('stock-locations-tab')?.getAttribute('href')).toBe('/stock/locations');
  });

  it('records goods received at the default location', async () => {
    q('stock-receive')!.click();
    await settle();
    type('field-quantity', '10');
    q('stock-movement-save')!.click();
    await settle();

    expect(facade.record).toHaveBeenCalledWith('c1', {
      operation: 'receive',
      productId: 'p1',
      locationId: 'l1',
      quantity: '10',
    });
    expect(q('stock-recorded')).not.toBeNull();
    expect(q('stock-movement-save')).toBeNull();
  });

  it('records what a count found, and keeps the form when it is refused', async () => {
    facade.record.mockResolvedValue(false);
    q('stock-count')!.click();
    await settle();
    type('field-quantity', '7');
    q('stock-movement-save')!.click();
    error.set('invalid');
    await settle();

    expect(facade.record).toHaveBeenCalledWith('c1', {
      operation: 'count',
      productId: 'p1',
      locationId: 'l1',
      quantity: '7',
    });
    expect(q('stock-error')?.textContent).toContain('refusée');
    expect(q('stock-movement-save')).not.toBeNull();
  });

  it('offers no movement to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(StockPage);
    await settle();

    expect(q('stock-receive')).toBeNull();
    expect(q('stock-count')).toBeNull();
  });
});

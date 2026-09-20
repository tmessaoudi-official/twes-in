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
import type { PickAsked } from '../shared/form/pick-api';
import { InventoryFacade } from './inventory-facade';
import type {
  InventoryError,
  StockLevelRow,
  StockLocationRow,
  StockOptions,
  StockProductOption,
} from './inventory-types';
import { StockPage } from './stock-page';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        stock: { negative: 'Négatif', product_required: 'Nommez le produit concerné.' },
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
  establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
};
const shortage: StockLevelRow = {
  id: 'p1:l1',
  productId: 'p1',
  productReference: 'ART-1',
  productName: 'Portable',
  unitCode: 'C62',
  unitDecimals: 0,
  locationId: 'l1',
  locationCode: '000',
  locationName: 'Siège',
  establishmentId: 'e1',
  quantity: '-2.000',
};
/** What the picker answers: the page holds no catalogue, so a product only exists here once it is picked. */
const products: StockProductOption[] = [
  { id: 'p1', reference: 'ART-1', name: 'Portable', unitCode: 'C62', unitDecimals: 0 },
  { id: 'p2', reference: 'ART-2', name: 'Écran', unitCode: 'C62', unitDecimals: 0 },
];

describe('StockPage', () => {
  const error = signal<InventoryError | null>(null);
  const facade = {
    options: signal<StockOptions | null>(options).asReadonly(),
    levels: signal<readonly StockLevelRow[]>([shortage]).asReadonly(),
    locations: signal<readonly StockLocationRow[]>([site]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    total: signal(1).asReadonly(),
    loadStockContext: vi.fn(),
    loadStock: vi.fn(),
    pickProducts: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? products.filter((each) => asked.ids.includes(each.id)) : products,
    ),
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

  beforeEach(async () => {
    error.set(null);
    facade.loadStockContext.mockReset().mockResolvedValue(undefined);
    facade.loadStock.mockReset().mockResolvedValue(undefined);
    facade.record.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [StockPage],
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
        { provide: InventoryFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(StockPage);
    await settle();
  });

  it('asks the API for the page the list wants, rather than reading the whole stock', () => {
    expect(facade.loadStockContext).toHaveBeenCalledWith('c1');
    expect(facade.loadStock).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ page: 1, itemsPerPage: 25, q: '' }),
    );
  });

  it('lists what is on hand where, in the unit of its product, and marks stock below zero', () => {
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

  it('records goods received at the default location, of the product that was picked', async () => {
    q('stock-receive')!.click();
    await settle();
    await pick('field-productId', 'ART-1 · Portable');
    type('field-quantity', '10');
    q('stock-movement-save')!.click();
    await settle();

    expect(facade.record).toHaveBeenCalledWith('c1', {
      operation: 'receive',
      productId: 'p1',
      locationId: 'l1',
      quantity: '10',
    });
    expect(successToasts()).toContain('inventory.stock.recorded');
    expect(q('stock-movement-save')).toBeNull();
  });

  it('records what a count found, and keeps the form when it is refused', async () => {
    facade.record.mockResolvedValue(false);
    q('stock-count')!.click();
    await settle();
    await pick('field-productId', 'ART-1 · Portable');
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

  /**
   * The product is a required field like any other: a page holding the catalogue used to start on the only product
   * there was, and a picker cannot guess. Refusing it says so where the field is, rather than dropping the save.
   */
  it('refuses to record a movement that names no product', async () => {
    q('stock-receive')!.click();
    await settle();
    type('field-quantity', '4');
    q('stock-movement-save')!.click();
    await settle();

    expect(facade.record).not.toHaveBeenCalled();
    expect(q('field-error-productId')).not.toBeNull();

    await pick('field-productId', 'ART-2 · Écran');
    q('stock-movement-save')!.click();
    await settle();

    expect(facade.record).toHaveBeenCalledWith('c1', {
      operation: 'receive',
      productId: 'p2',
      locationId: 'l1',
      quantity: '4',
    });
  });

  it('offers no movement to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(StockPage);
    await settle();

    expect(q('stock-receive')).toBeNull();
    expect(q('stock-count')).toBeNull();
  });
});

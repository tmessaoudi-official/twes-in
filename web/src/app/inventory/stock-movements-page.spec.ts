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
import { ProductScans } from '../products/product-scans';
import type { ProductScan } from '../products/products-types';
import { ScanBus } from '../shared/scan/scan-bus';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { Session } from '../shared/session/session';
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
  StockMovementRow,
  StockOptions,
} from './inventory-types';
import { StockMovementsPage } from './stock-movements-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        movement_kinds: { out: 'Sortie' },
        sources: { delivery_note: 'Bon de livraison' },
        movements: { of_lot: 'Mouvements du lot {{code}}.' },
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
  movementCount: 1,
};
const options: StockOptions = {
  establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
  planShapes: [],
  structureShapes: [],
};
const delivered: StockMovementRow = {
  id: 'm1',
  productId: 'p1',
  productReference: 'ART-1',
  productName: 'Portable',
  unitCode: 'C62',
  unitDecimals: 0,
  locationId: 'l1',
  locationCode: '000',
  locationName: 'Siège',
  kind: 'out',
  quantity: '-3.000',
  sourceType: 'delivery_note',
  sourceId: 'n1',
  lotCode: 'L-2408',
  recordedBy: null,
  at: '2026-09-15T09:00:00+00:00',
};

describe('StockMovementsPage', () => {
  const facade = {
    options: signal<StockOptions | null>(options).asReadonly(),
    levels: signal<readonly StockLevelRow[]>([]).asReadonly(),
    locations: signal<readonly StockLocationRow[]>([site]).asReadonly(),
    movements: signal<readonly StockMovementRow[]>([delivered]).asReadonly(),
    movementsTotal: signal(1).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal<InventoryError | null>(null).asReadonly(),
    loadMovements: vi.fn(),
    reloadMovements: vi.fn(),
    loadLocations: vi.fn(),
  };
  const productScans = { named: vi.fn() };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: () => true,
  };
  let fixture: ComponentFixture<StockMovementsPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    for (const call of [facade.loadMovements, facade.reloadMovements, facade.loadLocations]) {
      call.mockReset().mockResolvedValue(undefined);
    }
    TestBed.configureTestingModule({
      imports: [StockMovementsPage],
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
        { provide: ProductScans, useValue: productScans },
        ...provideQuietFeedback(),
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(StockMovementsPage);
  });

  it("lists one product's movements by what moved, where, why and by how much", async () => {
    fixture.componentRef.setInput('productId', 'p1');
    await settle();

    expect(facade.loadMovements).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ productId: 'p1' }),
    );
    const row = q('stock-movement-m1')?.textContent ?? '';
    expect(row).toContain('ART-1 — Portable');
    expect(row).toContain('000 — Siège');
    expect(row).toContain('Sortie');
    expect(row).toContain('Bon de livraison');
    expect(row).toMatch(/[-−]3(?![,.\d])/);
    expect(q('stock-movements-all')?.getAttribute('href')).toBe('/stock/movements');
  });

  it("reads the company's latest movements when no product is named, and again when one is", async () => {
    await settle();
    // The list asks first, and the page it asks for is what the API is sent — never the whole history.
    expect(facade.loadMovements).toHaveBeenLastCalledWith('c1', {
      page: 1,
      itemsPerPage: 25,
      q: '',
      productId: null,
      locationId: null,
      kind: null,
      sourceType: null,
      lot: null,
      order: { key: 'movedAt', direction: 'desc' },
    });
    expect(q('stock-movements-all')).toBeNull();

    fixture.componentRef.setInput('productId', 'p1');
    await settle();
    expect(facade.loadMovements).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ productId: 'p1', page: 1 }),
    );
  });

  it('names the lot a row moved', async () => {
    await settle();

    expect(q('stock-movement-m1')?.textContent).toContain('L-2408');
  });

  it('narrows to the lot or serial number the address names, says so, and offers the whole history back (row 63 slice 10)', async () => {
    fixture.componentRef.setInput('lot', 'L-2408');
    await settle();

    expect(facade.loadMovements).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ lot: 'L-2408', productId: null }),
    );
    expect(q('stock-movements-of-lot')?.textContent).toContain('Mouvements du lot L-2408.');
    expect(q('stock-movements-all')?.getAttribute('href')).toBe('/stock/movements');
  });

  it('opens the movements of a lot typed into the search, trimmed', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await settle();

    const field = q('stock-movements-lot-search') as HTMLInputElement;
    field.value = '  L-2408 ';
    field.dispatchEvent(new Event('input'));
    field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));

    expect(navigate).toHaveBeenCalledWith(['/stock/movements'], { queryParams: { lot: 'L-2408' } });
  });

  it('opens the movements of the lot or serial number a scanned label carries, and leaves any other code to the card', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await settle();
    const scan = (lot: string | null, serial: string | null): ProductScan =>
      ({
        productId: 'p1',
        name: 'Peinture',
        isActive: true,
        lot,
        serial,
        useBy: null,
      }) as ProductScan;

    productScans.named.mockResolvedValueOnce(scan(null, 'SN-0001'));
    const outcome = await TestBed.inject(ScanBus).receive('(01)06194000123456(21)SN-0001', 'wedge');
    expect(navigate).toHaveBeenCalledWith(['/stock/movements'], {
      queryParams: { lot: 'SN-0001' },
    });
    expect(outcome.kind).toBe('done');

    productScans.named.mockResolvedValueOnce(scan(null, null));
    expect((await TestBed.inject(ScanBus).receive('6194000123456', 'wedge')).kind).toBe(
      'unclaimed',
    );
  });

  it('sits among the stock screens', async () => {
    await settle();

    expect(q('stock-tab')?.getAttribute('href')).toBe('/stock');
  });
});

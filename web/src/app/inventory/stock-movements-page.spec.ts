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
  recordedBy: null,
  at: '2026-09-15T09:00:00+00:00',
};

describe('StockMovementsPage', () => {
  const facade = {
    options: signal<StockOptions | null>(options).asReadonly(),
    levels: signal<readonly StockLevelRow[]>([]).asReadonly(),
    locations: signal<readonly StockLocationRow[]>([site]).asReadonly(),
    movements: signal<readonly StockMovementRow[]>([delivered]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal<InventoryError | null>(null).asReadonly(),
    loadMovements: vi.fn(),
  };
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
    facade.loadMovements.mockReset().mockResolvedValue(undefined);
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

    expect(facade.loadMovements).toHaveBeenCalledWith('c1', 'p1');
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
    expect(facade.loadMovements).toHaveBeenLastCalledWith('c1', null);
    expect(q('stock-movements-all')).toBeNull();

    fixture.componentRef.setInput('productId', 'p1');
    await settle();
    expect(facade.loadMovements).toHaveBeenLastCalledWith('c1', 'p1');
  });

  it('sits among the stock screens', async () => {
    await settle();

    expect(q('stock-tab')?.getAttribute('href')).toBe('/stock');
  });
});

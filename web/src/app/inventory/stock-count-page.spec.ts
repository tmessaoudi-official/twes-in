// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthFacade } from '../auth/auth-facade';
import { ProductScans } from '../products/product-scans';
import { ScanBus } from '../shared/scan/scan-bus';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import type { PickAsked } from '../shared/form/pick-api';
import { InventoryFacade } from './inventory-facade';
import type { InventoryError, StockLocationRow, StockProductOption } from './inventory-types';
import { StockCountPage } from './stock-count-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
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
  childCount: 1,
  movementCount: 0,
};
const rack: StockLocationRow = {
  ...site,
  id: '0192a0b1-0000-7000-8000-000000000002',
  parentId: 'l1',
  kind: 'rack',
  code: 'R1',
  name: 'Rayonnage 1',
  isDefault: false,
  childCount: 0,
};
const products: StockProductOption[] = [
  {
    id: 'p1',
    reference: 'VIS-6',
    name: 'Vis 6x40',
    unitCode: 'C62',
    unitDecimals: 0,
    homeLocationId: null,
    tracking: 'none',
  },
  {
    id: 'p3',
    reference: 'COL-1',
    name: 'Colle',
    unitCode: 'C62',
    unitDecimals: 0,
    homeLocationId: null,
    tracking: 'lot',
  },
];
const scanOf = (productId: string, extra: Record<string, unknown> = {}) => ({
  productId,
  reference: 'X',
  name: products.find((each) => each.id === productId)?.name ?? '?',
  isActive: true,
  code: '3017620422003',
  role: 'unit' as const,
  quantity: 1,
  lot: null,
  useBy: null,
  serial: null,
  unitPriceNet: '1.0000',
  unitPriceGross: '1.190',
  priceGross: '1.190',
  ...extra,
});

describe('StockCountPage', () => {
  const error = signal<InventoryError | null>(null);
  const facade = {
    locations: signal<readonly StockLocationRow[]>([site, rack]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadStockContext: vi.fn(),
    pickProducts: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? products.filter((each) => asked.ids.includes(each.id)) : products,
    ),
    record: vi.fn(),
    clearError: vi.fn(),
  };
  const scans = { named: vi.fn(), piecesPerScan: vi.fn() };
  const granted = new Set<string>();
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: (permission: string) => granted.has(permission),
  };
  let fixture: ComponentFixture<StockCountPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const scanned = (code: string) => TestBed.inject(ScanBus).receive(code, 'wedge');
  const said = () => (TestBed.inject(Feedback) as RecordedFeedback).said;

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
    granted.clear();
    ['stock.read', 'stock.write', 'product.read'].forEach((each) => granted.add(each));
    facade.loadStockContext.mockReset().mockResolvedValue(undefined);
    facade.record.mockReset().mockResolvedValue(true);
    facade.pickProducts.mockClear();
    scans.named.mockReset().mockResolvedValue(null);
    TestBed.configureTestingModule({
      imports: [StockCountPage],
      providers: [
        ...provideQuietFeedback(),
        provideRouter([]),
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: InventoryFacade, useValue: facade },
        { provide: ProductScans, useValue: scans },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(StockCountPage);
    await settle();
  });

  it('counts at the default location until a location label is scanned, and tallies what is scanned there', async () => {
    expect(facade.loadStockContext).toHaveBeenCalledWith('c1');
    expect(q('stock-count-location')?.textContent).toContain('000');

    expect(await scanned(`https://twes.example/stock/locations/${rack.id}`)).toMatchObject({
      kind: 'done',
      key: 'inventory.count.at_location',
    });
    await settle();
    expect(q('stock-count-location')?.textContent).toContain('R1');

    scans.named.mockResolvedValue(scanOf('p1'));
    await scanned('3017620422003');
    const again = await scanned('3017620422003');
    scans.named.mockResolvedValue(scanOf('p1', { role: 'pack', quantity: 12 }));
    await scanned('13017620422000');
    await settle();

    expect(again).toMatchObject({ kind: 'done', key: 'inventory.count.tallied' });
    expect((q('stock-count-0-counted') as HTMLInputElement).value).toBe('14');
    expect(q('stock-count-1')).toBeNull();
    expect(facade.pickProducts).toHaveBeenCalledWith('c1', { ids: ['p1'] });
  });

  it("starts at the location a label's address named, which a phone's camera opens", async () => {
    fixture = TestBed.createComponent(StockCountPage);
    fixture.componentRef.setInput('location', rack.id);
    await settle();
    expect(q('stock-count-location')?.textContent).toContain('R1');
  });

  it('asks a lot of a tracked product whose label named none, and records every line as a count', async () => {
    scans.named.mockResolvedValue(scanOf('p1'));
    await scanned('3017620422003');
    scans.named.mockResolvedValue(scanOf('p3'));
    await scanned('5449000000996');
    await settle();

    q('stock-count-record')!.click();
    await settle();
    expect(facade.record).not.toHaveBeenCalled();
    expect(said()).toContainEqual(
      expect.objectContaining({ kind: 'failure', key: 'inventory.count.incomplete' }),
    );

    type('stock-count-1-lot', 'L-07');
    type('stock-count-0-counted', '9');
    await settle();
    q('stock-count-record')!.click();
    await settle();

    expect(facade.record.mock.calls).toEqual([
      ['c1', { operation: 'count', productId: 'p1', locationId: 'l1', quantity: '9' }],
      [
        'c1',
        { operation: 'count', productId: 'p3', locationId: 'l1', quantity: '1', lotCode: 'L-07' },
      ],
    ]);
    // Recording writes one line after another, so the sheet empties after the last answer, not the first.
    await vi.waitFor(() => {
      fixture.detectChanges();
      expect(q('stock-count-0')).toBeNull();
    });
    expect(said()).toContainEqual(
      expect.objectContaining({ kind: 'success', key: 'inventory.count.recorded' }),
    );
  });

  it('keeps a line the API refused, and takes a scan back', async () => {
    scans.named.mockResolvedValue(scanOf('p1'));
    await scanned('3017620422003');
    scans.named.mockResolvedValue(scanOf('p3', { lot: 'L-07' }));
    await scanned('5449000000996');
    TestBed.inject(ScanBus).undoLast();
    await settle();
    expect(q('stock-count-1')).toBeNull();

    facade.record.mockResolvedValue(false);
    q('stock-count-record')!.click();
    await settle();
    expect(q('stock-count-0')).not.toBeNull();
  });

  it('leaves a scan to the card for somebody who may not count, or a code no product answers to', async () => {
    scans.named.mockResolvedValue(null);
    expect(await scanned('999')).toEqual({ kind: 'unclaimed' });

    granted.delete('stock.write');
    scans.named.mockResolvedValue(scanOf('p1'));
    expect(await scanned('3017620422003')).toEqual({ kind: 'unclaimed' });
  });
});

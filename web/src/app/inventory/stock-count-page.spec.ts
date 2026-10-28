// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
  TranslateService,
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
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import type { FieldRefusal } from '../shared/form/field-refusals';
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
  version: 1,
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
  mainPhotoId: null,
  ...extra,
});

describe('StockCountPage', () => {
  const error = signal<InventoryError | null>(null);
  const refusals = signal<readonly FieldRefusal[]>([]);
  const locations = signal<readonly StockLocationRow[]>([site, rack]);
  const facade = {
    locations: locations.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    refusals: refusals.asReadonly(),
    loadStockContext: vi.fn(),
    pickProducts: vi.fn(async (_companyId: string, asked: PickAsked) =>
      'ids' in asked ? products.filter((each) => asked.ids.includes(each.id)) : products,
    ),
    record: vi.fn(),
    clearError: vi.fn(),
    countContexts: vi.fn(),
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
    refusals.set([]);
    granted.clear();
    ['stock.read', 'stock.write', 'product.read'].forEach((each) => granted.add(each));
    facade.loadStockContext.mockReset().mockResolvedValue(undefined);
    facade.record.mockReset().mockResolvedValue(true);
    facade.pickProducts.mockClear();
    facade.countContexts.mockReset().mockResolvedValue([]);
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

  it('takes a code another shop also uses at the shop counted, and asks for the label where it names neither', async () => {
    // A code is used once per establishment: another shop holds an « R1 » too, listed first.
    const elsewhere = { ...site, id: 'l8', establishmentId: 'e2', code: '001', name: 'Sousse' };
    const third = {
      ...site,
      id: '0192a0b1-0000-7000-8000-000000000007',
      establishmentId: 'e3',
      code: '002',
    };
    locations.set([
      elsewhere,
      { ...rack, id: 'l9', establishmentId: 'e2', parentId: 'l8' },
      site,
      rack,
      third,
    ]);
    try {
      await settle();
      expect(await scanned('R1')).toMatchObject({
        kind: 'done',
        key: 'inventory.count.at_location',
        // Its own shop's rack, not the other shop's (« 001 »).
        params: { name: expect.stringMatching(/^(?!.*001).*R1/) },
      });

      await scanned(`https://twes.example/stock/locations/${third.id}`);
      expect(await scanned('R1')).toEqual({
        kind: 'refused',
        key: 'inventory.scan.location_ambiguous',
        params: { code: 'R1' },
      });
    } finally {
      locations.set([site, rack]);
    }
  });

  it('says on each line what recording it would change, and warns of a place the article is not kept at', async () => {
    facade.countContexts.mockResolvedValue([
      {
        productId: 'p1',
        places: [{ locationId: rack.id, lotCode: null, quantity: '10.000' }],
        homeLocationIds: [rack.id],
      },
    ]);
    scans.named.mockResolvedValue(scanOf('p1'));
    await scanned('3017620422003');
    await settle();

    expect(facade.countContexts).toHaveBeenCalledWith('c1', ['p1']);
    expect(q('stock-count-0-effect-expected')).not.toBeNull();
    expect(q('stock-count-0-effect-elsewhere')?.querySelectorAll('bdi').length).toBe(1);
    // Counted at the default place, where nothing of it is recorded and which is not its home.
    expect(q('stock-count-0-effect-stray')?.textContent).toContain('R1');
    expect(facade.record).not.toHaveBeenCalled();

    // A second scan of it asks nothing more: the product is known.
    await scanned('3017620422003');
    await settle();
    expect(facade.countContexts).toHaveBeenCalledTimes(1);

    // At its own rack it is counted against what is there, with nothing to warn of.
    await scanned(`https://twes.example/stock/locations/${rack.id}`);
    await scanned('3017620422003');
    await settle();
    expect(q('stock-count-1-effect-difference')).not.toBeNull();
    expect(q('stock-count-1-effect-stray')).toBeNull();
  });

  it('names a lot the article never had before it is recorded', async () => {
    facade.countContexts.mockResolvedValue([
      {
        productId: 'p3',
        places: [{ locationId: 'l1', lotCode: 'L-1', quantity: '2.000' }],
        homeLocationIds: [],
      },
    ]);
    scans.named.mockResolvedValue(scanOf('p3', { lot: 'L-1' }));
    await scanned('3017620422003');
    await settle();
    expect(q('stock-count-0-effect-new-lot')).toBeNull();

    type('stock-count-0-lot', 'L-9');
    await settle();
    expect(q('stock-count-0-effect-new-lot')?.textContent).toContain('count_effect.new_lot');
  });

  // docs/SPEC.md § 7, 2026-09-24 12:40 (row 23): what is counted and not recorded is unsaved work.
  it('counts what is tallied and not recorded as unsaved work, and nothing once it is recorded', async () => {
    const unsaved = TestBed.inject(UnsavedChanges);
    expect(unsaved.count()).toBe(0);

    scans.named.mockResolvedValue(scanOf('p1'));
    await scanned('3017620422003');
    await settle();
    expect(unsaved.count()).toBe(1);

    q('stock-count-record')!.click();
    await settle();
    expect(unsaved.count()).toBe(0);
  });

  it('says a count that is not a quantity under its field and records nothing', async () => {
    scans.named.mockResolvedValue(scanOf('p1'));
    await scanned('3017620422003');
    await settle();

    type('stock-count-0-counted', 'douze');
    await settle();
    q('stock-count-record')!.click();
    await settle();

    expect(facade.record).not.toHaveBeenCalled();
    expect(q('stock-count-0-counted-error')?.textContent).toContain(
      'inventory.count.counted_invalid',
    );
    expect(document.activeElement).toBe(q('stock-count-0-counted'));
  });

  it('offers to record only once something is counted', async () => {
    // Audit 2026-10-06 V-23 (d): a filled « Enregistrer le comptage » stood under an empty count.
    expect(q('stock-count-empty')).not.toBeNull();
    expect(q('stock-count-record')).toBeNull();

    scans.named.mockResolvedValue(scanOf('p1'));
    await scanned('3017620422003');
    await settle();
    expect(q('stock-count-record')).not.toBeNull();
  });

  it('tells a member who may not write stock that they may count but not record', async () => {
    granted.delete('stock.write');
    fixture = TestBed.createComponent(StockCountPage);
    await settle();

    expect(q('stock-count-read-only')).not.toBeNull();
    expect(q('stock-count-record')).toBeNull();
  });

  it("starts at the location a label's address named, which a phone's camera opens", async () => {
    fixture = TestBed.createComponent(StockCountPage);
    fixture.componentRef.setInput('location', rack.id);
    await settle();
    expect(q('stock-count-location')?.textContent).toContain('R1');
  });

  it('opens on the product and place « Sous zéro » asked to count, its line waiting for what is there', async () => {
    const unsaved = TestBed.inject(UnsavedChanges);
    fixture = TestBed.createComponent(StockCountPage);
    fixture.componentRef.setInput('location', rack.id);
    fixture.componentRef.setInput('product', 'p1');
    // The product is read after the locations: one more turn for its answer.
    await settle();
    await settle();

    expect(q('stock-count-location')?.textContent).toContain('R1');
    expect(q('stock-count-0')?.textContent).toContain('Vis 6x40');
    expect((q('stock-count-0-counted') as HTMLInputElement).value).toBe('');
    expect(q('stock-count-1')).toBeNull();
    expect(unsaved.count()).toBe(0);

    type('stock-count-0-counted', '4');
    await settle();
    expect(unsaved.count()).toBe(1);
    q('stock-count-record')!.click();
    await settle();
    expect(facade.record).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ productId: 'p1', locationId: rack.id, quantity: '4' }),
    );
  });

  it('names the lot asked for a tracked product, and asks nothing of a product this company does not have', async () => {
    fixture = TestBed.createComponent(StockCountPage);
    fixture.componentRef.setInput('location', rack.id);
    fixture.componentRef.setInput('product', 'p3');
    fixture.componentRef.setInput('lot', 'L-7');
    await settle();
    await settle();
    expect((q('stock-count-0-lot') as HTMLInputElement).value).toBe('L-7');

    fixture = TestBed.createComponent(StockCountPage);
    fixture.componentRef.setInput('location', rack.id);
    fixture.componentRef.setInput('product', 'p9');
    await settle();
    await settle();
    expect(q('stock-count-empty')).not.toBeNull();
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
    // Said under the field it concerns too, the cursor there: a toast alone left the line to be found.
    expect(q('stock-count-1-lot-error')?.textContent).toContain('form.errors.required');
    expect(document.activeElement).toBe(q('stock-count-1-lot'));

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

  // Scanning another location's label once moved every line already on the sheet there.
  it('records each line at the location it was scanned at, and shows the sheet by location', async () => {
    scans.named.mockResolvedValue(scanOf('p1'));
    await scanned('3017620422003');
    await scanned(`https://twes.example/stock/locations/${rack.id}`);
    await scanned('3017620422003');
    await scanned('3017620422003');
    await settle();

    expect(q('stock-count-group-0')?.textContent).toContain('000');
    expect(q('stock-count-group-1')?.textContent).toContain('R1');
    expect((q('stock-count-0-counted') as HTMLInputElement).value).toBe('1');
    expect((q('stock-count-1-counted') as HTMLInputElement).value).toBe('2');

    q('stock-count-record')!.click();
    await settle();
    expect(facade.record.mock.calls).toEqual([
      ['c1', { operation: 'count', productId: 'p1', locationId: 'l1', quantity: '1' }],
      ['c1', { operation: 'count', productId: 'p1', locationId: rack.id, quantity: '2' }],
    ]);
  });

  it('keeps a line the API refused, and takes a scan back', async () => {
    scans.named.mockResolvedValue(scanOf('p1'));
    await scanned('3017620422003');
    scans.named.mockResolvedValue(scanOf('p3', { lot: 'L-07' }));
    await scanned('5449000000996');
    TestBed.inject(ScanBus).undoLast();
    await settle();
    expect(q('stock-count-1')).toBeNull();

    const translate = TestBed.inject(TranslateService);
    translate.setTranslation('fr', {
      import: {
        rejections: { quantity_decimals: '{{unit}} se compte avec {{decimals}} décimales.' },
      },
    });
    translate.use('fr');
    facade.record.mockImplementation(async () => {
      error.set('invalid');
      refusals.set([
        { field: 'quantity', reason: 'quantity_decimals', params: { unit: 'Pièce', decimals: 0 } },
      ]);
      return false;
    });
    q('stock-count-record')!.click();
    await settle();
    expect(q('stock-count-0')).not.toBeNull();
    // Why, on the line itself; the line above the sheet says nothing more.
    expect(q('stock-count-0-refused')?.textContent).toContain('Pièce se compte avec 0 décimales.');
    expect(q('stock-count-error')).toBeNull();
  });

  it('leaves a scan to the card for somebody who may not count, or a code no product answers to', async () => {
    scans.named.mockResolvedValue(null);
    expect(await scanned('999')).toEqual({ kind: 'unclaimed' });

    granted.delete('stock.write');
    scans.named.mockResolvedValue(scanOf('p1'));
    expect(await scanned('3017620422003')).toEqual({ kind: 'unclaimed' });
  });
});

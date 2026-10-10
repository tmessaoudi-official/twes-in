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
import type { ListPickSource } from '../shared/list/list-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        movement_kinds: { out: 'Sortie' },
        sources: { delivery_note: 'Bon de livraison', loss: 'Perte' },
        loss: {
          reasons: { stolen: 'Volée', broken: 'Cassée' },
          files: { count: '{{count}} fichiers joints', open_of: 'Fichiers de la perte : {{name}}' },
        },
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
  reason: null,
  note: null,
  recordedBy: null,
  vendorId: null,
  vendorName: null,
  supplierReference: null,
  receivedOn: null,
  at: '2026-09-15T09:00:00+00:00',
  costTyped: false,
  costToComplete: false,
  attachmentCount: null,
};
/** Recorded by someone who could not read costs: valued at the average until a cost reader enters its cost. */
const uncosted: StockMovementRow = {
  ...delivered,
  id: 'm2',
  kind: 'in',
  quantity: '10.000',
  sourceType: 'receipt',
  sourceId: null,
  lotCode: null,
  costToComplete: true,
};

/** Three broken, written off with the photo of what broke and one more file. */
const lost: StockMovementRow = {
  ...delivered,
  id: 'm3',
  quantity: '-3.000',
  sourceType: 'loss',
  sourceId: null,
  reason: 'broken',
  attachmentCount: 2,
};
const photo = {
  id: 'f1',
  name: 'carton.jpg',
  mime: 'image/jpeg',
  size: 2048,
  createdAt: '2026-10-09T10:00:00+00:00',
};

describe('StockMovementsPage', () => {
  const shown = signal<readonly StockMovementRow[]>([delivered]);
  const facade = {
    options: signal<StockOptions | null>(options).asReadonly(),
    levels: signal<readonly StockLevelRow[]>([]).asReadonly(),
    locations: signal<readonly StockLocationRow[]>([
      site,
      {
        ...site,
        id: 'l2',
        parentId: 'l1',
        kind: 'rack',
        code: 'R1',
        name: 'Rayon 1',
        isDefault: false,
      },
    ]).asReadonly(),
    movements: shown.asReadonly(),
    movementsTotal: signal(1).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal<InventoryError | null>(null).asReadonly(),
    loadMovements: vi.fn(),
    pickProducts: vi.fn(),
    exportMovementsUrl: vi.fn(
      (companyId: string, _search: unknown, format: string) =>
        `/api/companies/${companyId}/exports/stock-movements.${format}`,
    ),
    reloadMovements: vi.fn(),
    loadLocations: vi.fn(),
    receiptCost: vi.fn(),
    enterReceiptCost: vi.fn(),
    lossFiles: vi.fn(),
    attachToLoss: vi.fn(),
    detachFromLoss: vi.fn(),
    clearError: vi.fn(),
    lossFileUrl: vi.fn(
      (companyId: string, movementId: string, fileId: string) =>
        `/api/companies/${companyId}/stock-movements/${movementId}/attachments/${fileId}/content`,
    ),
  };
  const productScans = { named: vi.fn() };
  const readsCosts = signal(true);
  const writesStock = signal(true);
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: (permission: string) =>
      permission === 'product.cost.read'
        ? readsCosts()
        : permission === 'stock.write'
          ? writesStock()
          : true,
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
    facade.receiptCost.mockReset().mockResolvedValue({ mode: 'average' });
    facade.enterReceiptCost.mockReset().mockResolvedValue(true);
    facade.lossFiles.mockReset().mockResolvedValue([photo]);
    facade.attachToLoss.mockReset().mockResolvedValue(true);
    facade.detachFromLoss.mockReset().mockResolvedValue(true);
    shown.set([delivered]);
    readsCosts.set(true);
    writesStock.set(true);
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
      expect.objectContaining({ productIds: ['p1'] }),
    );
    const row = q('stock-movement-m1')?.textContent ?? '';
    expect(row).toContain('ART-1 — Portable');
    expect(row).toContain('000 — Siège');
    expect(row).toContain('Sortie');
    expect(row).toContain('Bon de livraison');
    expect(row).toMatch(/[-−]3(?![,.\d])/);
    expect(q('stock-movements-all')?.getAttribute('href')).toBe('/stock/movements');
  });

  // Row 197: the « Filtres » panel picks products through the API and locations from the ones the page holds, each
  // named by its path, since picking one lists what moved under it too.
  it('finds products through the API and locations among the company’s own, named by their path', async () => {
    await settle();
    facade.pickProducts.mockResolvedValue([
      {
        id: 'p1',
        reference: 'ART-1',
        name: 'Portable',
        unitCode: 'C62',
        unitDecimals: 0,
        homeLocationId: null,
        tracking: 'none',
      },
    ]);
    const sources = (
      fixture.componentInstance as unknown as { pickSources: Record<string, ListPickSource> }
    ).pickSources;

    expect(await sources['product'].search('port')).toEqual([
      { id: 'p1', code: 'ART-1', name: 'Portable' },
    ]);
    expect(facade.pickProducts).toHaveBeenCalledWith('c1', { words: 'port' });
    expect(await sources['location'].search('rayon')).toEqual([
      { id: 'l2', code: '', name: '000 › R1 — Rayon 1' },
    ]);
    expect((await sources['location'].byIds(['l1'])).map((each) => each.name)).toEqual([
      '000 — Siège',
    ]);
  });

  it("reads the company's latest movements when no product is named, and again when one is", async () => {
    await settle();
    // The list asks first, and the page it asks for is what the API is sent — never the whole history.
    expect(facade.loadMovements).toHaveBeenLastCalledWith('c1', {
      page: 1,
      itemsPerPage: 25,
      q: '',
      productIds: [],
      locationIds: [],
      kinds: [],
      sourceTypes: [],
      reasons: [],
      costToComplete: null,
      intervals: {},
      lot: null,
      order: { key: 'movedAt', direction: 'desc' },
    });
    expect(q('stock-movements-all')).toBeNull();

    fixture.componentRef.setInput('productId', 'p1');
    await settle();
    expect(facade.loadMovements).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ productIds: ['p1'], page: 1 }),
    );
  });

  // docs/SPEC.md § 7, audit 2026-10-06 C challenge 9.
  it('says a receipt’s cost is to complete and lets a cost reader enter it, with only what the company leaves open', async () => {
    shown.set([uncosted]);
    await settle();
    expect(q('movement-cost-to-complete')).not.toBeNull();

    q('row-action-enter-cost-m2')!.click();
    await settle();
    expect(facade.receiptCost).toHaveBeenCalledWith('c1', 'p1', '1', '0');
    expect(
      document.body.querySelector('[data-testid="receipt-cost-product"]')?.textContent,
    ).toContain('ART-1 — Portable');
    expect(document.body.querySelector('[data-testid="field-applyCost"]')).toBeNull();
    const cost = document.body.querySelector<HTMLInputElement>('[data-testid="field-unitCost"]')!;
    cost.value = '1200,5';
    cost.dispatchEvent(new Event('input'));
    document.body.querySelector<HTMLElement>('[data-testid="receipt-cost-enter"]')!.click();
    await settle();

    expect(facade.enterReceiptCost).toHaveBeenCalledWith('c1', 'm2', '1200.5', null);
  });

  it('asks the cost of the receipt « À surveiller » sent a cost reader here for, with the choice a suggesting company leaves', async () => {
    facade.receiptCost.mockResolvedValue({ mode: 'suggest' });
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('costOf', 'm2');
    await settle();

    expect(document.body.querySelector('[data-testid="receipt-cost-title"]')).not.toBeNull();
    expect(document.body.querySelector('[data-testid="field-applyCost"]')).not.toBeNull();
    document.body.querySelector<HTMLElement>('[data-testid="receipt-cost-keep"]')!.click();
    await settle();
    expect(navigate).toHaveBeenCalledWith([], {
      queryParams: { costOf: null },
      queryParamsHandling: 'merge',
    });
    expect(facade.enterReceiptCost).not.toHaveBeenCalled();
  });

  it('offers no cost to whoever may not read costs', async () => {
    readsCosts.set(false);
    shown.set([uncosted]);
    await settle();

    expect(q('movement-cost-to-complete')).not.toBeNull();
    expect(q('row-action-enter-cost-m2')).toBeNull();
  });

  it('offers what the list shows as a CSV or an Excel file', async () => {
    await settle();

    expect(q('stock-movements-export-csv')?.getAttribute('data-address')).toBe(
      '/api/companies/c1/exports/stock-movements.csv',
    );
    expect(q('stock-movements-export-xlsx')?.getAttribute('data-address')).toBe(
      '/api/companies/c1/exports/stock-movements.xlsx',
    );
  });

  it('says why a loss left, and what was written beside it', async () => {
    shown.set([{ ...delivered, sourceType: 'loss', reason: 'stolen', note: 'Vitrine forcée' }]);
    await settle();

    const row = q('stock-movement-m1')?.textContent ?? '';
    expect(row).toContain('Perte');
    expect(q('movement-reason')?.textContent).toContain('Volée');
    expect(q('movement-note')?.textContent).toContain('Vitrine forcée');

    shown.set([delivered]);
    await settle();
    expect(q('movement-reason')).toBeNull();
  });

  // Row 74 (b): a loss keeps the photo of what broke, or the complaint filed for a theft.
  it('says how many files a loss keeps, and opens them from the loss alone', async () => {
    shown.set([lost, delivered]);
    await settle();

    expect(q('movement-files')?.textContent).toContain('2 fichiers joints');
    expect(q('row-action-loss-files-m1')).toBeNull();
    q('row-action-loss-files-m3')!.click();
    await settle();

    expect(facade.lossFiles).toHaveBeenCalledWith('c1', 'm3');
    const link = document.body.querySelector<HTMLAnchorElement>(
      '[data-testid="loss-file-open-carton.jpg"]',
    );
    expect(link?.getAttribute('href')).toBe(
      '/api/companies/c1/stock-movements/m3/attachments/f1/content',
    );
    expect(
      document.body.querySelector('[data-testid="loss-files-product"]')?.textContent,
    ).toContain('ART-1 — Portable');
  });

  it('lets a stock writer add a file to a loss and take one off, and a reader only open them', async () => {
    shown.set([lost]);
    await settle();
    q('row-action-loss-files-m3')!.click();
    await settle();

    const file = new File(['%PDF-1.4'], 'plainte.pdf', { type: 'application/pdf' });
    const drop = document.body.querySelector<HTMLInputElement>('[data-testid="loss-files-add"]')!;
    Object.defineProperty(drop, 'files', { value: { item: () => file, length: 1 } });
    drop.dispatchEvent(new Event('change'));
    await settle();
    expect(facade.attachToLoss).toHaveBeenCalledWith('c1', 'm3', file);
    expect(facade.lossFiles).toHaveBeenCalledTimes(2);

    document.body
      .querySelector<HTMLElement>('[data-testid="loss-file-remove-carton.jpg"]')!
      .click();
    await settle();
    expect(facade.detachFromLoss).toHaveBeenCalledWith('c1', 'm3', 'f1');
    document.body.querySelector<HTMLElement>('[data-testid="loss-files-close"]')!.click();
    await settle();

    writesStock.set(false);
    q('row-action-loss-files-m3')!.click();
    await settle();
    expect(document.body.querySelector('[data-testid="loss-file-open-carton.jpg"]')).not.toBeNull();
    expect(document.body.querySelector('[data-testid="loss-files-add"]')).toBeNull();
    expect(document.body.querySelector('[data-testid="loss-file-remove-carton.jpg"]')).toBeNull();
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
      expect.objectContaining({ lot: 'L-2408', productIds: [] }),
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

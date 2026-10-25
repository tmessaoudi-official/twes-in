// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { TextsParts } from '../shared/i18n/texts-part';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { ProductCostHistorySection } from './product-cost-history';
import { ProductCostHistory } from './product-cost-history-facade';
import type { ProductCostChangeRow } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: {
        costs: {
          title: 'Historique',
          none: 'Aucun changement',
          source: { created: 'Création', edited: 'Modifié', receipt: 'Réception' },
        },
      },
      purchase_history: {
        bought: 'Achat',
        undated: 'date inconnue',
        invoice: 'facture {{number}}',
        quantity: 'quantité {{quantity}}',
      },
    });
  }
}

const change = (
  id: string,
  oldCost: string | null,
  newCost: string | null,
  source: ProductCostChangeRow['source'],
): ProductCostChangeRow => ({
  id,
  oldCost,
  newCost,
  source,
  at: '2026-10-03T13:20:24+00:00',
  purchase: null,
});

const bought = (
  id: string,
  cost: string,
  day: string | null,
  invoice: string | null,
  quantity: string | null,
): ProductCostChangeRow => ({
  ...change(id, null, cost, 'purchase'),
  purchase: {
    vendorId: 'v1',
    vendorNumber: 'F-001',
    vendorName: 'Sotumag',
    day,
    invoice,
    quantity,
  },
});

// docs/SPEC.md § 7: every change of a product's cost is kept, and read newest first by whoever may read costs.
describe('ProductCostHistorySection', () => {
  const rows = signal<readonly ProductCostChangeRow[]>([]);
  const facade = {
    rows: rows.asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    load: vi.fn(),
  };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  const texts = { open: vi.fn() };
  let fixture: ComponentFixture<ProductCostHistorySection>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(ProductCostHistorySection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    rows.set([
      change('h2', '1000.0000', '1300.0000', 'receipt'),
      change('h1', null, '1000.0000', 'created'),
    ]);
    facade.load.mockReset().mockResolvedValue(undefined);
    texts.open.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [ProductCostHistorySection],
      providers: [
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        provideRouter([]),
        { provide: ProductCostHistory, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: TextsParts, useValue: texts },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('lists each change from the cost before to the cost after, with what moved it', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1', 'p1');
    const receipt = q('product-cost-change-h2')?.textContent ?? '';
    expect(receipt).toMatch(/1\D?000/);
    expect(receipt).toMatch(/1\D?300/);
    expect(receipt).toContain('Réception');
    // A product that had no cost reads a dash before its first one, never a zero.
    const created = q('product-cost-change-h1')?.textContent ?? '';
    expect(created).toContain('—');
    expect(created).toContain('Création');
  });

  it('lists a past purchase with what one cost, its vendor, day, invoice and quantity, the undated said so', async () => {
    rows.set([
      bought('b2', '1.6882', '2024-02-03', 'FA-12', '72.000'),
      bought('b1', '1.9000', null, null, null),
    ]);
    await open();

    expect(texts.open).toHaveBeenCalledWith('purchase-history');
    const dated = q('product-cost-change-b2')?.textContent ?? '';
    expect(dated).toMatch(/1\D6882/);
    expect(dated).toContain('Achat');
    expect(dated).toContain('Sotumag');
    expect(dated).toContain('facture FA-12');
    expect(dated).toContain('quantité 72');
    expect(dated).not.toContain('→');
    // Its vendor opens from the line.
    expect(q('product-cost-change-b2-vendor')?.getAttribute('href')).toBe('/vendors/v1');
    const undated = q('product-cost-change-b1')?.textContent ?? '';
    expect(undated).toContain('date inconnue');
    expect(undated).not.toContain('facture');
    expect(undated).not.toContain('quantité');
  });

  it('says so when the cost never changed', async () => {
    rows.set([]);
    await open();

    expect(q('product-cost-history-none')).not.toBeNull();
  });
});

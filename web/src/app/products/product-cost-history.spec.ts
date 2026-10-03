// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
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
    });
  }
}

const change = (
  id: string,
  oldCost: string | null,
  newCost: string | null,
  source: ProductCostChangeRow['source'],
): ProductCostChangeRow => ({ id, oldCost, newCost, source, at: '2026-10-03T13:20:24+00:00' });

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
    TestBed.configureTestingModule({
      imports: [ProductCostHistorySection],
      providers: [
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        { provide: ProductCostHistory, useValue: facade },
        { provide: AuthFacade, useValue: auth },
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

  it('says so when the cost never changed', async () => {
    rows.set([]);
    await open();

    expect(q('product-cost-history-none')).not.toBeNull();
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

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
import { VendorPurchasesSection } from './vendor-purchases';
import { VENDOR_PURCHASES_PAGE } from './vendor-purchases-facade';
import { VendorsApi } from './vendors-api';
import type { VendorPurchaseRow } from './vendors-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      purchase_history: {
        undated: 'date inconnue',
        invoice: 'facture {{number}}',
        quantity: 'quantité {{quantity}}',
        none: 'Aucun achat',
        more: 'Afficher plus',
        count: '{{count}} lignes',
      },
    });
  }
}

const line = (
  id: string,
  day: string | null,
  invoice: string | null,
  quantity: string | null,
): VendorPurchaseRow => ({
  id,
  productId: `p-${id}`,
  productReference: `REF-${id}`,
  productName: `Produit ${id}`,
  day,
  invoice,
  quantity,
  unitCost: '1.6882',
});

// A vendor's « Achats »: what was bought from it, the newest day first, the undated last, a page at a time.
describe('VendorPurchasesSection', () => {
  const api = { purchases: vi.fn() };
  const texts = { open: vi.fn() };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  let fixture: ComponentFixture<VendorPurchasesSection>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  // whenStable() does not wait for the mocked API's answer: a turn of the task queue lets the read finish.
  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(VendorPurchasesSection);
    fixture.componentRef.setInput('companyId', 'c1');
    fixture.componentRef.setInput('vendorId', 'v1');
    await settle();
  }

  beforeEach(() => {
    api.purchases.mockReset();
    texts.open.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [VendorPurchasesSection],
      providers: [
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        provideRouter([]),
        { provide: VendorsApi, useValue: api },
        { provide: TextsParts, useValue: texts },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('lists each line with its product, cost, day, invoice and quantity, the undated said so', async () => {
    api.purchases.mockResolvedValue({
      rows: [line('b2', '2024-02-03', 'FA-12', '72.000'), line('b1', null, null, null)],
      total: 2,
    });
    await open();

    expect(texts.open).toHaveBeenCalledWith('purchase-history');
    expect(api.purchases).toHaveBeenCalledWith('c1', 'v1', 1, VENDOR_PURCHASES_PAGE);
    expect(q('vendor-purchases-count')?.textContent).toContain('2 lignes');
    const dated = q('vendor-purchase-b2')?.textContent ?? '';
    expect(dated).toContain('REF-b2');
    expect(dated).toContain('Produit b2');
    expect(dated).toMatch(/1\D6882/);
    expect(dated).toContain('facture FA-12');
    expect(dated).toContain('quantité 72');
    expect(q('vendor-purchase-b2-product')?.getAttribute('href')).toBe('/products/p-b2');
    const undated = q('vendor-purchase-b1')?.textContent ?? '';
    expect(undated).toContain('date inconnue');
    expect(undated).not.toContain('facture');
    // Everything is on screen: nothing more to show.
    expect(q('vendor-purchases-more')).toBeNull();
  });

  it('adds the next page under the lines shown when asked, until every line is there', async () => {
    api.purchases
      .mockResolvedValueOnce({ rows: [line('b3', '2025-01-01', null, null)], total: 2 })
      .mockResolvedValueOnce({ rows: [line('b1', null, null, null)], total: 2 });
    await open();

    q('vendor-purchases-more')?.click();
    await settle();

    expect(api.purchases).toHaveBeenLastCalledWith('c1', 'v1', 2, VENDOR_PURCHASES_PAGE);
    expect(q('vendor-purchase-b3')).not.toBeNull();
    expect(q('vendor-purchase-b1')).not.toBeNull();
    expect(q('vendor-purchases-more')).toBeNull();
  });

  it('says so when nothing was bought from the vendor', async () => {
    api.purchases.mockResolvedValue({ rows: [], total: 0 });
    await open();

    expect(q('vendor-purchases-none')).not.toBeNull();
    expect(q('vendor-purchases-count')).toBeNull();
  });
});

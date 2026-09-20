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
import { provideQuietFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { ProductsFacade } from './products-facade';
import { ProductsPage } from './products-page';
import type {
  ProductCategoryRow,
  ProductOptions,
  ProductRow,
  ProductsError,
} from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: {
        kinds: { goods: 'Bien', service: 'Service' },
        statuses: { active: 'Actif', inactive: 'Inactif' },
        errors: { not_found: 'Produit introuvable.' },
      },
    });
  }
}

const laptop: ProductRow = {
  id: 'p1',
  reference: 'ART-001',
  name: 'Portable 14"',
  description: null,
  kind: 'goods',
  unitId: 'u1',
  unitPriceNet: '1250.5000',
  costPrice: null,
  categoryId: 'k1',
  barcode: null,
  defaultTaxComponentIds: [],
  isActive: true,
  customFields: {},
};

describe('ProductsPage', () => {
  const error = signal<ProductsError | null>(null);
  const facade = {
    products: signal<readonly ProductRow[]>([laptop]).asReadonly(),
    categories: signal<readonly ProductCategoryRow[]>([
      { id: 'k1', name: 'Matériel', parentId: null, productCount: 1, childCount: 0 },
    ]).asReadonly(),
    options: signal<ProductOptions | null>({
      currency: 'TND',
      currencyScale: 3,
      units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
      taxes: [],
    }).asReadonly(),
    customFields: signal<readonly CustomFieldDefinition[]>([]).asReadonly(),
    total: signal(1).asReadonly(),
    error: error.asReadonly(),
    loadListContext: vi.fn(),
    loadPage: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<ProductsPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    error.set(null);
    facade.loadListContext.mockReset().mockResolvedValue(undefined);
    facade.loadPage.mockReset().mockResolvedValue(undefined);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [ProductsPage],
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
        { provide: ProductsFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(ProductsPage);
    await settle();
  });

  it('lists the products with their kind, category, unit and price at the currency scale', () => {
    expect(facade.loadListContext).toHaveBeenCalledWith('c1');
    expect(facade.loadPage).toHaveBeenCalledWith('c1', {
      page: 1,
      itemsPerPage: 25,
      q: '',
      kind: null,
      isActive: null,
      order: { key: 'reference', direction: 'asc' },
    });
    const row = (q('product-ART-001')?.textContent ?? '').replace(/\s/g, ' ');
    expect(row).toContain('Portable 14"');
    expect(row).toContain('Bien');
    expect(row).toContain('Matériel');
    expect(row).toContain('C62');
    expect(row).toContain('1 250,500');
    expect(row).toContain('Actif');
    expect(q('list-link-p1')?.getAttribute('href')).toBe('/products/p1');
  });

  it('reads the page shown and what it names again when products change elsewhere', async () => {
    facade.loadListContext.mockClear();
    facade.loadPage.mockClear();

    await announceSaved('product', 'p9');

    expect(facade.loadListContext).toHaveBeenCalledWith('c1');
    expect(facade.loadPage).toHaveBeenCalledWith('c1', expect.objectContaining({ page: 1 }));
  });

  it('offers a new product and the categories to a writer only', async () => {
    expect(q('product-add')).not.toBeNull();
    expect(q('product-categories-link')).not.toBeNull();

    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(ProductsPage);
    await settle();
    expect(q('product-add')).toBeNull();
    expect(q('product-categories-link')).not.toBeNull();
  });

  it('leads to the categories through the tabs of the products screens', () => {
    expect(q('products-tab')?.getAttribute('href')).toBe('/products');
    expect(q('product-categories-link')?.getAttribute('href')).toBe('/products/categories');
    expect(q('product-categories-link')?.closest('nav')).not.toBeNull();
  });

  it('says why the list could not be read', async () => {
    error.set('not_found');
    await settle();

    expect(q('products-error')?.textContent).toContain('Produit introuvable.');
  });
});

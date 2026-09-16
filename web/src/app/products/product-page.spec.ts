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
import { Session } from '../shared/session/session';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { ProductPage } from './product-page';
import type { SettingRow } from '../shared/settings/settings-types';
import { ArticleSettings } from './article-settings-facade';
import { ProductsFacade } from './products-facade';
import type {
  ProductCategoryRow,
  ProductOptions,
  ProductRow,
  ProductsError,
} from './products-types';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: { errors: { reference_taken: 'Un autre produit porte déjà cette référence.' } },
    });
  }
}

const options: ProductOptions = {
  currency: 'TND',
  currencyScale: 3,
  units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
  taxes: [{ id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat' }],
};
const laptop: ProductRow = {
  id: 'p1',
  reference: 'ART-001',
  name: 'Portable 14"',
  description: null,
  kind: 'goods',
  unitId: 'u1',
  unitPriceNet: '1250.5000',
  costPrice: null,
  categoryId: null,
  barcode: null,
  defaultTaxComponentIds: ['t1'],
  isActive: true,
  customFields: {},
};

describe('ProductPage', () => {
  const error = signal<ProductsError | null>(null);
  const product = signal<ProductRow | null>(null);
  const optionsSignal = signal<ProductOptions | null>(options);
  const facade = {
    options: optionsSignal.asReadonly(),
    categories: signal<readonly ProductCategoryRow[]>([]).asReadonly(),
    customFields: signal<readonly CustomFieldDefinition[]>([]).asReadonly(),
    product: product.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    defaultUnitCode: signal<string | null>('C62').asReadonly(),
    loadProduct: vi.fn(),
    createProduct: vi.fn(),
    reviseProduct: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  const articleSettings = {
    rows: signal<readonly SettingRow[]>([]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    load: vi.fn(),
    save: vi.fn(),
    reset: vi.fn(),
    clearError: vi.fn(),
  };
  let fixture: ComponentFixture<ProductPage>;

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

  async function open(productId: string | undefined): Promise<void> {
    fixture = TestBed.createComponent(ProductPage);
    if (productId !== undefined) {
      fixture.componentRef.setInput('productId', productId);
    }
    await settle();
  }

  beforeEach(() => {
    error.set(null);
    product.set(null);
    optionsSignal.set(options);
    facade.loadProduct.mockReset().mockResolvedValue(undefined);
    facade.createProduct.mockReset().mockResolvedValue({ ...laptop, id: 'p9' });
    facade.reviseProduct.mockReset().mockResolvedValue(laptop);
    auth.hasPermission.mockReset().mockReturnValue(true);
    articleSettings.load.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [ProductPage],
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
        { provide: ArticleSettings, useValue: articleSettings },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('creates a product in the preselected unit, then opens it by its identifier', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadProduct).toHaveBeenCalledWith('c1', null);
    expect(q('article-defaults')).toBeNull();

    type('field-reference', 'ART-009');
    type('field-name', 'Souris');
    type('field-unitPriceNet', '25.5');
    q('product-save')!.click();
    await settle();

    expect(facade.createProduct).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        reference: 'ART-009',
        name: 'Souris',
        kind: 'goods',
        unitId: 'u1',
        unitPriceNet: '25.5',
        defaultTaxComponentIds: [],
      }),
    );
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/products', 'p9'], { replaceUrl: true }),
    );
  });

  it('does not send a price the API would refuse', async () => {
    await open(undefined);
    type('field-reference', 'ART-009');
    type('field-name', 'Souris');
    type('field-unitPriceNet', '25,5');
    q('product-save')!.click();
    await settle();

    expect(facade.createProduct).not.toHaveBeenCalled();
  });

  it('revises a product shown at the currency scale and says it was saved', async () => {
    product.set(laptop);
    await open('p1');
    expect(facade.loadProduct).toHaveBeenCalledWith('c1', 'p1');
    expect(articleSettings.load).toHaveBeenCalledWith('c1', { productId: 'p1' });
    expect(q('article-defaults')).not.toBeNull();
    expect(q('product-title')?.textContent).toContain('ART-001');
    expect((q('field-unitPriceNet') as HTMLInputElement).value).toBe('1250.500');

    type('field-unitPriceNet', '1300');
    q('product-save')!.click();
    await settle();

    expect(facade.reviseProduct).toHaveBeenCalledWith(
      'c1',
      'p1',
      expect.objectContaining({ unitPriceNet: '1300', defaultTaxComponentIds: ['t1'] }),
    );
    expect(successToasts()).toContain('products.saved');
  });

  it('keeps what was typed when the product and its options are read again', async () => {
    product.set(laptop);
    await open('p1');
    type('field-unitPriceNet', '1300');

    // What reading a product again gives: the same product and options, as new objects.
    product.set({ ...laptop });
    optionsSignal.set({ ...options, units: [...options.units], taxes: [...options.taxes] });
    await settle();
    q('product-save')!.click();
    await settle();

    expect(facade.reviseProduct).toHaveBeenCalledWith(
      'c1',
      'p1',
      expect.objectContaining({ unitPriceNet: '1300' }),
    );
  });

  it('shows another product when another one is opened', async () => {
    product.set(laptop);
    await open('p1');
    type('field-unitPriceNet', '1300');

    product.set({ ...laptop, id: 'p2', reference: 'ART-002', unitPriceNet: '10.0000' });
    fixture.componentRef.setInput('productId', 'p2');
    await settle();

    expect((q('field-unitPriceNet') as HTMLInputElement).value).toBe('10.000');
  });

  it('says why the API refused', async () => {
    error.set('reference_taken');
    await open(undefined);

    expect(q('product-error')?.textContent).toContain('porte déjà cette référence');
  });

  it('shows a reader the product without a way to change it', async () => {
    auth.hasPermission.mockReturnValue(false);
    product.set(laptop);
    await open('p1');

    expect(q('product-read-only')).not.toBeNull();
    expect(q('product-save')).toBeNull();
  });
});

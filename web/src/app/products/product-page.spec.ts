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
import { announceSaved } from '../shared/testing/live';
import { Feedback } from '../shared/feedback/feedback';
import type { RecordedFeedback } from '../shared/testing/feedback';

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
  barcodes: [],
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
    hasModule: vi.fn(),
  };
  const articleSettings = {
    rows: signal<readonly SettingRow[]>([]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
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

  /** A long record is in tabs, so reaching a section means opening its tab, as a person does. */
  async function openTab(label: string): Promise<void> {
    const tab = Array.from(
      fixture.nativeElement.querySelectorAll('[role="tab"]') as NodeListOf<HTMLElement>,
    ).find((candidate) => (candidate.textContent ?? '').includes(label));
    tab!.click();
    await settle();
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
    auth.hasModule.mockReset().mockReturnValue(true);
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

  // docs/SPEC.md § 7, 2026-09-19 21:55: a page names nothing it has not loaded.
  it('titles a product still loading as nothing, never as a new one', async () => {
    await open('p1');

    const title = q('product-title');
    expect(title?.textContent?.trim()).toBe('');
    expect(title?.getAttribute('aria-hidden')).toBe('true');
  });

  it('titles the page for a new product as new', async () => {
    await open(undefined);

    expect(q('product-title')?.textContent).toContain('products.new_title');
    expect(q('product-title')?.getAttribute('aria-hidden')).toBeNull();
  });

  it('creates the product a scanned code names, then opens its codes with that code listed', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    fixture = TestBed.createComponent(ProductPage);
    fixture.componentRef.setInput('barcode', '5449000000996');
    await settle();

    type('field-reference', 'ART-010');
    type('field-name', 'Soda');
    type('field-unitPriceNet', '2');
    await settle();
    q('record-save')!.click();
    await settle();

    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/products', 'p9'], {
        replaceUrl: true,
        queryParams: { tab: 'codes', add: '5449000000996' },
      }),
    );
  });

  it('creates a product in the preselected unit, then opens it by its identifier', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadProduct).toHaveBeenCalledWith('c1', null);
    expect(q('article-defaults')).toBeNull();

    type('field-reference', 'ART-009');
    type('field-name', 'Souris');
    type('field-unitPriceNet', '25.5');
    await settle();
    q('record-save')!.click();
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
    expect(successToasts()).toContain('products.saved');
  });

  it('does not send a price the API would refuse', async () => {
    await open(undefined);
    type('field-reference', 'ART-009');
    type('field-name', 'Souris');
    type('field-unitPriceNet', '25,12345');
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.createProduct).not.toHaveBeenCalled();
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: a French decimal comma is a price, sent as the API's point.
  it('sends a price typed with a decimal comma', async () => {
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    type('field-reference', 'ART-009');
    type('field-name', 'Souris');
    type('field-unitPriceNet', '25,5');
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.createProduct).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ unitPriceNet: '25.5' }),
    );
  });

  it('revises a product shown at the currency scale and says it was saved', async () => {
    product.set(laptop);
    await open('p1');
    expect(facade.loadProduct).toHaveBeenCalledWith('c1', 'p1');
    expect(articleSettings.load).toHaveBeenCalledWith('c1', { productId: 'p1' });
    expect(q('product-title')?.textContent).toContain('ART-001');
    expect((q('field-unitPriceNet') as HTMLInputElement).value).toBe('1250,500');

    type('field-unitPriceNet', '1300');
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.reviseProduct).toHaveBeenCalledWith(
      'c1',
      'p1',
      expect.objectContaining({ unitPriceNet: '1300', defaultTaxComponentIds: ['t1'] }),
    );
    expect(successToasts()).toContain('products.saved');

    // The defaults are their own panel, in their own tab, with their own save.
    await openTab('products.tabs.defaults');
    expect(q('article-defaults')).not.toBeNull();
  });

  /**
   * Where the product lives is its own tab, offered only for a product that exists and only to somebody who may
   * both write products and see the warehouse (docs/SPEC.md row 101): choosing a home means reading a list of
   * locations, and nobody points at a shelf they are not allowed to see.
   */
  it('offers where the product lives once it exists, to somebody who may see the warehouse', async () => {
    product.set(laptop);
    await open('p1');

    await openTab('products.tabs.homes');
    expect(q('product-homes')).not.toBeNull();
  });

  it('opens on the codes when the address asks for them, as a scan does', async () => {
    product.set(laptop);
    fixture = TestBed.createComponent(ProductPage);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('tab', 'codes');
    await settle();

    const selected = fixture.nativeElement.querySelector(
      '[role="tab"][aria-selected="true"]',
    ) as HTMLElement;
    expect(selected.textContent).toContain('products.tabs.barcodes');
  });

  it('offers it on no new product, without stock, and to nobody who may not read stock', async () => {
    await open(undefined);
    expect(q('product-tab-homes')).toBeNull();

    auth.hasModule.mockReturnValue(false);
    product.set(laptop);
    await open('p1');
    expect(q('product-tab-homes')).toBeNull();

    auth.hasModule.mockReturnValue(true);
    auth.hasPermission.mockImplementation((name: string) => name !== 'stock.read');
    await open('p1');
    expect(q('product-tab-homes')).toBeNull();
  });

  it('keeps what was typed when the product and its options are read again', async () => {
    product.set(laptop);
    await open('p1');
    type('field-unitPriceNet', '1300');

    // What reading a product again gives: the same product and options, as new objects.
    product.set({ ...laptop });
    optionsSignal.set({ ...options, units: [...options.units], taxes: [...options.taxes] });
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.reviseProduct).toHaveBeenCalledWith(
      'c1',
      'p1',
      expect.objectContaining({ unitPriceNet: '1300' }),
    );
  });

  it('takes what another person saved into the open product', async () => {
    product.set(laptop);
    await open('p1');
    facade.loadProduct.mockImplementation(async () => {
      product.set({ ...laptop, name: 'Portable 14 pouces' });
    });

    await announceSaved('product', 'p1');
    await settle();

    expect(facade.loadProduct).toHaveBeenLastCalledWith('c1', 'p1');
    expect((q('field-name') as HTMLInputElement).value).toBe('Portable 14 pouces');
    expect(q('record-changed')).toBeNull();
    expect(TestBed.inject(Feedback) as RecordedFeedback).toMatchObject({
      said: [{ kind: 'notice', key: 'live.notice', params: { name: 'Nadia' } }],
    });
  });

  it('shows another product when another one is opened', async () => {
    product.set(laptop);
    await open('p1');
    type('field-unitPriceNet', '1300');

    product.set({ ...laptop, id: 'p2', reference: 'ART-002', unitPriceNet: '10.0000' });
    fixture.componentRef.setInput('productId', 'p2');
    await settle();

    expect((q('field-unitPriceNet') as HTMLInputElement).value).toBe('10,000');
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
    expect(q('record-save')).toBeNull();
  });
});

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
import { ProductCategoriesPage } from './product-categories-page';
import type { SettingRow } from '../shared/settings/settings-types';
import { ArticleSettings } from './article-settings-facade';
import { ProductsFacade } from './products-facade';
import type { ProductCategoryRow, ProductsError } from './products-types';
import { provideQuietFeedback } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: {
        categories: { errors: { in_use: 'Cette catégorie contient encore des produits.' } },
      },
    });
  }
}

const hardware: ProductCategoryRow = {
  id: 'k1',
  name: 'Matériel',
  parentId: null,
  productCount: 0,
  childCount: 1,
};
const laptops: ProductCategoryRow = {
  id: 'k2',
  name: 'Portables',
  parentId: 'k1',
  productCount: 4,
  childCount: 0,
};

describe('ProductCategoriesPage', () => {
  const error = signal<ProductsError | null>(null);
  const categories = signal<readonly ProductCategoryRow[]>([hardware, laptops]);
  const facade = {
    categories: categories.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadCategories: vi.fn(),
    createCategory: vi.fn(),
    reviseCategory: vi.fn(),
    deleteCategory: vi.fn(),
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
  let fixture: ComponentFixture<ProductCategoriesPage>;

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

  beforeEach(async () => {
    error.set(null);
    categories.set([hardware, laptops]);
    facade.loadCategories.mockReset().mockResolvedValue(undefined);
    facade.createCategory.mockReset().mockResolvedValue(true);
    facade.reviseCategory.mockReset().mockResolvedValue(true);
    facade.deleteCategory.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    articleSettings.load.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [ProductCategoriesPage],
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
    fixture = TestBed.createComponent(ProductCategoriesPage);
    await settle();
  });

  it('lists the categories by their path with what each holds', () => {
    expect(facade.loadCategories).toHaveBeenCalledWith('c1');
    const row = q('product-category-Portables')?.textContent ?? '';
    expect(row).toContain('Matériel › Portables');
    expect(row).toContain('4');
  });

  it('keeps what was typed when the categories arrive after the form was opened', async () => {
    q('product-category-add')!.click();
    await settle();
    type('field-name', 'Services');

    // What a slow first read gives: the list, and so the parents offered, arrive once the form is open.
    categories.set([hardware, laptops, { ...laptops, id: 'k3', name: 'Écrans' }]);
    await settle();
    q('product-category-save')!.click();
    await settle();

    expect(facade.createCategory).toHaveBeenCalledWith('c1', { name: 'Services', parentId: null });
  });

  it('leads back to the products through the tabs of the products screens', () => {
    expect(q('products-tab')?.getAttribute('href')).toBe('/products');
    expect(q('product-categories-link')?.closest('nav')).not.toBeNull();
  });

  it('adds a category at the top of the tree', async () => {
    q('product-category-add')!.click();
    await settle();
    type('field-name', 'Services');
    q('product-category-save')!.click();
    await settle();

    expect(facade.createCategory).toHaveBeenCalledWith('c1', { name: 'Services', parentId: null });
  });

  it('renames a category keeping its parent, and deletes one by its identifier', async () => {
    q('product-category-edit-Portables')!.click();
    await settle();
    type('field-name', 'Ordinateurs portables');
    q('product-category-save')!.click();
    await settle();
    expect(facade.reviseCategory).toHaveBeenCalledWith('c1', 'k2', {
      name: 'Ordinateurs portables',
      parentId: 'k1',
    });

    q('product-category-delete-Portables')!.click();
    await settle();
    expect(facade.deleteCategory).toHaveBeenCalledWith('c1', 'k2');
  });

  it("shows a category's defaults while it is edited, and none for a new one", async () => {
    q('product-category-add')!.click();
    await settle();
    expect(q('article-defaults')).toBeNull();
    q('product-category-cancel')!.click();
    await settle();

    q('product-category-edit-Portables')!.click();
    await settle();
    expect(q('article-defaults')).not.toBeNull();
    expect(articleSettings.load).toHaveBeenCalledWith('c1', { productCategoryId: 'k2' });
  });

  it('says a category still in use cannot go', async () => {
    error.set('in_use');
    await settle();

    expect(q('product-categories-error')?.textContent).toContain('encore des produits');
  });

  it('offers no change to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(ProductCategoriesPage);
    await settle();

    expect(q('product-category-add')).toBeNull();
    expect(q('product-category-edit-Portables')).toBeNull();
    expect(q('product-category-delete-Portables')).toBeNull();
  });
});

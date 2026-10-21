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
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { RecordDeletes } from '../shared/records/record-deletes';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: {
        categories: {
          delete_named: 'Supprimer {{name}}',
        },
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
  version: 1,
};
const laptops: ProductCategoryRow = {
  id: 'k2',
  name: 'Portables',
  parentId: 'k1',
  productCount: 4,
  childCount: 0,
  version: 1,
};
const archives: ProductCategoryRow = {
  id: 'k3',
  name: 'Archives',
  parentId: null,
  productCount: 0,
  childCount: 0,
  version: 1,
};

describe('ProductCategoriesPage', () => {
  const error = signal<ProductsError | null>(null);
  const categories = signal<readonly ProductCategoryRow[]>([hardware, laptops, archives]);
  const facade = {
    categories: categories.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadCategories: vi.fn(),
    createCategory: vi.fn(),
    reviseCategory: vi.fn(),
    clearError: vi.fn(),
  };
  const deletes = { remove: vi.fn(), offerMerge: vi.fn() };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
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
  let fixture: ComponentFixture<ProductCategoriesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  // A menu opens in the CDK overlay, which hangs off the body rather than the component.
  const inMenu = (testId: string): HTMLElement | null =>
    document.body.querySelector(
      `.cdk-overlay-container [data-testid="${testId}"]`,
    ) as HTMLElement | null;

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
    categories.set([hardware, laptops, archives]);
    error.set(null);
    facade.loadCategories.mockReset().mockResolvedValue(undefined);
    facade.clearError.mockReset().mockImplementation(() => error.set(null));
    facade.createCategory.mockReset().mockResolvedValue(true);
    facade.reviseCategory.mockReset().mockResolvedValue(true);
    deletes.remove.mockReset().mockResolvedValue(true);
    deletes.offerMerge.mockReset().mockResolvedValue(true);
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
        { provide: RecordDeletes, useValue: deletes },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(ProductCategoriesPage);
    await settle();
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  const tick = async (id: string): Promise<void> => {
    (q(`list-select-row-${id}`)!.querySelector('input') as HTMLInputElement).click();
    await settle();
  };

  it('offers to merge once two categories are ticked, saying what each holds, and clears the ticks after', async () => {
    await tick('k2');
    expect(q('product-categories-merge')).toBeNull();
    await tick('k1');

    q('product-categories-merge')!.click();
    await settle();

    expect(deletes.offerMerge).toHaveBeenCalledWith('c1', {
      subject: 'product_category',
      candidates: [
        {
          id: 'k2',
          label: 'Portables',
          moves: [
            { table: 'product', count: 4 },
            { table: 'product_category', count: 0 },
          ],
        },
        {
          id: 'k1',
          label: 'Matériel',
          moves: [
            { table: 'product', count: 0 },
            { table: 'product_category', count: 1 },
          ],
        },
      ],
      settings: true,
    });
    expect(q('product-categories-merge')).toBeNull();
  });

  it('lets nobody but a person who may change categories tick them', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(ProductCategoriesPage);
    await settle();
    expect(q('list-select-row-k1')).toBeNull();
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
    fixture.detectChanges();
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
    fixture.detectChanges();
    q('product-category-save')!.click();
    await settle();

    expect(facade.createCategory).toHaveBeenCalledWith('c1', { name: 'Services', parentId: null });
  });

  it('renames a category keeping its parent, and deletes one without asking', async () => {
    q('row-action-edit-k2')!.click();
    await settle();
    type('field-name', 'Ordinateurs portables');
    fixture.detectChanges();
    q('product-category-save')!.click();
    await settle();
    expect(facade.reviseCategory).toHaveBeenCalledWith(
      'c1',
      'k2',
      {
        name: 'Ordinateurs portables',
        parentId: 'k1',
      },
      1,
    );

    // Deleting is destructive, so it sits behind "⋮" rather than under the pointer.
    q('row-more-k3')!.click();
    await settle();
    // The menu names the row it deletes, as the customer groups' does.
    expect(inMenu('row-menu-delete-k3')?.textContent).toContain('Supprimer Archives');
    // Asks nothing: the toast offers « Annuler » and « Récemment supprimés » keeps it a day.
    expect(inMenu('row-menu-delete-k3')?.getAttribute('data-kind')).toBe('annulable');
    inMenu('row-menu-delete-k3')!.click();
    await settle();
    expect(document.querySelector('[data-testid="confirm-message"]')).toBeNull();
    expect(deletes.remove).toHaveBeenCalledWith('c1', 'product_category', [
      { id: 'k3', label: 'Archives' },
    ]);
  });

  it('after a save refused as made over a version saved since, merges what was saved, says nothing was, and saves on it next', async () => {
    q('row-action-edit-k2')!.click();
    await settle();
    facade.reviseCategory.mockImplementationOnce(async () => {
      error.set('record_changed');
      return false;
    });
    facade.loadCategories.mockImplementationOnce(async () =>
      categories.set([hardware, { ...laptops, parentId: null, version: 2 }, archives]),
    );

    type('field-name', 'Ordinateurs portables');
    fixture.detectChanges();
    q('product-category-save')!.click();
    await settle();
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
        kind: 'failure',
        key: 'live.saved_since',
        params: undefined,
      }),
    );

    expect((q('field-name') as HTMLInputElement).value).toBe('Ordinateurs portables');
    expect(q('product-categories-error')).toBeNull();

    fixture.detectChanges();

    q('product-category-save')!.click();
    await settle();
    expect(facade.reviseCategory).toHaveBeenLastCalledWith(
      'c1',
      'k2',
      { name: 'Ordinateurs portables', parentId: null },
      2,
    );
  });

  it('takes into the open form what another person saved on the same category', async () => {
    q('row-action-edit-k2')!.click();
    await settle();
    facade.loadCategories.mockImplementationOnce(async () =>
      categories.set([hardware, { ...laptops, name: 'Ordinateurs', version: 2 }, archives]),
    );

    await announceSaved('product_category', 'k2');
    await settle();

    expect((q('field-name') as HTMLInputElement).value).toBe('Ordinateurs');
  });

  it('offers no delete for a category that still holds products or sub-categories', () => {
    // The page says such a category cannot go; offering it only to be refused contradicted that (sweep 2026-10-09).
    expect(q('row-more-k2')).toBeNull();
    expect(q('row-more-k1')).toBeNull();
    expect(q('row-more-k3')).not.toBeNull();
  });

  it("shows a category's defaults while it is edited, and none for a new one", async () => {
    q('product-category-add')!.click();
    await settle();
    expect(q('article-defaults')).toBeNull();
    q('product-category-cancel')!.click();
    await settle();

    q('row-action-edit-k2')!.click();
    await settle();
    expect(q('article-defaults')).not.toBeNull();
    expect(articleSettings.load).toHaveBeenCalledWith('c1', { productCategoryId: 'k2' });
  });

  it('offers the file of categories to a writer, the form open or not', async () => {
    expect(q('product-categories-import')?.getAttribute('href')).toBe(
      '/imports/product-categories',
    );
    q('product-category-add')?.click();
    await settle();
    expect(q('product-categories-import')).not.toBeNull();
  });

  it('offers no change to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(ProductCategoriesPage);
    await settle();

    expect(q('product-categories-import')).toBeNull();
    expect(q('product-category-add')).toBeNull();
    expect(q('row-action-edit-k2')).toBeNull();
    expect(q('row-more-k2')).toBeNull();
  });
});

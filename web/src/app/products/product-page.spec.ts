// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DOCUMENT, signal } from '@angular/core';
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
import type { FieldRefusal } from '../shared/form/field-refusals';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { ScreenActions } from '../shared/actions/screen-actions';
import { By } from '@angular/platform-browser';
import { ProductBarcodesSection } from './product-barcodes';
import { ProductHomesSection } from './product-homes';
import { ProductReorderPointsSection } from './product-reorder-points';
import { ProductPage } from './product-page';
import { ProductOnView } from './product-on-view';
import type { SettingRow } from '../shared/settings/settings-types';
import { RecordDeletes } from '../shared/records/record-deletes';
import { ArticleSettings } from './article-settings-facade';
import { PRICE_PREVIEW_DELAY } from './price-calculator';
import { ProductsFacade } from './products-facade';
import type {
  ProductCategoryRow,
  ProductOptions,
  ProductRow,
  ProductsError,
} from './products-types';
import { effectToasts, provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { Feedback } from '../shared/feedback/feedback';
import { AddToDocuments } from '../shared/documents/add-to-documents';
import type { RecordedFeedback } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: { errors: { reference_taken: 'Un autre produit porte déjà cette référence.' } },
      import: { rejections: { invalid_text: 'Texte refusé : {{max}} caractères au plus.' } },
    });
  }
}

const options: ProductOptions = {
  currency: 'TND',
  currencyScale: 3,
  units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
  taxes: [{ id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat', isDefault: true }],
  photosPerProduct: 6,
  photoMaxBytes: 5242880,
  checkReasons: [],
};
const laptop: ProductRow = {
  id: 'p1',
  version: 1,
  reference: 'ART-001',
  name: 'Portable 14"',
  description: null,
  kind: 'goods',
  unitId: 'u1',
  unitPriceNet: '1250.5000',
  costPrice: null,
  unitPriceGross: null,
  margin: null,
  marginPercent: null,
  categoryId: null,
  barcodes: [],
  defaultTaxComponentIds: ['t1'],
  isActive: true,
  customFields: {},
  tracking: 'none',
  substitutionGroup: null,
  mainPhotoId: null,
  priceReview: null,
  toCheck: false,
  checkNote: null,
  zeroPriceIntended: false,
  checkReasons: [],
  mergedInto: null,
  vendorIds: [],
  vendors: [],
};

describe('ProductPage', () => {
  const addTo = { kinds: vi.fn(() => ['invoice']), open: vi.fn(async () => undefined) };
  const error = signal<ProductsError | null>(null);
  const refusals = signal<readonly FieldRefusal[]>([]);
  const product = signal<ProductRow | null>(null);
  const optionsSignal = signal<ProductOptions | null>(options);
  const categories = signal<readonly ProductCategoryRow[]>([]);
  const facade = {
    options: optionsSignal.asReadonly(),
    categories: categories.asReadonly(),
    customFields: signal<readonly CustomFieldDefinition[]>([]).asReadonly(),
    product: product.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    refusals: refusals.asReadonly(),
    defaultUnitCode: signal<string | null>('C62').asReadonly(),
    defaultTracking: signal<'none' | 'lot' | 'serial'>('none').asReadonly(),
    loadProduct: vi.fn(),
    substitutionGroups: signal<readonly string[]>(['Vis 6 mm', 'Chevilles']).asReadonly(),
    loadSubstitutionGroups: vi.fn(),
    createProduct: vi.fn(),
    reviseProduct: vi.fn(),
    clearError: vi.fn(),
    pricePreview: vi.fn(),
    referencePreview: vi.fn(),
    settlePriceReview: vi.fn(),
    pickVendors: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
    hasModule: vi.fn(),
  };
  const deletes = { remove: vi.fn() };
  const articleSettings = {
    rows: signal<readonly SettingRow[]>([]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    refusals: signal([]).asReadonly(),
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
    refusals.set([]);
    product.set(null);
    optionsSignal.set(options);
    facade.loadProduct.mockReset().mockResolvedValue(undefined);
    facade.loadSubstitutionGroups.mockReset().mockResolvedValue(undefined);
    facade.createProduct.mockReset().mockResolvedValue({ ...laptop, id: 'p9' });
    facade.reviseProduct.mockReset().mockResolvedValue(laptop);
    facade.pricePreview.mockReset().mockResolvedValue(null);
    facade.referencePreview.mockReset().mockResolvedValue(null);
    facade.settlePriceReview.mockReset();
    facade.pickVendors.mockReset().mockResolvedValue([]);
    categories.set([]);
    auth.hasPermission.mockReset().mockReturnValue(true);
    auth.hasModule.mockReset().mockReturnValue(true);
    deletes.remove.mockReset().mockResolvedValue(true);
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
        { provide: PRICE_PREVIEW_DELAY, useValue: 0 },
        { provide: ArticleSettings, useValue: articleSettings },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: RecordDeletes, useValue: deletes },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        // Customer view is remembered per tab: on the real session storage it outlives this spec and hides columns in others.
      ],
    });
    // The page provides « Ajouter à… » itself, so the stub replaces its own provider.
    TestBed.overrideComponent(ProductPage, {
      add: { providers: [{ provide: AddToDocuments, useValue: addTo }] },
    });
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  // docs/SPEC.md § 7, 2026-09-23 slice 8: a saved product's labels open in a tab of their own, to print.
  it('puts the person on the first field in error when the bar saves a form that cannot be sent', async () => {
    await open(undefined);
    await settle();
    type('field-reference', 'ART-010');
    await settle();
    q('record-save')!.click();
    await settle();

    const focused = document.activeElement as HTMLElement;
    expect(focused.getAttribute('data-testid')).toMatch(/^field-/);
    fixture.detectChanges();
    // Its error is shown and is what is said with it.
    expect(focused.classList).toContain('ng-invalid');
    expect(focused.getAttribute('aria-describedby')).toMatch(/mat-mdc-error/);
  });

  /** The entry « ⋮ » lists for an action, the menu opened first. */
  async function menuEntry(id: string): Promise<HTMLElement | null> {
    q('record-more')?.click();
    await fixture.whenStable();
    fixture.detectChanges();
    return document.body.querySelector<HTMLElement>(`[data-testid="record-menu-${id}"]`);
  }

  // docs/SPEC.md § 7, cost and price: a margin a receipt moved stays on the product until someone settles it.
  it('shows the margin a receipt moved and settles it on the version read, said corrigeable', async () => {
    const review = {
      previousCost: '1000.0000',
      cost: '1100.0000',
      price: '1250.0000',
      suggestedPrice: '1375.000',
      previousMargin: '20.0',
      margin: '12.0',
    };
    product.set({ ...laptop, priceReview: review });
    facade.settlePriceReview.mockResolvedValue({
      ...laptop,
      version: 2,
      unitPriceNet: '1375.0000',
      priceReview: null,
      toCheck: false,
      checkNote: null,
      zeroPriceIntended: false,
      checkReasons: [],
    });
    await open(laptop.id);
    expect(q('product-price-review')).not.toBeNull();

    q('product-price-review-adjust')!.click();
    await settle();

    expect(facade.settlePriceReview).toHaveBeenCalledWith('c1', 'p1', 'adjust', laptop.version);
    // The form stands on the version the review left first, then the outcome is said.
    await vi.waitFor(() =>
      expect(effectToasts()).toEqual(['products.price_review.adjusted:corrigeable']),
    );
  });

  it('names who a product is bought from, finds another in the vendor book, and saves exactly those', async () => {
    product.set({
      ...laptop,
      vendorIds: ['v1'],
      vendors: [{ id: 'v1', number: 'FRN-0001', name: 'Comptoir Sfax' }],
    });
    facade.pickVendors.mockResolvedValue([{ id: 'v2', number: 'FRN-0002', name: 'Sotumag' }]);
    await open(laptop.id);
    expect(q('field-vendorIds-held-v1')?.textContent?.trim()).toBe('FRN-0001 · Comptoir Sfax');

    q('field-vendorIds')!.dispatchEvent(new Event('focusin'));
    await settle();
    const offered = Array.from(document.body.querySelectorAll<HTMLElement>('mat-option')).find(
      (each) => each.textContent?.trim() === 'FRN-0002 · Sotumag',
    );
    expect(facade.pickVendors).toHaveBeenCalledWith('c1', expect.objectContaining({ words: '' }));
    offered!.click();
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.reviseProduct).toHaveBeenCalledWith(
      'c1',
      'p1',
      expect.objectContaining({ vendorIds: ['v1', 'v2'] }),
      laptop.version,
    );
  });

  it('leaves who a product is bought from alone while the company keeps no vendor book', async () => {
    auth.hasModule.mockImplementation((module: string) => module !== 'vendors');
    product.set({
      ...laptop,
      vendorIds: ['v1'],
      vendors: [{ id: 'v1', number: 'FRN-0001', name: 'Sfax' }],
    });
    await open(laptop.id);
    expect(q('field-vendorIds-held')).toBeNull();
    expect(q('field-vendorIds')).toBeNull();

    type('field-name', 'Portable 15"');
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.reviseProduct).toHaveBeenCalledWith(
      'c1',
      'p1',
      expect.objectContaining({ vendorIds: null }),
      laptop.version,
    );
  });

  it('shows no margin notice on a product no receipt moved', async () => {
    product.set(laptop);
    await open(laptop.id);

    expect(q('product-price-review')).toBeNull();
  });

  it('names what a product lacks and the mark put on it, with its note', async () => {
    product.set({
      ...laptop,
      toCheck: true,
      checkNote: 'Prix à confirmer',
      checkReasons: ['vendor_missing', 'to_count'],
    });
    await open(laptop.id);

    expect(q('product-check-marked')?.textContent).toContain('check.marked');
    expect(q('product-check-note')?.textContent).toContain('Prix à confirmer');
    const reasons = [...(q('product-check-reasons')?.querySelectorAll('li') ?? [])];
    expect(reasons.map((each) => each.getAttribute('data-reason'))).toEqual([
      'vendor_missing',
      'to_count',
    ]);
    expect(reasons.map((each) => each.textContent?.trim())).toEqual([
      'check.reasons.vendor_missing',
      'check.reasons.to_count',
    ]);
  });

  it('shows nothing to check on a product that lacks nothing and is not marked', async () => {
    product.set(laptop);
    await open(laptop.id);

    expect(q('product-check')).toBeNull();
  });

  it('only shows the moved margin to someone who may not write products', async () => {
    auth.hasPermission.mockImplementation((name: string) => name !== 'product.write');
    product.set({
      ...laptop,
      priceReview: {
        previousCost: '1000.0000',
        cost: '1100.0000',
        price: '1250.0000',
        suggestedPrice: '1375.000',
        previousMargin: '20.0',
        margin: '12.0',
      },
    });
    await open(laptop.id);

    expect(q('product-price-review')).not.toBeNull();
    expect(q('product-price-review-adjust')).toBeNull();
  });

  it('deletes the product without asking, said annulable, and goes back to the list once it is gone', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    product.set(laptop);
    await open(laptop.id);

    const entry = await menuEntry('delete');
    expect(entry?.getAttribute('data-kind')).toBe('annulable');
    entry!.click();
    await settle();

    expect(auth.hasPermission).toHaveBeenCalledWith('product.write');
    expect(deletes.remove).toHaveBeenCalledWith('c1', 'product', [
      { id: 'p1', label: 'ART-001 Portable 14"' },
    ]);
    expect(navigate).toHaveBeenCalledWith(['/products']);
  });

  // One page per case: a case opening it twice outran Vitest's five seconds beside a gate.
  it('stays on the product when the delete is refused', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    deletes.remove.mockResolvedValue(false);
    product.set(laptop);
    await open(laptop.id);
    (await menuEntry('delete'))!.click();
    await settle();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('offers no delete to whoever may not write products', async () => {
    auth.hasPermission.mockImplementation((permission: string) => permission !== 'product.write');
    product.set(laptop);
    await open(laptop.id);
    expect(await menuEntry('delete')).toBeNull();
  });

  it('offers no delete while a new product is filled in', async () => {
    await open(undefined);
    expect(await menuEntry('delete')).toBeNull();
  });

  it("offers a saved product's labels, opened in a new tab", async () => {
    const view = TestBed.inject(DOCUMENT).defaultView!;
    const opened = vi.spyOn(view, 'open').mockReturnValue(null);
    product.set(laptop);
    await open(laptop.id);
    const labels = TestBed.inject(ScreenActions)
      .actions()
      .find((each) => each.id === 'labels');
    expect(labels).toMatchObject({ rare: true, shown: true });
    labels!.run!();
    expect(opened).toHaveBeenCalledWith(`/print/product-labels/${laptop.id}`, '_blank');
  });

  it('offers « Ajouter à… » on a saved product, which goes to the document chosen', async () => {
    addTo.kinds.mockReturnValue(['invoice']);
    addTo.open.mockClear();
    product.set(laptop);
    await open(laptop.id);
    const addToAction = TestBed.inject(ScreenActions)
      .actions()
      .find((each) => each.id === 'add_to');
    expect(addToAction).toMatchObject({ label: 'documents.add_to.action', shown: true });
    addToAction!.run!();
    expect(addTo.open).toHaveBeenCalledExactlyOnceWith([laptop.id]);
  });

  it('offers no « Ajouter à… » on a product not saved yet, or to someone who may write no document', async () => {
    addTo.kinds.mockReturnValue(['invoice']);
    await open(undefined);
    expect(
      TestBed.inject(ScreenActions)
        .actions()
        .map((each) => each.id),
    ).not.toContain('add_to');

    fixture.destroy();
    addTo.kinds.mockReturnValue([]);
    product.set(laptop);
    await open(laptop.id);
    expect(
      TestBed.inject(ScreenActions)
        .actions()
        .map((each) => each.id),
    ).not.toContain('add_to');
  });

  // Round 2: every action where its subject appears. Its stock is moved from the product, not only from Stock.
  it('offers its stock and each movement of it, opening Stock’s form already naming it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    product.set(laptop);
    await open(laptop.id);
    const actions = TestBed.inject(ScreenActions).actions();
    const action = (id: string) => actions.find((each) => each.id === id);
    expect(actions.map((each) => each.id)).toEqual(
      expect.arrayContaining(['stock', 'stock_receive', 'stock_count', 'stock_move', 'stock_loss']),
    );

    action('stock_count')!.run!();
    expect(navigate).toHaveBeenLastCalledWith(['/stock'], {
      queryParams: { operation: 'count', product: laptop.id },
    });
    action('stock')!.run!();
    expect(navigate).toHaveBeenLastCalledWith(['/stock'], { queryParams: { product: laptop.id } });
  });

  it('offers no stock of a service, nor its movements to whoever only reads stock', async () => {
    product.set({ ...laptop, kind: 'service' });
    await open(laptop.id);
    let ids = TestBed.inject(ScreenActions)
      .actions()
      .map((each) => each.id);
    expect(ids).toContain('save');
    expect(ids).not.toContain('stock');

    fixture.destroy();
    auth.hasPermission.mockImplementation((permission: string) => permission !== 'stock.write');
    product.set(laptop);
    await open(laptop.id);
    ids = TestBed.inject(ScreenActions)
      .actions()
      .map((each) => each.id);
    expect(ids).toContain('stock');
    expect(ids).not.toContain('stock_receive');
  });

  it('offers no labels for a product not saved yet', async () => {
    await open(undefined);
    // The bar offers only what is shown, so the page's other actions prove the declaration was read.
    const ids = TestBed.inject(ScreenActions)
      .actions()
      .map((each) => each.id);
    expect(ids).toContain('save');
    expect(ids).not.toContain('labels');
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
        defaultTaxComponentIds: ['t1'],
      }),
    );
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/products', 'p9'], { replaceUrl: true }),
    );
    expect(successToasts()).toContain('products.saved');
  });

  // docs/SPEC.md § 7, 2026-09-17 (3): a reference left as proposed is given at save, the next free one.
  describe('the reference a new product is given', () => {
    async function fillAndSave(): Promise<void> {
      type('field-name', 'Souris');
      type('field-unitPriceNet', '25.5');
      await settle();
      q('record-save')!.click();
      await settle();
    }

    /** A Select as a person uses it: opened, then the option taken. Its panel hangs off the body. */
    async function choose(id: string, value: string): Promise<void> {
      q(id)!.click();
      await settle();
      const option = Array.from(
        document.body.querySelectorAll<HTMLElement>('[role="option"]'),
      ).find((each) => each.textContent?.includes(value));
      expect(option, `${id} offers ${value}`).toBeDefined();
      option!.click();
      await settle();
    }

    beforeEach(() => {
      vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      facade.referencePreview.mockResolvedValue('ART-00042');
    });

    it('shows the proposal, counts it as nothing unsaved, and sends it empty when kept', async () => {
      facade.createProduct.mockResolvedValue({ ...laptop, id: 'p9', reference: 'ART-00042' });
      await open(undefined);

      expect(facade.referencePreview).toHaveBeenCalledWith('c1', null);
      expect((q('field-reference') as HTMLInputElement).value).toBe('ART-00042');
      expect(q('record-save')!.hasAttribute('disabled')).toBe(true);

      await fillAndSave();
      expect(facade.createProduct).toHaveBeenCalledWith(
        'c1',
        expect.objectContaining({ reference: '', name: 'Souris' }),
      );
      expect(successToasts()).toEqual(['products.saved']);
    });

    it('names the reference given when another save took the one shown', async () => {
      facade.createProduct.mockResolvedValue({ ...laptop, id: 'p9', reference: 'ART-00043' });
      await open(undefined);
      await fillAndSave();

      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual(
        expect.objectContaining({
          kind: 'success',
          key: 'products.saved_as',
          params: { given: 'ART-00043', shown: 'ART-00042' },
        }),
      );
    });

    it('keeps a reference the person typed, through a change of category too', async () => {
      categories.set([
        {
          id: 'cat1',
          name: 'Boulonnerie',
          parentId: null,
          productCount: 0,
          childCount: 0,
          version: 1,
        },
      ]);
      await open(undefined);
      type('field-reference', 'VIS-6X40');
      await settle();
      facade.referencePreview.mockResolvedValue('BOI-0001');
      await choose('field-categoryId', 'Boulonnerie');

      expect(facade.referencePreview).toHaveBeenLastCalledWith('c1', 'cat1');
      expect((q('field-reference') as HTMLInputElement).value).toBe('VIS-6X40');
      await fillAndSave();
      expect(facade.createProduct).toHaveBeenCalledWith(
        'c1',
        expect.objectContaining({ reference: 'VIS-6X40', categoryId: 'cat1' }),
      );
      expect(successToasts()).toEqual(['products.saved']);
    });

    it("replaces the proposal with the category's own while nobody changed it", async () => {
      categories.set([
        {
          id: 'cat1',
          name: 'Boulonnerie',
          parentId: null,
          productCount: 0,
          childCount: 0,
          version: 1,
        },
      ]);
      await open(undefined);
      facade.referencePreview.mockResolvedValue('BOI-0001');
      await choose('field-categoryId', 'Boulonnerie');

      expect((q('field-reference') as HTMLInputElement).value).toBe('BOI-0001');
    });

    it('asks nothing on a product that exists, nor for somebody who may only read', async () => {
      product.set(laptop);
      await open('p1');
      expect((q('field-reference') as HTMLInputElement).value).toBe('ART-001');
      expect(facade.referencePreview).not.toHaveBeenCalled();
    });
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

  /** Joining a group is typing its name as it is already written, so the form offers the names in use. */
  it('offers the substitution groups in use as the group is typed', async () => {
    product.set(laptop);
    await open('p1');
    expect(facade.loadSubstitutionGroups).toHaveBeenCalledWith('c1');

    const field = q('field-substitutionGroup') as HTMLInputElement;
    field.dispatchEvent(new Event('focusin'));
    type('field-substitutionGroup', 'vis');
    await settle();
    expect(
      Array.from(document.body.querySelectorAll('mat-option')).map((each) =>
        each.textContent?.trim(),
      ),
    ).toEqual(['Vis 6 mm']);
  });

  it('asks for no groups for somebody who may only read the product', async () => {
    auth.hasPermission.mockReturnValue(false);
    product.set(laptop);
    await open('p1');

    expect(facade.loadSubstitutionGroups).not.toHaveBeenCalled();
  });

  it('after a save refused as made over a version saved since, merges what was saved, says nothing was, and saves on it next', async () => {
    product.set(laptop);
    await open('p1');
    facade.reviseProduct.mockImplementationOnce(async () => {
      error.set('record_changed');
      return null;
    });
    facade.clearError.mockImplementationOnce(() => error.set(null));
    facade.loadProduct.mockImplementationOnce(async () =>
      product.set({ ...laptop, version: 2, name: 'Portable 15"' }),
    );

    type('field-unitPriceNet', '1300');
    await settle();
    q('record-save')!.click();
    await settle();
    await vi.waitFor(() =>
      expect((q('field-name') as HTMLInputElement).value).toBe('Portable 15"'),
    );

    expect((q('field-unitPriceNet') as HTMLInputElement).value).toBe('1300');
    expect(q('product-error')).toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });

    q('record-save')!.click();
    await settle();
    expect(facade.reviseProduct).toHaveBeenLastCalledWith(
      'c1',
      'p1',
      expect.objectContaining({ unitPriceNet: '1300', name: 'Portable 15"' }),
      2,
    );
  });

  // The codes and the homes are shown beside the form and move the product's version.
  it('stands on the version a codes save left, keeping what is typed, and saves on it next', async () => {
    product.set(laptop);
    await open('p1');
    type('field-unitPriceNet', '1300');
    await settle();
    facade.loadProduct.mockImplementationOnce(async () => product.set({ ...laptop, version: 2 }));

    await openTab('products.tabs.barcodes');
    const codes = fixture.debugElement.query(By.directive(ProductBarcodesSection));
    (codes.componentInstance as ProductBarcodesSection).moved.emit('saved');
    await settle();

    expect(facade.loadProduct).toHaveBeenLastCalledWith('c1', 'p1');
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).not.toContainEqual(
      expect.objectContaining({ key: 'live.saved_since' }),
    );
    q('record-save')!.click();
    await settle();
    expect(facade.reviseProduct).toHaveBeenLastCalledWith(
      'c1',
      'p1',
      expect.objectContaining({ unitPriceNet: '1300' }),
      2,
    );
  });

  it('reads the product again and says nothing was saved when the homes were refused as made over a version saved since', async () => {
    product.set(laptop);
    await open('p1');
    facade.loadProduct.mockImplementationOnce(async () => product.set({ ...laptop, version: 3 }));

    await openTab('products.tabs.homes');
    const homes = fixture.debugElement.query(By.directive(ProductHomesSection));
    (homes.componentInstance as ProductHomesSection).moved.emit('changed');
    await settle();

    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });
    expect((homes.componentInstance as ProductHomesSection).version()).toBe(3);
  });

  it('reads the product again and says nothing was saved when a reorder point was refused as made over a version saved since', async () => {
    product.set(laptop);
    await open('p1');
    facade.loadProduct.mockImplementationOnce(async () => product.set({ ...laptop, version: 3 }));

    await openTab('products.tabs.homes');
    const points = fixture.debugElement.query(By.directive(ProductReorderPointsSection));
    expect((points.componentInstance as ProductReorderPointsSection).version()).toBe(1);
    (points.componentInstance as ProductReorderPointsSection).moved.emit('changed');
    await settle();

    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });
    expect((points.componentInstance as ProductReorderPointsSection).version()).toBe(3);
  });

  // Its reorder points are audited under their own kind, and their change moves the product's version.
  it('reads the product again when another person changed its reorder points', async () => {
    product.set(laptop);
    await open('p1');
    facade.loadProduct.mockClear();

    await announceSaved('product_reorder_point', 'p1', undefined, 'product_reorder_point.set');
    await settle();

    expect(facade.loadProduct).toHaveBeenLastCalledWith('c1', 'p1');
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
      1,
    );
    expect(successToasts()).toContain('products.saved');

    // The defaults are their own panel, in their own tab, with their own save.
    await openTab('products.tabs.defaults');
    expect(q('article-defaults')).not.toBeNull();
  });

  it('says on its field what a save was refused for, and puts the person on it', async () => {
    product.set(laptop);
    await open('p1');
    facade.reviseProduct.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([{ field: 'name', reason: 'invalid_text', params: { max: 200 } }]);
      return null;
    });
    type('field-name', 'Portable 15"');
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.reviseProduct).toHaveBeenCalled();
    expect(q('field-error-name')?.textContent).toContain('Texte refusé : 200 caractères au plus.');
    expect(document.activeElement).toBe(q('field-name'));
    expect(successToasts()).not.toContain('products.saved');
  });

  it('says on its field what the creation of a product was refused for', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    facade.createProduct.mockImplementationOnce(async () => {
      error.set('invalid');
      refusals.set([{ field: 'name', reason: 'invalid_text', params: { max: 200 } }]);
      return null;
    });
    type('field-reference', 'ART-009');
    type('field-name', 'Souris');
    type('field-unitPriceNet', '25.5');
    await settle();
    q('record-save')!.click();
    await settle();

    expect(q('field-error-name')?.textContent).toContain('Texte refusé : 200 caractères au plus.');
    expect(navigate).not.toHaveBeenCalled();
  });

  // docs/SPEC.md § 7, 2026-09-25 10:13: the scan card offers a code nobody holds to the product on view.
  it('names the saved product it shows to the scan card, and nothing once it goes', async () => {
    const onView = TestBed.inject(ProductOnView);
    await open(undefined);
    expect(onView.product()).toBeNull();
    fixture.destroy();

    product.set(laptop);
    await open('p1');
    expect(onView.product()).toEqual({ id: 'p1', reference: 'ART-001' });

    fixture.destroy();
    expect(onView.product()).toBeNull();
  });

  // CI e1b629d5: the defaults tab opened while a save was still on its way snapped back to the record when it answered.
  it('keeps the tab opened while a save was on its way when the save answers', async () => {
    product.set(laptop);
    await open('p1');
    let answer!: (row: ProductRow) => void;
    facade.reviseProduct.mockImplementation(
      () =>
        new Promise<ProductRow>((resolve) => {
          answer = (row) => {
            product.set(row);
            resolve(row);
          };
        }),
    );

    type('field-unitPriceNet', '1300');
    await settle();
    q('record-save')!.click();
    fixture.detectChanges();
    await openTab('products.tabs.defaults');
    answer({ ...laptop, unitPriceNet: '1300.0000' });
    await settle();

    const selected = fixture.nativeElement.querySelector(
      '[role="tab"][aria-selected="true"]',
    ) as HTMLElement;
    expect(selected.textContent).toContain('products.tabs.defaults');
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

  // docs/SPEC.md § 7, 2026-10-08 23:02: the main photo stands beside the title, and follows the gallery once it is read.
  it('shows the main photo beside the title, then the one the gallery makes main', async () => {
    product.set({ ...laptop, mainPhotoId: 'ph1' });
    await open('p1');
    const title = () => q('product-main-photo')?.querySelector('img')?.getAttribute('src');
    expect(title()).toBe('/api/companies/c1/products/p1/photos/ph1/content?size=small');

    await openTab('products.tabs.photos');
    const http = TestBed.inject(HttpTestingController);
    http.expectOne('/api/companies/c1/products/p1/photos').flush([
      { id: 'ph1', main: false },
      { id: 'ph2', main: true },
    ]);
    // whenStable covers the pending request, not the microtasks after its answer.
    await new Promise((resolve) => setTimeout(resolve));
    await settle();

    expect(q('product-photos')).not.toBeNull();
    expect(title()).toBe('/api/companies/c1/products/p1/photos/ph2/content?size=small');
  });

  it('shows no photo beside the title of a product that has none', async () => {
    product.set(laptop);
    await open('p1');

    expect(q('product-main-photo')).toBeNull();
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

  it('keeps the cost history to a saved product, for whoever may read costs and sees them', async () => {
    const hasTab = (): boolean =>
      Array.from(
        fixture.nativeElement.querySelectorAll('[role="tab"]') as NodeListOf<HTMLElement>,
      ).some((tab) => (tab.textContent ?? '').includes('products.tabs.costs'));

    await open(undefined);
    expect(hasTab()).toBe(false);

    product.set(laptop);
    await open('p1');
    expect(hasTab()).toBe(true);

    auth.hasPermission.mockImplementation(
      (permission: string) => permission !== 'product.cost.read',
    );
    await open('p1');
    expect(hasTab()).toBe(false);
  });

  // Audit 2026-10-06, B-9: the price per pack is the product's own packs, counted for the working company.
  it("asks the calculator's taxes for a unit and for each of the product's packs", async () => {
    product.set({
      ...laptop,
      barcodes: [
        { role: 'unit', code: '3017620422003', quantity: 1, supplierId: null },
        { role: 'pack', code: '13017620422000', quantity: 12, supplierId: null },
      ],
    });
    await open('p1');
    await new Promise((resolve) => setTimeout(resolve, 0));
    await settle();

    expect(facade.pricePreview).toHaveBeenCalledWith('c1', '1250.500', ['t1'], ['1', '12']);
  });

  // docs/SPEC.md § 7, 2026-09-23 slice 5.
  it('asks no cost of someone who may not read costs', async () => {
    auth.hasPermission.mockImplementation(
      (permission: string) => permission !== 'product.cost.read',
    );
    product.set(laptop);
    await open('p1');

    expect(q('field-unitPriceNet')).not.toBeNull();
    expect(q('field-costPrice')).toBeNull();
    expect(q('price-calculator')).toBeNull();
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
      1,
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

  // Its homes are audited under their own kind, and their change moves the product's version.
  it('reads the product again when another person changed its homes, standing on the version they left', async () => {
    product.set(laptop);
    await open('p1');
    type('field-unitPriceNet', '1300');
    await settle();
    facade.loadProduct.mockImplementation(async () => product.set({ ...laptop, version: 2 }));

    await announceSaved('product_home_location', 'p1', undefined, 'product_home_location.set');
    await settle();

    expect(facade.loadProduct).toHaveBeenLastCalledWith('c1', 'p1');
    q('record-save')!.click();
    await settle();
    expect(facade.reviseProduct).toHaveBeenLastCalledWith(
      'c1',
      'p1',
      expect.objectContaining({ unitPriceNet: '1300' }),
      2,
    );
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

  it('shows a product merged away as such, opening the one kept, with no way to save, delete, label or add it', async () => {
    addTo.kinds.mockReturnValue(['invoice']);
    product.set({
      ...laptop,
      id: 'p2',
      reference: 'ART-001B',
      isActive: false,
      mergedInto: { id: 'p1', reference: 'ART-001', name: 'Portable 14"' },
    });
    await open('p2');

    expect(q('product-merged-away')).not.toBeNull();
    expect(q('product-merged-away-open')?.getAttribute('href')).toBe('/products/p1');
    expect(q('record-save')).toBeNull();
    expect(await menuEntry('delete')).toBeNull();
    // Labels and « Ajouter à… » may sit in the bar rather than its menu: the declaration is what says either way.
    const declared = TestBed.inject(ScreenActions)
      .actions()
      .map((each) => each.id);
    expect(declared).not.toContain('labels');
    expect(declared).not.toContain('add_to');
    // Nobody lacks the right to change it: it is the merge that keeps it as it was.
    expect(q('product-read-only')).toBeNull();
  });

  it('shows a reader the product without a way to change it', async () => {
    auth.hasPermission.mockReturnValue(false);
    product.set(laptop);
    await open('p1');

    expect(q('product-read-only')).not.toBeNull();
    expect(q('record-save')).toBeNull();
  });
});

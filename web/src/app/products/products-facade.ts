// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { CustomFieldsApi } from '../shared/custom-fields/custom-fields-api';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { SettingsApi } from '../shared/settings/settings-api';
import type { SettingRow } from '../shared/settings/settings-types';
import type { ExportFormat } from '../shared/list/export-address';
import { ProductsApi, ProductsRefused } from './products-api';
import { trackingOf } from './products-types';
import type {
  PricePreviewLine,
  ProductBarcode,
  ProductCategoryInput,
  ProductCategoryRow,
  ProductInput,
  ProductOptions,
  ProductRow,
  ProductSearch,
  ProductsError,
  ProductTracking,
} from './products-types';

/** The products of the company being worked in, their categories, the form's options and the company's fields for products. */
@Injectable({ providedIn: 'root' })
export class ProductsFacade {
  private readonly api = inject(ProductsApi);
  private readonly fields = inject(CustomFieldsApi);
  private readonly settings = inject(SettingsApi);
  private readonly productsSignal = signal<readonly ProductRow[]>([]);
  private readonly totalSignal = signal(0);
  private pageRequest = 0;
  private readonly categoriesSignal = signal<readonly ProductCategoryRow[]>([]);
  private readonly optionsSignal = signal<ProductOptions | null>(null);
  private readonly customFieldsSignal = signal<readonly CustomFieldDefinition[]>([]);
  private readonly productSignal = signal<ProductRow | null>(null);
  private readonly substitutionGroupsSignal = signal<readonly string[]>([]);
  private readonly defaultUnitCodeSignal = signal<string | null>(null);
  private readonly defaultTrackingSignal = signal<ProductTracking>('none');
  private readonly busySignal = signal(false);
  private reads = 0;
  private readonly errorSignal = signal<ProductsError | null>(null);

  readonly products = this.productsSignal.asReadonly();
  /** How many products the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  readonly categories = this.categoriesSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  /** Every custom field declared for products, retired ones included; screens show the active ones. */
  readonly customFields = this.customFieldsSignal.asReadonly();
  readonly product = this.productSignal.asReadonly();
  /** The names of the substitution groups the company's products carry, which the product form offers. */
  readonly substitutionGroups = this.substitutionGroupsSignal.asReadonly();
  /** The unit code `article.default_unit` resolves to for the company, read with a new product's form. */
  readonly defaultUnitCode = this.defaultUnitCodeSignal.asReadonly();
  /** How `article.traceability` says a new product is followed, read with a new product's form. */
  readonly defaultTracking = this.defaultTrackingSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** What the list screen needs besides its page: the categories and units its rows name, and the custom fields it adds columns for. */
  async loadListContext(companyId: string): Promise<void> {
    await this.read(async () => {
      const [categories, options, customFields] = await Promise.all([
        this.api.categories(companyId),
        this.api.options(companyId),
        this.fields.list(companyId, 'product'),
      ]);
      this.categoriesSignal.set(categories);
      this.optionsSignal.set(options);
      this.customFieldsSignal.set(customFields);
    });
  }

  exportUrl(companyId: string, search: ProductSearch, format: ExportFormat): string {
    return this.api.exportUrl(companyId, search, format);
  }

  /**
   * One page of the products the search finds. Only the latest search's answer is shown: typing sends one search per
   * pause, and an earlier one answering late would otherwise put back rows the words no longer find.
   */
  async loadPage(companyId: string, search: ProductSearch): Promise<void> {
    const request = ++this.pageRequest;
    await this.read(async () => {
      const page = await this.api.products(companyId, search);
      if (request !== this.pageRequest) return;
      this.productsSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  async loadCategories(companyId: string): Promise<void> {
    await this.read(async () => this.categoriesSignal.set(await this.api.categories(companyId)));
  }

  /** What the product form needs: its options, the categories, the fields, and the product unless it is new. */
  async loadSubstitutionGroups(companyId: string): Promise<void> {
    await this.read(async () =>
      this.substitutionGroupsSignal.set(await this.api.substitutionGroups(companyId)),
    );
  }

  async loadProduct(companyId: string, id: string | null): Promise<void> {
    await this.read(async () => {
      const [options, categories, customFields, product, articles] = await Promise.all([
        this.api.options(companyId),
        this.api.categories(companyId),
        this.fields.list(companyId, 'product'),
        id === null ? Promise.resolve(null) : this.api.product(companyId, id),
        // Only a new product starts in the company's default unit; an existing one has its own.
        id === null ? this.settings.chain(companyId, 'articles') : Promise.resolve(null),
      ]);
      if (articles !== null) {
        this.defaultUnitCodeSignal.set(unitCodeOf(articles));
        this.defaultTrackingSignal.set(trackingOfRows(articles));
      }
      this.optionsSignal.set(options);
      this.categoriesSignal.set(categories);
      this.customFieldsSignal.set(customFields);
      this.productSignal.set(product);
    });
  }

  /** The product as the API kept it, or null with the reason in `error`. */
  async createProduct(companyId: string, input: ProductInput): Promise<ProductRow | null> {
    return this.save(() => this.api.createProduct(companyId, input));
  }

  async reviseProduct(
    companyId: string,
    id: string,
    input: ProductInput,
  ): Promise<ProductRow | null> {
    return this.save(() => this.api.reviseProduct(companyId, id, input));
  }

  async createCategory(companyId: string, input: ProductCategoryInput): Promise<boolean> {
    return this.write(
      () => this.api.createCategory(companyId, input),
      () => this.reloadCategories(companyId),
    );
  }

  async reviseCategory(
    companyId: string,
    id: string,
    input: ProductCategoryInput,
  ): Promise<boolean> {
    return this.write(
      () => this.api.reviseCategory(companyId, id, input),
      () => this.reloadCategories(companyId),
    );
  }

  async deleteCategory(companyId: string, id: string): Promise<boolean> {
    return this.write(
      () => this.api.deleteCategory(companyId, id),
      () => this.reloadCategories(companyId),
    );
  }

  /**
   * The with-tax figures of a price being typed, or null when the API could not count them (a tax it refuses, the
   * network). Quiet: a preview that fails says so where it is shown, never as the page's error.
   */
  async pricePreview(
    companyId: string,
    unitPriceNet: string,
    taxComponentIds: readonly string[],
    quantities: readonly string[],
  ): Promise<PricePreviewLine[] | null> {
    try {
      return await this.api.pricePreview(companyId, unitPriceNet, taxComponentIds, quantities);
    } catch {
      return null;
    }
  }

  /**
   * The reference a new product would be given, filed in that category, or null when the API could not say. Quiet,
   * like the price preview: the field then stays empty, and the save still gives one.
   */
  async referencePreview(companyId: string, categoryId: string | null): Promise<string | null> {
    try {
      return await this.api.referencePreview(companyId, categoryId);
    } catch {
      return null;
    }
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async reloadCategories(companyId: string): Promise<void> {
    this.categoriesSignal.set(await this.api.categories(companyId));
  }

  private async read(load: () => Promise<void>): Promise<void> {
    this.reads++;
    this.busySignal.set(true);
    // Cleared when a read starts, never when one ends, so a read answering after another failed does not hide it.
    this.errorSignal.set(null);
    try {
      await load();
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      // Several reads run at once (a list and what its filters name): busy until the last one answers.
      if (--this.reads === 0) this.busySignal.set(false);
    }
  }

  /** The codes the codes tab just saved, written into the product this screen holds (the tab saves on its own). */
  barcodesSaved(productId: string, barcodes: ProductBarcode[]): void {
    const product = this.productSignal();
    if (product?.id === productId) this.productSignal.set({ ...product, barcodes });
  }

  private async save(call: () => Promise<ProductRow>): Promise<ProductRow | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const product = await call();
      this.productSignal.set(product);
      return product;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }

  private async write(call: () => Promise<unknown>, reload: () => Promise<void>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      await reload();
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

/** The unit code the articles chain resolves, when it names one. */
function unitCodeOf(rows: readonly SettingRow[]): string | null {
  const value = rows.find((row) => row.key === 'article.default_unit')?.value;
  return typeof value === 'string' ? value : null;
}

/** The tracking the articles chain resolves for a new product; none when it names no known one. */
function trackingOfRows(rows: readonly SettingRow[]): ProductTracking {
  const value = rows.find((row) => row.key === 'article.traceability')?.value;
  return typeof value === 'string' ? trackingOf(value) : 'none';
}

function codeOf(error: unknown): ProductsError {
  return error instanceof ProductsRefused ? error.code : 'network';
}

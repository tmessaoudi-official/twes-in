// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { CustomFieldsApi } from '../shared/custom-fields/custom-fields-api';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { SettingsApi } from '../shared/settings/settings-api';
import type { SettingRow } from '../shared/settings/settings-types';
import { ProductsApi, ProductsRefused } from './products-api';
import type {
  ProductCategoryInput,
  ProductCategoryRow,
  ProductInput,
  ProductOptions,
  ProductRow,
  ProductsError,
} from './products-types';

/** The products of the company being worked in, their categories, the form's options and the company's fields for products. */
@Injectable({ providedIn: 'root' })
export class ProductsFacade {
  private readonly api = inject(ProductsApi);
  private readonly fields = inject(CustomFieldsApi);
  private readonly settings = inject(SettingsApi);
  private readonly productsSignal = signal<readonly ProductRow[]>([]);
  private readonly categoriesSignal = signal<readonly ProductCategoryRow[]>([]);
  private readonly optionsSignal = signal<ProductOptions | null>(null);
  private readonly customFieldsSignal = signal<readonly CustomFieldDefinition[]>([]);
  private readonly productSignal = signal<ProductRow | null>(null);
  private readonly defaultUnitCodeSignal = signal<string | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ProductsError | null>(null);

  readonly products = this.productsSignal.asReadonly();
  readonly categories = this.categoriesSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  /** Every custom field declared for products, retired ones included; screens show the active ones. */
  readonly customFields = this.customFieldsSignal.asReadonly();
  readonly product = this.productSignal.asReadonly();
  /** The unit code `article.default_unit` resolves to for the company, read with a new product's form. */
  readonly defaultUnitCode = this.defaultUnitCodeSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** The list, with what it names by id: categories and units. */
  async loadList(companyId: string): Promise<void> {
    await this.read(async () => {
      const [products, categories, options, customFields] = await Promise.all([
        this.api.products(companyId),
        this.api.categories(companyId),
        this.api.options(companyId),
        this.fields.list(companyId, 'product'),
      ]);
      this.productsSignal.set(products);
      this.categoriesSignal.set(categories);
      this.optionsSignal.set(options);
      this.customFieldsSignal.set(customFields);
    });
  }

  async loadCategories(companyId: string): Promise<void> {
    await this.read(async () => this.categoriesSignal.set(await this.api.categories(companyId)));
  }

  /** What the product form needs: its options, the categories, the fields, and the product unless it is new. */
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

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async reloadCategories(companyId: string): Promise<void> {
    this.categoriesSignal.set(await this.api.categories(companyId));
  }

  private async read(load: () => Promise<void>): Promise<void> {
    this.busySignal.set(true);
    try {
      await load();
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
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

function codeOf(error: unknown): ProductsError {
  return error instanceof ProductsRefused ? error.code : 'network';
}

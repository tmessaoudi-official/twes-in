// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { CustomFieldsApi } from '../shared/custom-fields/custom-fields-api';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { ProductsApi, ProductsRefused } from './products-api';
import { ProductsFacade } from './products-facade';
import type {
  ProductCategoryRow,
  ProductInput,
  ProductOptions,
  ProductRow,
} from './products-types';

const input: ProductInput = {
  reference: 'ART-001',
  name: 'Portable',
  description: null,
  kind: 'goods',
  unitId: 'u1',
  unitPriceNet: '1250',
  costPrice: null,
  categoryId: null,
  barcode: null,
  defaultTaxComponentIds: [],
  isActive: true,
  customFields: {},
};
const laptop: ProductRow = { ...input, id: 'p1', unitPriceNet: '1250.0000' };
const hardware: ProductCategoryRow = {
  id: 'k1',
  name: 'Matériel',
  parentId: null,
  productCount: 0,
  childCount: 0,
};
const options: ProductOptions = { currency: 'TND', currencyScale: 3, units: [], taxes: [] };
const warranty: CustomFieldDefinition = {
  id: 'f1',
  entity: 'product',
  key: 'warranty',
  label: 'Garantie',
  type: 'number',
  required: false,
  choices: [],
  sortOrder: 0,
  isActive: true,
};

describe('ProductsFacade', () => {
  const api = {
    options: vi.fn(),
    products: vi.fn(),
    product: vi.fn(),
    createProduct: vi.fn(),
    reviseProduct: vi.fn(),
    categories: vi.fn(),
    createCategory: vi.fn(),
    reviseCategory: vi.fn(),
    deleteCategory: vi.fn(),
  };
  const fieldsApi = { list: vi.fn() };
  let facade: ProductsFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.options.mockResolvedValue(options);
    api.products.mockResolvedValue([laptop]);
    api.categories.mockResolvedValue([hardware]);
    fieldsApi.list.mockReset().mockResolvedValue([warranty]);
    TestBed.configureTestingModule({
      providers: [
        { provide: ProductsApi, useValue: api },
        { provide: CustomFieldsApi, useValue: fieldsApi },
      ],
    });
    facade = TestBed.inject(ProductsFacade);
  });

  it("reads the list with the categories, the options and the company's fields for products", async () => {
    await facade.loadList('c1');

    expect(facade.products()).toEqual([laptop]);
    expect(facade.categories()).toEqual([hardware]);
    expect(facade.options()).toEqual(options);
    expect(fieldsApi.list).toHaveBeenCalledWith('c1', 'product');
    expect(facade.customFields()).toEqual([warranty]);
    expect(facade.error()).toBeNull();
  });

  it('reads one product for its form, or none for a new one', async () => {
    api.product.mockResolvedValue(laptop);
    await facade.loadProduct('c1', 'p1');
    expect(api.product).toHaveBeenCalledWith('c1', 'p1');
    expect(facade.product()).toEqual(laptop);

    await facade.loadProduct('c1', null);
    expect(api.product).toHaveBeenCalledTimes(1);
    expect(facade.product()).toBeNull();
  });

  it('keeps the product the API answered, or says why it refused', async () => {
    api.createProduct.mockResolvedValueOnce(laptop);
    expect(await facade.createProduct('c1', input)).toEqual(laptop);
    expect(facade.product()).toEqual(laptop);

    api.reviseProduct.mockRejectedValueOnce(new ProductsRefused('reference_taken'));
    expect(await facade.reviseProduct('c1', 'p1', input)).toBeNull();
    expect(facade.error()).toBe('reference_taken');

    facade.clearError();
    expect(facade.error()).toBeNull();
  });

  it('reads the categories again after each change', async () => {
    api.createCategory.mockResolvedValue(hardware);
    expect(await facade.createCategory('c1', { name: 'Matériel', parentId: null })).toBe(true);
    api.reviseCategory.mockResolvedValue(hardware);
    expect(await facade.reviseCategory('c1', 'k1', { name: 'Matériel', parentId: null })).toBe(
      true,
    );
    api.deleteCategory.mockResolvedValue(undefined);
    expect(await facade.deleteCategory('c1', 'k1')).toBe(true);
    expect(api.categories).toHaveBeenCalledTimes(3);

    api.deleteCategory.mockRejectedValueOnce(new ProductsRefused('in_use'));
    expect(await facade.deleteCategory('c1', 'k1')).toBe(false);
    expect(facade.error()).toBe('in_use');
  });

  it('says the network failed when a read throws something else', async () => {
    api.products.mockRejectedValueOnce(new Error('boom'));
    await facade.loadList('c1');

    expect(facade.error()).toBe('network');
    expect(facade.busy()).toBe(false);
  });
});

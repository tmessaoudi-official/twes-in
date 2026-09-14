// SPDX-License-Identifier: AGPL-3.0-or-later

import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import {
  categoryForm,
  categoryInput,
  categoryLabels,
  categoryValues,
  displayPrice,
  productForm,
  productInput,
  productListRows,
  productsList,
  productValues,
} from './product-forms';
import type { ProductCategoryRow, ProductOptions, ProductRow } from './products-types';

const options: ProductOptions = {
  currency: 'TND',
  currencyScale: 3,
  units: [
    { id: 'u-hour', code: 'HUR', name: 'Heure', decimals: 2 },
    { id: 'u-unit', code: 'C62', name: 'Unité', decimals: 0 },
  ],
  taxes: [
    { id: 't-fodec', code: 'FODEC', name: 'FODEC 1 %', family: 'levy' },
    { id: 't-vat', code: 'TVA19', name: 'TVA 19 %', family: 'vat' },
  ],
};
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
  productCount: 1,
  childCount: 1,
};
const gaming: ProductCategoryRow = {
  id: 'k3',
  name: 'Gaming',
  parentId: 'k2',
  productCount: 0,
  childCount: 0,
};
const laptop: ProductRow = {
  id: 'p1',
  reference: 'ART-001',
  name: 'Portable 14"',
  description: 'Processeur 8 cœurs',
  kind: 'goods',
  unitId: 'u-unit',
  unitPriceNet: '1250.5000',
  costPrice: '900.1250',
  categoryId: 'k2',
  barcode: '3017620422003',
  defaultTaxComponentIds: ['t-vat'],
  isActive: true,
  customFields: { warranty: 24 },
};
const warranty: CustomFieldDefinition = {
  id: 'f1',
  entity: 'product',
  key: 'warranty',
  label: 'Garantie (mois)',
  type: 'number',
  required: false,
  choices: [],
  sortOrder: 0,
  isActive: true,
};

describe('product forms', () => {
  it('shows a price at the currency scale, keeping the finer decimals a unit price may carry', () => {
    expect(displayPrice('1250.5000', 3)).toBe('1250.500');
    expect(displayPrice('0.0045', 3)).toBe('0.0045');
    expect(displayPrice('12.0000', 2)).toBe('12.00');
    expect(displayPrice('7.0000', 0)).toBe('7');
    expect(displayPrice('7.5000', 0)).toBe('7.5');
  });

  it('names each category by its path in the tree', () => {
    expect(categoryLabels([gaming, laptops, hardware])).toEqual(
      new Map([
        ['k1', 'Matériel'],
        ['k2', 'Matériel › Portables'],
        ['k3', 'Matériel › Portables › Gaming'],
      ]),
    );
  });

  it('lists products with their category path, unit code and price at the currency scale', () => {
    expect(productListRows([laptop], [hardware, laptops], options)).toEqual([
      { ...laptop, categoryName: 'Matériel › Portables', unitCode: 'C62', price: '1250.500' },
    ]);
    expect(productListRows([{ ...laptop, categoryId: null }], [], null)[0]).toEqual(
      expect.objectContaining({ categoryName: null, unitCode: '', price: '1250.5000' }),
    );
    const list = productsList([warranty]);
    expect(list.columns.map((column) => column.id)).toEqual([
      'reference',
      'name',
      'kind',
      'category',
      'unit',
      'price',
      'status',
      'custom__warranty',
    ]);
  });

  it('offers the active units, the line taxes, the categories by path and the custom fields', () => {
    const form = productForm(options, [laptops, hardware], [warranty]);
    const fields = form.sections.flatMap((section) => section.fields);

    expect(form.sections.map((section) => section.id)).toEqual([
      'identity',
      'pricing',
      'taxes',
      'description',
      'custom',
    ]);
    expect(fields.find((field) => field.id === 'unitId')?.options).toEqual([
      { value: 'u-hour', label: 'HUR · Heure' },
      { value: 'u-unit', label: 'C62 · Unité' },
    ]);
    expect(fields.find((field) => field.id === 'categoryId')?.options).toEqual([
      { value: '', label: 'products.form.no_category' },
      { value: 'k1', label: 'Matériel' },
      { value: 'k2', label: 'Matériel › Portables' },
    ]);
    expect(
      fields.filter((field) => field.id.startsWith('tax__')).map((field) => field.label),
    ).toEqual(['FODEC 1 %', 'TVA 19 %']);
    expect(fields.find((field) => field.id === 'unitPriceNet')).toEqual(
      expect.objectContaining({ required: true, pattern: '(0|[1-9][0-9]{0,9})([.][0-9]{1,4})?' }),
    );
    expect(fields.map((field) => field.id)).toContain('custom__warranty');
  });

  it("starts a new product as goods in the company's unit, and fills a product's own values", () => {
    expect(productValues(null, options, [], 'HUR')['unitId']).toBe('u-hour');
    // A unit the company does not offer, or none resolved: the first unit it offers.
    expect(productValues(null, options, [], 'KGM')['unitId']).toBe('u-hour');
    expect(productValues(null, options, [], null)['unitId']).toBe('u-hour');
    expect(productValues(null, options, [], 'C62')).toEqual(
      expect.objectContaining({
        reference: '',
        kind: 'goods',
        unitId: 'u-unit',
        unitPriceNet: '',
        categoryId: '',
        isActive: true,
        'tax__t-vat': false,
      }),
    );
    expect(productValues(laptop, options, [warranty])).toEqual(
      expect.objectContaining({
        reference: 'ART-001',
        unitPriceNet: '1250.500',
        costPrice: '900.125',
        categoryId: 'k2',
        'tax__t-vat': true,
        'tax__t-fodec': false,
        custom__warranty: 24,
      }),
    );
  });

  it('turns the form back into what the API takes: trimmed, empty as null, ticked taxes', () => {
    const values = {
      ...productValues(laptop, options, [warranty]),
      reference: ' ART-002 ',
      description: '  ',
      unitPriceNet: ' 80 ',
      costPrice: '',
      barcode: '',
      categoryId: '',
      kind: 'service',
      'tax__t-fodec': true,
    };

    expect(productInput(values, options, [warranty])).toEqual({
      reference: 'ART-002',
      name: 'Portable 14"',
      description: null,
      kind: 'service',
      unitId: 'u-unit',
      unitPriceNet: '80',
      costPrice: null,
      categoryId: null,
      barcode: null,
      defaultTaxComponentIds: ['t-fodec', 't-vat'],
      isActive: true,
      customFields: { warranty: 24 },
    });
  });

  it('never offers a category as a parent of itself or of its own subcategories', () => {
    const parents = (editing: ProductCategoryRow | null) =>
      categoryForm([hardware, laptops, gaming], editing)
        .sections[0]!.fields.find((field) => field.id === 'parentId')!
        .options!.map((option) => option.value);

    expect(parents(null)).toEqual(['', 'k1', 'k2', 'k3']);
    expect(parents(laptops)).toEqual(['', 'k1']);
    expect(parents(gaming)).toEqual(['', 'k1', 'k2']);
  });

  it('reads and writes a category', () => {
    expect(categoryValues(laptops)).toEqual({ name: 'Portables', parentId: 'k1' });
    expect(categoryValues(null)).toEqual({ name: '', parentId: '' });
    expect(categoryInput({ name: ' Gaming ', parentId: '' })).toEqual({
      name: 'Gaming',
      parentId: null,
    });
    expect(categoryInput({ name: 'Gaming', parentId: 'k2' })).toEqual({
      name: 'Gaming',
      parentId: 'k2',
    });
  });
});

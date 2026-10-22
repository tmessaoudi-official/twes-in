// SPDX-License-Identifier: AGPL-3.0-or-later

import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import {
  categoryForm,
  categoryInput,
  categoryLabels,
  categoryValues,
  productForm,
  productInput,
  productListRows,
  PRODUCTS_LIST,
  productSearch,
  productsList,
  productValues,
} from './product-forms';
import type {
  ProductCategoryRow,
  ProductCheckReason,
  ProductOptions,
  ProductRow,
} from './products-types';

const options: ProductOptions = {
  currency: 'TND',
  currencyScale: 3,
  units: [
    { id: 'u-hour', code: 'HUR', name: 'Heure', decimals: 2 },
    { id: 'u-unit', code: 'C62', name: 'Unité', decimals: 0 },
  ],
  taxes: [
    { id: 't-fodec', code: 'FODEC', name: 'FODEC 1 %', family: 'levy', isDefault: false },
    { id: 't-vat', code: 'TVA19', name: 'TVA 19 %', family: 'vat', isDefault: true },
  ],
  photosPerProduct: 6,
  photoMaxBytes: 5242880,
  checkReasons: [],
};
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
  productCount: 1,
  childCount: 1,
  version: 1,
};
const gaming: ProductCategoryRow = {
  id: 'k3',
  name: 'Gaming',
  parentId: 'k2',
  productCount: 0,
  childCount: 0,
  version: 1,
};
const laptop: ProductRow = {
  id: 'p1',
  version: 1,
  reference: 'ART-001',
  name: 'Portable 14"',
  description: 'Processeur 8 cœurs',
  kind: 'goods',
  unitId: 'u-unit',
  unitPriceNet: '1250.5000',
  costPrice: '900.1250',
  unitPriceGross: null,
  margin: null,
  marginPercent: null,
  categoryId: 'k2',
  barcodes: [],
  defaultTaxComponentIds: ['t-vat'],
  isActive: true,
  customFields: { warranty: 24 },
  tracking: 'lot',
  substitutionGroup: null,
  mainPhotoId: null,
  mergedInto: null,
  priceReview: null,
  toCheck: false,
  checkNote: null,
  zeroPriceIntended: false,
  checkReasons: [],
  vendorIds: [],
  vendors: [],
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
  version: 1,
};

describe('product forms', () => {
  it('offers the filters ruled for the list', () => {
    expect(
      (PRODUCTS_LIST.filters ?? []).map((filter) => [filter.id, filter.multiple ?? false]),
    ).toEqual([
      ['kind', true],
      ['tracking', true],
      ['status', false],
    ]);
    expect(PRODUCTS_LIST.picks?.map((pick) => pick.id)).toEqual(['category']);
    expect(PRODUCTS_LIST.ranges?.map((range) => [range.id, range.kind])).toEqual([
      ['unitPriceNet', 'amount'],
    ]);
  });

  it('asks the API for the page, words, kind, status and sort the list shows, and sorts only by what it sorts', () => {
    expect(
      productSearch({
        query: 'portable',
        filters: {
          kind: 'service,robot',
          status: 'inactive',
          tracking: 'serial,batch',
          category: '0199a1b2-0000-7000-8000-000000000001,not-an-id',
          'unitPriceNet.min': '5',
          'unitPriceNet.max': 'ten',
        },
        sort: { column: 'status', direction: 'desc' },
        pageIndex: 2,
        pageSize: 50,
      }),
    ).toEqual({
      page: 3,
      itemsPerPage: 50,
      q: 'portable',
      kinds: ['service'],
      trackings: ['serial'],
      categoryIds: ['0199a1b2-0000-7000-8000-000000000001'],
      isActive: false,
      toCheck: null,
      checkReasons: [],
      intervals: { 'unitPriceNet.min': '5' },
      order: { key: 'isActive', direction: 'desc' },
    });
    expect(
      productSearch({
        query: '',
        filters: { kind: 'unknown' },
        sort: { column: 'category', direction: 'asc' },
        pageIndex: 0,
        pageSize: 25,
      }),
    ).toEqual({
      page: 1,
      itemsPerPage: 25,
      q: '',
      kinds: [],
      trackings: [],
      categoryIds: [],
      isActive: null,
      toCheck: null,
      checkReasons: [],
      intervals: {},
      order: { key: 'category', direction: 'asc' },
    });
    for (const column of PRODUCTS_LIST.columns.filter((each) => each.sortable)) {
      expect(
        productSearch({
          query: '',
          filters: {},
          sort: { column: column.id, direction: 'asc' },
          pageIndex: 0,
          pageSize: 25,
        }).order,
      ).not.toBeNull();
    }
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

  it('lists products with their category path and unit name, sorting the price as the API wrote it', () => {
    expect(productListRows([laptop], [hardware, laptops], options)).toEqual([
      { ...laptop, categoryName: 'Matériel › Portables', unitName: 'Unité' },
    ]);
    expect(productListRows([{ ...laptop, categoryId: null }], [], null)[0]).toEqual(
      expect.objectContaining({ categoryName: null, unitName: '' }),
    );
    const list = productsList([warranty]);
    expect(list.columns.map((column) => column.id)).toEqual([
      'photo',
      'reference',
      'name',
      'kind',
      'category',
      'unit',
      'price',
      'priceGross',
      'status',
      'custom__warranty',
    ]);
  });

  // docs/SPEC.md § 7: prix TTC, marge and marge % hidden until placed; a field the person cannot read is no column.
  it('offers the price taxes included to everyone and the cost and margins to a cost reader only, all hidden', () => {
    const ids = (costs: boolean) => productsList([], [], costs).columns.map((column) => column.id);
    expect(ids(false)).not.toEqual(expect.arrayContaining(['cost']));
    expect(ids(false).filter((id) => ['margin', 'marginPercent'].includes(id))).toEqual([]);
    expect(ids(true)).toEqual([
      'photo',
      'reference',
      'name',
      'kind',
      'category',
      'unit',
      'price',
      'priceGross',
      'cost',
      'margin',
      'marginPercent',
      'status',
    ]);
    const columns = productsList([], [], true).columns;
    for (const id of ['priceGross', 'cost', 'margin', 'marginPercent']) {
      expect(columns.find((column) => column.id === id)?.defaultHidden).toBe(true);
    }
    const row = { ...laptop, categoryName: null, unitName: '' };
    const value = (id: string, of: typeof row) =>
      columns.find((column) => column.id === id)?.value(of);
    expect(value('marginPercent', { ...row, marginPercent: '20.0' })).toBe('20.0');
    expect(value('margin', { ...row, margin: null })).toBe('');
    expect(value('priceGross', { ...row, unitPriceGross: '1487.500' })).toBe('1487.500');
  });

  // docs/SPEC.md § 7, 2026-09-17 (3): a new product left without a reference is given one; a product keeps its own.
  it('asks a reference of an existing product only', () => {
    const reference = (newProduct: boolean) =>
      productForm(options, [], [], { cost: true, newProduct })
        .sections.flatMap((section) => section.fields)
        .find((field) => field.id === 'reference');
    expect(reference(true)).toEqual(
      expect.objectContaining({ required: false, hint: 'products.form.reference_hint_new' }),
    );
    expect(reference(false)).toEqual(
      expect.objectContaining({ required: true, hint: 'products.form.reference_hint' }),
    );
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: prices show and take the locale's decimal separator.
  it('asks both prices as decimals', () => {
    const fields = productForm(options, [laptops, hardware], [warranty]).sections.flatMap(
      (section) => section.fields,
    );
    expect(fields.filter((field) => field.kind === 'decimal').map((field) => field.id)).toEqual([
      'unitPriceNet',
      'costPrice',
    ]);
  });

  it('offers the active units, the line taxes, the categories by path and the custom fields', () => {
    const form = productForm(options, [laptops, hardware], [warranty]);
    const fields = form.sections.flatMap((section) => section.fields);

    expect(form.sections.map((section) => section.id)).toEqual([
      'identity',
      'pricing',
      'taxes',
      'check',
      'description',
      'custom',
    ]);
    expect(fields.find((field) => field.id === 'unitId')?.options).toEqual([
      // A shopkeeper reads « Heure », never « HUR »: the code stays in the units' own settings (audit V-10).
      { value: 'u-hour', label: 'Heure' },
      { value: 'u-unit', label: 'Unité' },
    ]);
    expect(fields.find((field) => field.id === 'categoryId')?.options).toEqual([
      { value: '', label: 'products.form.no_category' },
      { value: 'k1', label: 'Matériel' },
      { value: 'k2', label: 'Matériel › Portables' },
    ]);
    expect(fields.find((field) => field.id === 'defaultTaxComponentIds')).toMatchObject({
      kind: 'multiselect',
    });
    expect(
      fields.find((field) => field.id === 'defaultTaxComponentIds')?.options?.map((o) => o.label),
    ).toEqual(['FODEC 1 %', 'TVA 19 %']);
    expect(fields.find((field) => field.id === 'unitPriceNet')).toEqual(
      expect.objectContaining({ required: true, pattern: '(0|[1-9][0-9]{0,9})([.][0-9]{1,4})?' }),
    );
    expect(fields.map((field) => field.id)).toContain('custom__warranty');
  });

  it("ticks the company's default line taxes on a new product only, so an exempt one keeps none", () => {
    expect(productValues(null, options)['defaultTaxComponentIds']).toEqual(['t-vat']);
    const exempt: ProductRow = { ...laptop, defaultTaxComponentIds: [] };
    expect(productValues(exempt, options)['defaultTaxComponentIds']).toEqual([]);
    const none: ProductOptions = {
      ...options,
      taxes: options.taxes.map((tax) => ({ ...tax, isDefault: false })),
    };
    expect(productValues(null, none)['defaultTaxComponentIds']).toEqual([]);
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
        defaultTaxComponentIds: ['t-vat'],
      }),
    );
    expect(productValues(laptop, options, [warranty])).toEqual(
      expect.objectContaining({
        reference: 'ART-001',
        unitPriceNet: '1250.500',
        costPrice: '900.125',
        categoryId: 'k2',
        defaultTaxComponentIds: ['t-vat'],
        custom__warranty: 24,
      }),
    );
  });

  // docs/SPEC.md § 7, 2026-09-23 slice 5: the cost is asked of someone who may read it, and not in customer view.
  it('leaves the cost out of a form that must not show it', () => {
    const fields = productForm(options, [laptops, hardware], [warranty], {
      cost: false,
    }).sections.flatMap((section) => section.fields);

    expect(fields.map((field) => field.id)).not.toContain('costPrice');
    expect(fields.filter((field) => field.kind === 'decimal').map((field) => field.id)).toEqual([
      'unitPriceNet',
    ]);
  });

  it("keeps the product's cost when the form did not show it, so a save never erases what was hidden", () => {
    const values = productValues(laptop, options, [warranty]);
    delete values['costPrice'];

    expect(productInput(values, options, [warranty], laptop).costPrice).toBe('900.1250');
    expect(productInput(values, options, [warranty], null).costPrice).toBeNull();
  });

  it('turns the form back into what the API takes: trimmed, empty as null, ticked taxes', () => {
    const values = {
      ...productValues(laptop, options, [warranty]),
      reference: ' ART-002 ',
      description: '  ',
      unitPriceNet: ' 80 ',
      costPrice: '',
      categoryId: '',
      kind: 'service',
      defaultTaxComponentIds: ['t-vat', 't-fodec'],
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
      defaultTaxComponentIds: ['t-fodec', 't-vat'],
      isActive: true,
      customFields: { warranty: 24 },
      tracking: 'none',
      substitutionGroup: null,
      toCheck: false,
      checkNote: null,
      zeroPriceIntended: false,
      vendorIds: [],
    });
  });

  it('asks who a product is bought from only while the company keeps a vendor book', () => {
    const sections = (vendors: boolean) =>
      productForm(options, [], [], { cost: true, vendors }).sections.map((each) => each.id);
    expect(sections(true)).toContain('buying');
    expect(sections(false)).not.toContain('buying');
    expect(
      productForm(options, [], [], { cost: true, vendors: true })
        .sections.find((each) => each.id === 'buying')
        ?.fields.map((field) => [field.id, field.kind]),
    ).toEqual([['vendorIds', 'picks']]);
  });

  it('sends exactly the vendors held, each once, and keeps them when the form did not show them', () => {
    const values = productValues({ ...laptop, vendorIds: ['v1'] }, options);
    expect(values['vendorIds']).toEqual(['v1']);
    expect(productInput({ ...values, vendorIds: ['v2', 'v1', 'v2'] }, options).vendorIds).toEqual([
      'v2',
      'v1',
    ]);
    const withoutField = { ...values };
    delete withoutField['vendorIds'];
    expect(productInput(withoutField, options).vendorIds).toBeNull();
  });

  // docs/SPEC.md § 7, 2026-10-20 01:40: « à vérifier » by hand with a note, and « prix 0 voulu » under a price of nothing.
  it('marks a product to check with its note, and keeps the note only with its mark', () => {
    const marked: ProductRow = { ...laptop, toCheck: true, checkNote: 'Prix à confirmer' };
    const values = productValues(marked, options);
    expect(values).toEqual(
      expect.objectContaining({
        toCheck: true,
        checkNote: 'Prix à confirmer',
        zeroPriceIntended: false,
      }),
    );
    expect(productInput(values, options)).toEqual(
      expect.objectContaining({ toCheck: true, checkNote: 'Prix à confirmer' }),
    );
    expect(productInput({ ...values, checkNote: '  ' }, options).checkNote).toBeNull();
    expect(productInput({ ...values, toCheck: false }, options)).toEqual(
      expect.objectContaining({ toCheck: false, checkNote: null }),
    );
    expect(productValues(null, options)).toEqual(
      expect.objectContaining({ toCheck: false, checkNote: '', zeroPriceIntended: false }),
    );
  });

  it('asks the note under the mark and « prix 0 voulu » under a price of nothing', () => {
    const fields = productForm(options, []).sections.flatMap((section) => section.fields);
    expect(fields.find((field) => field.id === 'checkNote')).toEqual(
      expect.objectContaining({
        kind: 'textarea',
        maxLength: 500,
        visibleWhen: { field: 'toCheck', oneOf: ['true'] },
      }),
    );
    expect(fields.find((field) => field.id === 'zeroPriceIntended')).toEqual(
      expect.objectContaining({
        kind: 'checkbox',
        visibleWhen: { field: 'unitPriceNet', zero: true },
      }),
    );
    expect(
      productInput({ ...productValues(laptop, options), zeroPriceIntended: true }, options)
        .zeroPriceIntended,
    ).toBe(true);
  });

  it('narrows the list to what is to check, and by what is missing among the reasons said to this reader', () => {
    const query = (filters: Record<string, string>) => ({
      query: '',
      filters,
      sort: null,
      pageIndex: 0,
      pageSize: 25,
    });
    expect(productSearch(query({ toCheck: 'yes' })).toCheck).toBe(true);
    expect(productSearch(query({ toCheck: 'no' })).toCheck).toBe(false);
    expect(productSearch(query({ toCheck: 'maybe' })).toCheck).toBeNull();
    expect(
      productSearch(query({ checkReason: 'cost_missing,barcode_missing,unknown' }), [
        'price_missing',
        'barcode_missing',
      ]).checkReasons,
    ).toEqual(['barcode_missing']);
    expect(productSearch(query({ checkReason: 'barcode_missing' })).checkReasons).toEqual([]);

    const filters = (offered: ProductCheckReason[]) =>
      (productsList([], offered).filters ?? []).map((filter) => [
        filter.id,
        filter.options.map((option) => option.value),
      ]);
    expect(filters(['barcode_missing', 'price_missing']).slice(3)).toEqual([
      ['toCheck', ['yes', 'no']],
      ['checkReason', ['price_missing', 'barcode_missing']],
    ]);
    expect(filters([]).slice(3)).toEqual([['toCheck', ['yes', 'no']]]);
    const reasonFilter = productsList([], ['price_missing']).filters?.[4];
    const row = {
      ...laptop,
      categoryName: null,
      unitName: '',
      checkReasons: ['price_missing' as const],
    };
    expect(reasonFilter?.value(row)).toEqual(['price_missing']);
    expect(productsList([], []).filters?.[3]?.value(row)).toBe('yes');
    expect(productsList([], []).filters?.[3]?.value({ ...row, checkReasons: [] })).toBe('no');
    expect(
      productsList([], []).filters?.[3]?.value({ ...row, checkReasons: [], toCheck: true }),
    ).toBe('yes');
  });

  // docs/SPEC.md § 7, 2026-09-22 11:10 and 2026-09-23 slice 7: how a product's stock is told apart.
  it('asks how the stock is told apart, a new product by none, and sends none for a service', () => {
    const tracking = productForm(options, [laptops, hardware])
      .sections.flatMap((section) => section.fields)
      .find((field) => field.id === 'tracking');
    expect(tracking?.kind).toBe('select');
    expect(tracking?.options?.map((option) => option.value)).toEqual(['none', 'lot', 'serial']);

    expect(productValues(null, options)['tracking']).toBe('none');
    expect(productValues(null, options, [], null, 'lot')['tracking']).toBe('lot');
    expect(productValues(laptop, options, [], null, 'serial')['tracking']).toBe('lot');
    const values = productValues(laptop, options, [warranty]);
    expect(values['tracking']).toBe('lot');
    expect(productInput(values, options, [warranty]).tracking).toBe('lot');
    expect(productInput({ ...values, tracking: 'serial' }, options, [warranty]).tracking).toBe(
      'serial',
    );
    expect(productInput({ ...values, kind: 'service' }, options, [warranty]).tracking).toBe('none');
    expect(productInput({ ...values, tracking: 'weird' }, options, [warranty]).tracking).toBe(
      'none',
    );
  });

  it('asks a substitution group by name, trimmed, an empty one as none, and fills it back', () => {
    const field = productForm(options, [laptops, hardware])
      .sections.flatMap((section) => section.fields)
      .find((each) => each.id === 'substitutionGroup');
    expect(field?.kind).toBe('text');
    expect(field?.maxLength).toBe(80);

    const values = productValues({ ...laptop, substitutionGroup: 'Portables' }, options);
    expect(values['substitutionGroup']).toBe('Portables');
    expect(
      productInput({ ...values, substitutionGroup: '  Ultra  ' }, options).substitutionGroup,
    ).toBe('Ultra');
    expect(
      productInput({ ...values, substitutionGroup: '   ' }, options).substitutionGroup,
    ).toBeNull();
    expect(productValues(null, options)['substitutionGroup']).toBe('');
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

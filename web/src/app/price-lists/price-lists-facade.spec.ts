// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { CustomersApi } from '../customers/customers-api';
import { ProductsApi } from '../products/products-api';
import { PriceListsApi, PriceListsRefused } from './price-lists-api';
import { PriceListsFacade } from './price-lists-facade';
import type { PriceListInput, PriceListRow } from './price-lists-types';

const row: PriceListRow = {
  id: 'l1',
  name: 'Gros',
  customerGroupId: null,
  customerId: null,
  validFrom: null,
  validTo: null,
  isActive: true,
  itemCount: 0,
  items: null,
};
const input: PriceListInput = { ...row, items: [] };

describe('PriceListsFacade', () => {
  const api = {
    list: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    remove: vi.fn(),
  };
  const products = { products: vi.fn(), product: vi.fn() };
  const customers = { groups: vi.fn(), customers: vi.fn(), customer: vi.fn() };
  let facade: PriceListsFacade;

  beforeEach(() => {
    [...Object.values(api), products.products, ...Object.values(customers)].forEach((fn) =>
      fn.mockReset(),
    );
    api.list.mockResolvedValue([row]);
    customers.groups.mockResolvedValue([{ id: 'g1', name: 'Revendeurs', description: null }]);
    TestBed.configureTestingModule({
      providers: [
        { provide: PriceListsApi, useValue: api },
        { provide: ProductsApi, useValue: products },
        { provide: CustomersApi, useValue: customers },
      ],
    });
    facade = TestBed.inject(PriceListsFacade);
  });

  it('loads the lists and the customer groups they can be for', async () => {
    await facade.load('c1');

    expect(facade.lists()).toEqual([row]);
    expect(facade.groups().map((group) => group.name)).toEqual(['Revendeurs']);
    expect(facade.error()).toBeNull();
    expect(facade.busy()).toBe(false);
  });

  it('reads the lists again after a save, a revision or a deletion', async () => {
    api.create.mockResolvedValue(row);
    api.revise.mockResolvedValue(row);
    api.remove.mockResolvedValue(undefined);

    expect(await facade.create('c1', input)).toBe(true);
    expect(await facade.revise('c1', 'l1', input)).toBe(true);
    expect(await facade.remove('c1', 'l1')).toBe(true);

    expect(api.list).toHaveBeenCalledTimes(3);
  });

  it('names a refusal and keeps the lists it had', async () => {
    await facade.load('c1');
    api.create.mockRejectedValue(new PriceListsRefused('name_taken'));

    expect(await facade.create('c1', input)).toBe(false);

    expect(facade.error()).toBe('name_taken');
    expect(facade.lists()).toEqual([row]);
    expect(facade.busy()).toBe(false);
  });

  it('opens one list with its prices, or null with the refusal named', async () => {
    api.get.mockResolvedValueOnce({ ...row, items: [] });
    expect((await facade.open('c1', 'l1'))?.items).toEqual([]);

    api.get.mockRejectedValueOnce(new PriceListsRefused('not_found'));
    expect(await facade.open('c1', 'gone')).toBeNull();
    expect(facade.error()).toBe('not_found');
  });

  it('offers the few active products and customers the words name, as picks', async () => {
    products.products.mockResolvedValue({
      rows: [{ id: 'p1', reference: 'REF-1', name: 'Stylo' }],
      total: 1,
    });
    customers.customers.mockResolvedValue({
      rows: [{ id: 'k1', number: 'CLI-0001', name: 'Acme' }],
      total: 1,
    });

    expect(await facade.pickProducts('c1', 'sty')).toEqual([
      { id: 'p1', code: 'REF-1', name: 'Stylo' },
    ]);
    expect(products.products).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ q: 'sty', isActive: true, itemsPerPage: 10 }),
    );
    expect(await facade.pickCustomers('c1', 'acme')).toEqual([
      { id: 'k1', code: 'CLI-0001', name: 'Acme' },
    ]);
  });

  it('names a customer a list is for, and leaves one that cannot be read out', async () => {
    customers.customer.mockImplementation(async (_company: string, id: string) => {
      if (id === 'gone') throw new Error('404');
      return { id, number: 'CLI-0001', name: 'Acme' };
    });

    expect(await facade.pickCustomerIds('c1', ['k1', 'gone'])).toEqual([
      { id: 'k1', code: 'CLI-0001', name: 'Acme' },
    ]);
  });

  it('remembers the price and cost of the products a picker offered', async () => {
    products.products.mockResolvedValue({
      rows: [
        {
          id: 'p1',
          reference: 'REF-1',
          name: 'Stylo',
          unitPriceNet: '890.0000',
          costPrice: '534.0000',
        },
      ],
    });

    await facade.pickProducts('c1', 'sty');

    expect(facade.figures().get('p1')).toEqual({ unitPriceNet: '890.0000', costPrice: '534.0000' });
  });

  it("reads the figures of a list's products once each, and shows none for one it cannot read", async () => {
    products.product.mockImplementation(async (_company: string, id: string) => {
      if (id === 'p2') throw new Error('gone');
      return { id, unitPriceNet: '10.0000', costPrice: null };
    });

    await facade.loadFigures('c1', ['p1', 'p1', 'p2']);
    await facade.loadFigures('c1', ['p1']);

    expect(products.product).toHaveBeenCalledTimes(2);
    expect([...facade.figures().keys()]).toEqual(['p1']);
  });
});

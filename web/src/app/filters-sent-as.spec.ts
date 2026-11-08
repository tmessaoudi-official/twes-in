// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient, type HttpParams } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivityApi } from './activity/activity-api';
import { ACTIVITY_LIST, activitySearch } from './activity/activity-list';
import { CUSTOMERS_LIST, customerSearch } from './customers/customer-forms';
import { CustomersApi } from './customers/customers-api';
import { DELIVERY_NOTES_LIST, deliveryNoteSearch } from './delivery-notes/delivery-note-forms';
import { DeliveryNotesApi } from './delivery-notes/delivery-notes-api';
import { EXPENSES_LIST, expenseSearch } from './expenses/expense-forms';
import { ExpensesApi } from './expenses/expenses-api';
import { InventoryApi } from './inventory/inventory-api';
import {
  MOVEMENTS_LIST,
  movementSearch,
  STOCK_LIST,
  stockSearch,
} from './inventory/inventory-forms';
import { INVOICES_LIST, invoiceSearch } from './invoices/invoice-forms';
import { InvoicesApi } from './invoices/invoices-api';
import { PortfolioApi } from './invoices/portfolio-api';
import { PORTFOLIO_LIST, portfolioSearch } from './invoices/portfolio-forms';
import { PlatformApi } from './platform/platform-api';
import {
  ACCOUNTS_LIST,
  accountSearch,
  companiesListOf,
  companySearch,
} from './platform/platform-forms';
import { PRODUCTS_LIST, productSearch } from './products/product-forms';
import { ProductsApi } from './products/products-api';
import { PURCHASES_LIST, purchaseOrderSearch } from './purchases/purchase-order-forms';
import { PurchasesApi } from './purchases/purchases-api';
import { QUOTES_LIST, quoteSearch } from './quotes/quote-forms';
import { QuotesApi } from './quotes/quotes-api';
import { VENDORS_LIST, vendorSearch } from './vendors/vendor-forms';
import { VendorsApi } from './vendors/vendors-api';
import { rangeKey, refusedFilterOf } from './shared/list/list-filters';
import type { ListDescriptor, ListQuery } from './shared/list/list-types';

/**
 * Every list the API pages sends each of its filters under a name the list reads back: a read the API refuses for a
 * filter names the parameter as the API reads it (`taxRegime[0]`), and the list names the filter a person set from it
 * (`refusedFilterOf`, through the filter's `asked`, else its id). Each list's query holds every filter set; every
 * parameter its read sends, but the page, the search and the sort, must name one of its filters.
 */

const AN_ID = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const BESIDES = ['page', 'itemsPerPage', 'q', 'order'];

interface Paged {
  readonly name: string;
  readonly descriptor: ListDescriptor<never>;
  readonly read: (query: ListQuery) => Promise<unknown>;
}

const LISTS: readonly Paged[] = [
  {
    name: 'activity',
    descriptor: ACTIVITY_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(ActivityApi).entries('c1', activitySearch(query)),
  },
  {
    name: 'customers',
    descriptor: CUSTOMERS_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(CustomersApi).customers('c1', customerSearch(query)),
  },
  {
    name: 'delivery notes',
    descriptor: DELIVERY_NOTES_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(DeliveryNotesApi).notes('c1', deliveryNoteSearch(query)),
  },
  {
    name: 'expenses',
    descriptor: EXPENSES_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(ExpensesApi).expenses('c1', expenseSearch(query)),
  },
  {
    name: 'stock movements',
    descriptor: MOVEMENTS_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(InventoryApi).movements('c1', movementSearch(query)),
  },
  {
    name: 'stock',
    descriptor: STOCK_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(InventoryApi).levels('c1', stockSearch(query)),
  },
  {
    name: 'invoices',
    descriptor: INVOICES_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(InvoicesApi).invoices('c1', invoiceSearch(query)),
  },
  {
    name: 'portfolio',
    descriptor: PORTFOLIO_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(PortfolioApi).portfolio('c1', portfolioSearch(query)),
  },
  {
    name: 'platform companies',
    descriptor: companiesListOf(['TN'], 'fr') as ListDescriptor<never>,
    read: (query) => TestBed.inject(PlatformApi).companies(companySearch(query)),
  },
  {
    name: 'platform accounts',
    descriptor: ACCOUNTS_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(PlatformApi).accounts(accountSearch(query)),
  },
  {
    name: 'products',
    descriptor: PRODUCTS_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(ProductsApi).products('c1', productSearch(query)),
  },
  {
    name: 'purchases',
    descriptor: PURCHASES_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(PurchasesApi).orders('c1', purchaseOrderSearch(query)),
  },
  {
    name: 'quotes',
    descriptor: QUOTES_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(QuotesApi).quotes('c1', quoteSearch(query)),
  },
  {
    name: 'vendors',
    descriptor: VENDORS_LIST as ListDescriptor<never>,
    read: (query) => TestBed.inject(VendorsApi).vendors('c1', vendorSearch(query)),
  },
];

/** A query holding every filter the list declares: each facet its first option, each interval both ends, each picker an id. */
function everyFilterSet(descriptor: ListDescriptor<never>): ListQuery {
  const filters: Record<string, string> = {};
  for (const facet of descriptor.filters ?? []) filters[facet.id] = facet.options[0]?.value ?? 'x';
  for (const range of descriptor.ranges ?? []) {
    if (range.kind === 'day') {
      filters[rangeKey(range.id, 'from')] = '2026-01-01';
      filters[rangeKey(range.id, 'to')] = '2026-01-31';
    } else {
      filters[rangeKey(range.id, 'min')] = '1';
      filters[rangeKey(range.id, 'max')] = '2';
    }
  }
  for (const pick of descriptor.picks ?? []) filters[pick.id] = AN_ID;
  return { query: '', filters, sort: null, pageIndex: 0, pageSize: 25 };
}

/** The parameters a read sent, as a refusal names them: without an entry's index or an interval's end. */
function sentNames(params: HttpParams): string[] {
  return [...new Set(params.keys().map((key) => key.split(/[[.]/)[0]))].filter(
    (name) => !BESIDES.includes(name),
  );
}

describe('the filters every paged list sends', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it.each(LISTS)(
    'are each named so the $name list can say which one the API refused',
    async (list) => {
      const pending = list.read(everyFilterSet(list.descriptor)).catch(() => undefined);
      const [request] = http.match(() => true);
      const sent = sentNames(request.request.params);

      expect(sent.length).toBeGreaterThan(0);
      expect(sent.filter((name) => refusedFilterOf(list.descriptor, [name]) === null)).toEqual([]);

      request.flush({ member: [], totalItems: 0 });
      await pending;
    },
  );
});

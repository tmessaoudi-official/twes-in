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
import { provideQuietFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { CustomersFacade } from './customers-facade';
import { CustomersPage } from './customers-page';
import type { CustomerGroupRow, CustomerRow } from './customers-types';
import { exportEntry } from '../shared/testing/list-export';
import { RecordDeletes } from '../shared/records/record-deletes';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      customers: {
        title: 'Clients',
        add: 'Nouveau client',
        kinds: { company: 'Entreprise', individual: 'Particulier' },
        statuses: { active: 'Actif', inactive: 'Inactif' },
      },
    });
  }
}

const amel: CustomerRow = {
  id: 'k1',
  version: 1,
  number: 'CLI-0001',
  kind: 'individual',
  customerGroupId: 'g1',
  taxRegime: 'standard',
  name: 'Amel Trabelsi',
  legalName: null,
  identifiers: {},
  email: null,
  phone: null,
  website: null,
  billingAddress: { line1: null, line2: null, postalCode: null, city: 'Sousse', countryCode: 'TN' },
  shippingAddress: null,
  defaultTaxComponentIds: [],
  defaultDiscountRate: null,
  notes: null,
  isActive: false,
  customFields: {},
  mergedInto: null,
  toCheck: false,
  checkNote: null,
  checkReasons: [],
  missingIdentifiers: [],
};
const amelSousse: CustomerRow = { ...amel, id: 'k2', number: 'CLI-0002', name: 'Amel T.' };
const wholesalers: CustomerGroupRow = {
  id: 'g1',
  name: 'Grossistes',
  description: null,
  customerCount: 1,
  version: 1,
};

describe('CustomersPage', () => {
  const facade = {
    customers: signal<readonly CustomerRow[]>([amel, amelSousse]).asReadonly(),
    groups: signal<readonly CustomerGroupRow[]>([wholesalers]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    customFields: signal([]).asReadonly(),
    options: signal(null).asReadonly(),
    total: signal(2).asReadonly(),
    loadListContext: vi.fn(),
    loadPage: vi.fn(),
    exportUrl: vi.fn(
      (companyId: string, _search: unknown, format: string) =>
        `/api/companies/${companyId}/exports/customers.${format}`,
    ),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  const deletes = { offerMergeOf: vi.fn() };
  let fixture: ComponentFixture<CustomersPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    facade.loadListContext.mockReset().mockResolvedValue(undefined);
    facade.loadPage.mockReset().mockResolvedValue(undefined);
    auth.hasPermission.mockReset().mockReturnValue(true);
    deletes.offerMergeOf.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      imports: [CustomersPage],
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
        { provide: CustomersFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: RecordDeletes, useValue: deletes },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(CustomersPage);
    await settle();
  });

  it("lists the company's customers with their group, kind and status in words", () => {
    expect(facade.loadListContext).toHaveBeenCalledWith('c1');
    const row = q('customer-CLI-0001')?.textContent ?? '';
    expect(row).toContain('Amel Trabelsi');
    expect(row).toContain('Grossistes');
    expect(row).toContain('Particulier');
    expect(row).toContain('Inactif');
    expect(
      q('customer-CLI-0001')?.querySelector('app-status-badge')?.getAttribute('data-tone'),
    ).toBe('neutral');
    // The row's own name is the link now (design review finding 1).
    expect(q('list-link-k1')?.getAttribute('href')).toBe('/customers/k1');
  });

  it('asks the API for the first page of customers, by number', () => {
    expect(facade.loadPage).toHaveBeenCalledWith('c1', {
      page: 1,
      itemsPerPage: 25,
      q: '',
      kinds: [],
      groupIds: [],
      regimes: [],
      isActive: null,
      toCheck: null,
      intervals: {},
      order: { key: 'number', direction: 'asc' },
    });
  });

  it('reads the context and the page shown again when customers change elsewhere', async () => {
    facade.loadListContext.mockClear();
    facade.loadPage.mockClear();

    await announceSaved('customer', 'k9');

    expect(facade.loadListContext).toHaveBeenCalledWith('c1');
    expect(facade.loadPage).toHaveBeenCalledWith('c1', expect.objectContaining({ page: 1 }));
  });

  it('leads to the groups through the tabs of the customers screens', () => {
    expect(q('customers-tab')?.getAttribute('href')).toBe('/customers');
    expect(q('customer-groups-link')?.getAttribute('href')).toBe('/customers/groups');
    expect(q('customer-groups-link')?.closest('nav')).not.toBeNull();
  });

  it('offers what the list shows as a CSV or an Excel file, to a reader as to a writer', async () => {
    expect(exportEntry(fixture, 'customers-export', 'csv')?.getAttribute('data-address')).toBe(
      '/api/companies/c1/exports/customers.csv',
    );
    expect(exportEntry(fixture, 'customers-export', 'xlsx')?.getAttribute('data-address')).toBe(
      '/api/companies/c1/exports/customers.xlsx',
    );
    expect(facade.exportUrl).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ order: { key: 'number', direction: 'asc' } }),
      'csv',
    );

    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(CustomersPage);
    await settle();

    expect(exportEntry(fixture, 'customers-export', 'csv')).not.toBeNull();
  });

  it('offers a new customer to a writer only', async () => {
    expect(q('customer-add')?.getAttribute('href')).toBe('/customers/new');

    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(CustomersPage);
    await settle();

    expect(q('customer-add')).toBeNull();
    expect(q('list-link-k1')).not.toBeNull();
  });

  const tick = async (id: string): Promise<void> => {
    (q(`list-select-row-${id}`)!.querySelector('input') as HTMLInputElement).click();
    await settle();
  };

  it('offers to merge once two customers are ticked, the one merged away staying as an alias with its defaults moving, and clears the ticks after', async () => {
    await tick('k2');
    expect(q('customers-merge')).toBeNull();
    await tick('k1');

    q('customers-merge')!.click();
    await settle();

    expect(deletes.offerMergeOf).toHaveBeenCalledWith('c1', 'customer', ['k2', 'k1'], {
      settings: true,
      aliased: true,
    });
    expect(q('customers-merge')).toBeNull();
  });

  it('offers a writer who may not remove customers a new one but no ticks to merge with', async () => {
    auth.hasPermission.mockImplementation((permission: string) => permission !== 'customer.remove');
    fixture = TestBed.createComponent(CustomersPage);
    await settle();

    expect(q('customer-add')).not.toBeNull();
    expect(q('list-select-row-k1')).toBeNull();
  });

  it('keeps the ticks when the person merges nothing', async () => {
    deletes.offerMergeOf.mockResolvedValue(false);
    await tick('k2');
    await tick('k1');

    q('customers-merge')!.click();
    await settle();

    expect(q('customers-merge')).not.toBeNull();
  });

  it('lets nobody but a person who may change customers tick them', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(CustomersPage);
    await settle();

    expect(q('list-select-row-k1')).toBeNull();
  });
});

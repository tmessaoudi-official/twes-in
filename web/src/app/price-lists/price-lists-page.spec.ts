// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { Session } from '../shared/session/session';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';
import { PriceListsFacade } from './price-lists-facade';
import { PriceListsPage } from './price-lists-page';
import type { PriceListRow } from './price-lists-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      price_lists: {
        scopes: {
          everyone: 'Tous les clients',
          group: 'Un groupe de clients',
          customer: 'Un client',
        },
        always: 'Toujours',
        active: 'Actif',
        inactive: 'Désactivé',
        errors: { name_taken: 'Un autre tarif porte déjà ce nom.' },
      },
    });
  }
}

const everyone: PriceListRow = {
  id: 'l1',
  name: 'Public',
  customerGroupId: null,
  customerId: null,
  validFrom: null,
  validTo: null,
  isActive: true,
  itemCount: 0,
  items: null,
};
const wholesale: PriceListRow = {
  ...everyone,
  id: 'l2',
  name: 'Gros',
  customerGroupId: 'g1',
  validFrom: '2026-01-01',
  validTo: '2026-12-31',
  itemCount: 1,
};

describe('PriceListsPage', () => {
  const lists = signal<readonly PriceListRow[]>([everyone, wholesale]);
  const busy = signal(false);
  const error = signal<string | null>(null);
  const permissions = signal(true);
  const facade = {
    lists: lists.asReadonly(),
    groups: signal([
      { id: 'g1', name: 'Revendeurs', description: null, customerCount: 3 },
    ]).asReadonly(),
    busy: busy.asReadonly(),
    error: error.asReadonly(),
    load: vi.fn(),
    open: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    remove: vi.fn(),
    clearError: vi.fn(),
    pickProducts: vi.fn(async () => [{ id: 'p1', code: 'REF-1', name: 'Stylo' }]),
    pickCustomers: vi.fn(async () => []),
    pickCustomerIds: vi.fn(async () => []),
  };

  beforeEach(async () => {
    Object.values(facade)
      .filter((value) => typeof value === 'function' && 'mockReset' in value)
      .forEach((fn) => (fn as ReturnType<typeof vi.fn>).mockReset().mockResolvedValue(true));
    facade.pickProducts.mockResolvedValue([{ id: 'p1', code: 'REF-1', name: 'Stylo' }]);
    facade.pickCustomerIds.mockResolvedValue([]);
    lists.set([everyone, wholesale]);
    permissions.set(true);
    await TestBed.configureTestingModule({
      imports: [PriceListsPage],
      providers: [
        provideRouter([]),
        ...provideQuietFeedback(),
        { provide: PriceListsFacade, useValue: facade },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
            hasPermission: () => permissions(),
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(PriceListsPage);
    await fixture.whenStable();
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    const settle = async () => {
      fixture.detectChanges();
      await fixture.whenStable();
      fixture.detectChanges();
    };
    return { fixture, query, settle };
  }

  it('loads the lists of the working company and shows who each is for and when', async () => {
    const { query } = await render();

    expect(facade.load).toHaveBeenCalledWith('c1');
    expect(query('price-list-Public')!.textContent).toContain('Tous les clients');
    expect(query('price-list-Public')!.textContent).toContain('Toujours');
    const line = query('price-list-Gros')!.textContent!.replace(/\s+/g, ' ');
    expect(line).toContain('Un groupe de clients · Revendeurs');
    expect(line).toContain('2026-01-01 → 2026-12-31');
  });

  it('offers no way to add a list to someone who may not write', async () => {
    permissions.set(false);

    const { query } = await render();

    expect(query('price-list-add')).toBeNull();
  });

  it('creates a list for everyone with a price row and says it was saved', async () => {
    const { query, settle } = await render();

    query<HTMLButtonElement>('price-list-add')!.click();
    await settle();
    const name = query<HTMLInputElement>('price-list-name')!;
    name.value = 'Promo';
    name.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('price-row-add')!.click();
    await settle();
    const price = query<HTMLInputElement>('price-row-0-price')!;
    price.value = '9.5';
    price.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('price-list-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        name: 'Promo',
        customerGroupId: null,
        customerId: null,
        validFrom: null,
        validTo: null,
        isActive: true,
        items: [expect.objectContaining({ productId: '', unitPriceNet: '9.5' })],
      }),
    );
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      { kind: 'success', key: 'price_lists.saved', params: undefined },
    ]);
    expect(query('price-list-form')).toBeNull();
  });

  it('opens a list with the prices it holds and sends the whole list back, its group kept', async () => {
    facade.open.mockResolvedValue({
      ...wholesale,
      items: [
        {
          productId: 'p1',
          productReference: 'REF-1',
          productName: 'Stylo',
          minQuantity: '10.000',
          unitPriceNet: '0.9000',
        },
      ],
    });
    const { query, settle } = await render();

    query<HTMLButtonElement>('row-action-edit-l2')!.click();
    await settle();

    expect(facade.open).toHaveBeenCalledWith('c1', 'l2');
    expect(query<HTMLInputElement>('price-list-name')!.value).toBe('Gros');
    expect(query('price-row-0')).not.toBeNull();
    query<HTMLButtonElement>('price-list-save')!.click();
    await settle();

    expect(facade.revise).toHaveBeenCalledWith(
      'c1',
      'l2',
      expect.objectContaining({
        customerGroupId: 'g1',
        validFrom: '2026-01-01',
        validTo: '2026-12-31',
        items: [
          expect.objectContaining({
            productId: 'p1',
            minQuantity: '10.000',
            unitPriceNet: '0.9000',
          }),
        ],
      }),
    );
  });

  it('removes a price row and says when none is left', async () => {
    const { query, settle } = await render();

    query<HTMLButtonElement>('price-list-add')!.click();
    await settle();
    query<HTMLButtonElement>('price-row-add')!.click();
    await settle();
    expect(query('price-rows-empty')).toBeNull();
    query<HTMLButtonElement>('price-row-0-remove')!.click();
    await settle();

    expect(query('price-rows-empty')).not.toBeNull();
  });

  it('keeps the form open and names the refusal when the name is taken', async () => {
    facade.create.mockResolvedValue(false);
    error.set('name_taken');
    const { query, settle } = await render();

    query<HTMLButtonElement>('price-list-add')!.click();
    await settle();
    query<HTMLButtonElement>('price-list-save')!.click();
    await settle();

    expect(query('price-list-form')).not.toBeNull();
    expect(query('price-lists-error')!.textContent).toContain('Un autre tarif porte déjà ce nom.');
    error.set(null);
  });

  it('shows the customer picker only for a list for one customer', async () => {
    const { query, settle } = await render();

    query<HTMLButtonElement>('price-list-add')!.click();
    await settle();
    expect(query('price-list-customer')).toBeNull();
    const scope = query<HTMLSelectElement>('price-list-scope')!;
    scope.value = 'customer';
    scope.dispatchEvent(new Event('change'));
    await settle();

    expect(query('price-list-customer')).not.toBeNull();
    expect(query('price-list-group')).toBeNull();
  });
});

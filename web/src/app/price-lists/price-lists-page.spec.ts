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
import type { ProductFigures } from './price-lists-figures';
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
        prices: {
          figures: 'Prix actuel {{shelf}} · {{change}}',
          figures_cost: 'Prix actuel {{shelf}} · coût {{cost}} · marge {{margin}} · {{change}}',
          below_cost: 'Sous le coût',
        },
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
  const figures = signal<ReadonlyMap<string, ProductFigures>>(new Map());
  const facade = {
    lists: lists.asReadonly(),
    groups: signal([
      { id: 'g1', name: 'Revendeurs', description: null, customerCount: 3 },
    ]).asReadonly(),
    busy: busy.asReadonly(),
    error: error.asReadonly(),
    figures: figures.asReadonly(),
    loadFigures: vi.fn(),
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
    figures.set(new Map());
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

  it('takes the validity days as typed in the company format, sends them as ISO, and refuses what is not a day', async () => {
    const { query, settle } = await render();

    query<HTMLButtonElement>('price-list-add')!.click();
    await settle();
    const name = query<HTMLInputElement>('price-list-name')!;
    name.value = 'Soldes';
    name.dispatchEvent(new Event('input'));
    const from = query<HTMLInputElement>('price-list-from')!;
    from.value = '31/02/2026';
    from.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('price-row-add')!.click();
    await settle();
    query<HTMLButtonElement>('price-list-save')!.click();
    await settle();
    expect(facade.create).not.toHaveBeenCalled();

    from.value = '5/1/2027';
    from.dispatchEvent(new Event('input'));
    await settle();
    query<HTMLButtonElement>('price-list-save')!.click();
    await settle();
    expect(facade.create).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ validFrom: '2027-01-05', validTo: null }),
    );
  });

  it('creates a list for a customer group, chosen from the groups the company has', async () => {
    const { query, settle } = await render();

    query<HTMLButtonElement>('price-list-add')!.click();
    await settle();
    const name = query<HTMLInputElement>('price-list-name')!;
    name.value = 'Revendeurs été';
    name.dispatchEvent(new Event('input'));
    query('price-list-scope')!.click();
    await settle();
    document.body
      .querySelector<HTMLElement>('[data-testid="price-list-scope-option-group"]')!
      .click();
    await settle();
    expect(query('price-list-group')).not.toBeNull();
    query('price-list-group')!.click();
    await settle();
    document.body.querySelector<HTMLElement>('[data-testid="price-list-group-option-g1"]')!.click();
    await settle();
    query<HTMLButtonElement>('price-row-add')!.click();
    await settle();
    const price = query<HTMLInputElement>('price-row-0-price')!;
    price.value = '8';
    price.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('price-list-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ name: 'Revendeurs été', customerGroupId: 'g1', customerId: null }),
    );
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
    query('price-list-scope')!.click();
    await settle();
    document.body
      .querySelector<HTMLElement>('[data-testid="price-list-scope-option-customer"]')!
      .click();
    await settle();

    expect(query('price-list-customer')).not.toBeNull();
    expect(query('price-list-group')).toBeNull();
  });

  describe("starting a price from the product's own", () => {
    interface Row {
      controls: { unitPriceNet: { value: string; setValue(value: string): void } };
    }
    interface Inside {
      form: { controls: { items: { at(index: number): Row } } };
      chooseProduct(row: Row, option: { id: string; code: string; name: string } | null): void;
    }
    const stylo = { id: 'p1', code: 'REF-1', name: 'Stylo' };

    async function withARow() {
      const view = await render();
      view.query<HTMLButtonElement>('price-list-add')!.click();
      await view.settle();
      view.query<HTMLButtonElement>('price-row-add')!.click();
      await view.settle();
      const page = view.fixture.componentInstance as unknown as Inside;
      return { ...view, page, row: page.form.controls.items.at(0) };
    }

    it("fills the price with the product's price when it is picked, to be edited", async () => {
      figures.set(new Map([['p1', { unitPriceNet: '890.0000', costPrice: '534.0000' }]]));
      const { page, row, settle, query } = await withARow();

      page.chooseProduct(row, stylo);
      await settle();

      expect(row.controls.unitPriceNet.value).toBe('890.0000');
      expect(query('price-row-0-figures')?.textContent).toContain('coût');
    });

    it('keeps a price already typed on the row when a product is picked', async () => {
      figures.set(new Map([['p1', { unitPriceNet: '890.0000', costPrice: '534.0000' }]]));
      const { page, row, settle } = await withARow();
      row.controls.unitPriceNet.setValue('700');

      page.chooseProduct(row, stylo);
      await settle();

      expect(row.controls.unitPriceNet.value).toBe('700');
    });

    it('warns when the price is under the cost, and only then', async () => {
      figures.set(new Map([['p1', { unitPriceNet: '890.0000', costPrice: '534.0000' }]]));
      const { page, row, settle, query } = await withARow();
      page.chooseProduct(row, stylo);
      await settle();
      expect(query('price-row-0-below-cost')).toBeNull();

      row.controls.unitPriceNet.setValue('400');
      await settle();

      expect(query('price-row-0-below-cost')?.textContent).toContain('Sous le coût');
    });

    it('shows no cost and no warning to someone who may not read costs', async () => {
      figures.set(new Map([['p1', { unitPriceNet: '890.0000', costPrice: null }]]));
      const { page, row, settle, query } = await withARow();
      page.chooseProduct(row, stylo);
      row.controls.unitPriceNet.setValue('400');
      await settle();

      const line = query('price-row-0-figures')?.textContent ?? '';
      expect(line).toContain('Prix actuel');
      expect(line).not.toContain('coût');
      expect(query('price-row-0-below-cost')).toBeNull();
    });
  });
});

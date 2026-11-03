// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../../auth/auth-facade';
import { TextsParts } from '../../shared/i18n/texts-part';
import { LiveChanges } from '../../shared/realtime/live-changes';
import { Session } from '../../shared/session/session';
import { BrowserStorageSettings } from '../../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../../shared/settings/settings-facade';
import { InventoryApi } from '../inventory-api';
import { StockHome } from './stock-home';

const TEXTS = {
  home: {
    stock: {
      title: 'Stock',
      value: 'Valeur du stock',
      see: 'Voir',
      value_caption: 'au coût moyen de ce qui est entré',
      value_estimated: 'dont une part estimée',
      negative: '{{count}} sous zéro',
      expired: '{{count}} périmés',
      clear: 'Ni stock sous zéro ni lot périmé.',
      error: 'Le stock n’a pas pu être lu.',
    },
  },
};

describe('StockHome', () => {
  let costs = false;
  const reloadOn = vi.fn();
  const open = vi.fn();
  const levels = vi.fn();
  const valuation = vi.fn();

  beforeEach(() => {
    costs = false;
    reloadOn.mockReset();
    open.mockReset().mockResolvedValue(undefined);
    levels
      .mockReset()
      .mockImplementation(async (_company: string, search: { negative: string | null }) => ({
        rows: [],
        total: search.negative === 'yes' ? 2 : 0,
      }));
    valuation.mockReset().mockResolvedValue({ total: '12000.500', estimated: true, lines: [] });
    TestBed.configureTestingModule({
      imports: [StockHome],
      providers: [
        provideRouter([]),
        provideTranslateService({ lang: 'fr' }),
        {
          provide: AuthFacade,
          useValue: {
            me: signal({
              user: { id: 'u1' },
              company: { id: 'c1', currency: 'TND', countryCode: 'TN' },
            }).asReadonly(),
            hasPermission: (permission: string) => costs && permission === 'product.cost.read',
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: LiveChanges, useValue: { reloadOn } },
        { provide: TextsParts, useValue: { open } },
        { provide: InventoryApi, useValue: { levels, valuation } },
      ],
    });
    TestBed.inject(TranslateService).setTranslation('fr', TEXTS);
  });

  async function render() {
    const fixture = TestBed.createComponent(StockHome);
    await fixture.whenStable();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    return {
      fixture,
      el,
      find: (id: string) => el.querySelector<HTMLElement>(`[data-testid="${id}"]`),
    };
  }

  it('opens the home’s texts and reads the stock again when a teammate moves it', async () => {
    await render();
    expect(open).toHaveBeenCalledWith('home');
    expect(reloadOn.mock.calls[0]?.[0]).toEqual(['stock', 'delivery_note', 'invoice', 'product']);
  });

  it('never asks what the stock is worth without the right to read costs, and shows what is wrong in it', async () => {
    const { find } = await render();

    expect(valuation).not.toHaveBeenCalled();
    expect(find('home-stock')?.querySelector('[data-part="label"]')?.textContent?.trim()).toBe(
      'Stock',
    );
    expect(find('home-stock')?.querySelector('[data-part="figure"]')).toBeNull();
    const negative = find('home-stock-negative');
    expect(negative?.textContent?.trim()).toBe('2 sous zéro');
    expect(negative?.getAttribute('href')).toBe('/stock?negative=yes');
    expect(find('home-stock-expired')).toBeNull();
  });

  it('shows what the stock is worth to a reader of costs, said to be partly estimated, and leads to the valuation', async () => {
    costs = true;
    const { find } = await render();

    expect(valuation).toHaveBeenCalledWith('c1');
    const tile = find('home-stock')!;
    expect(tile.querySelector('[data-part="label"]')?.textContent?.trim()).toBe('Valeur du stock');
    expect(tile.querySelector('[data-part="figure"]')?.textContent).toContain('TND');
    expect(tile.querySelector('[data-part="caption"]')?.textContent?.trim()).toBe(
      'dont une part estimée',
    );
    expect(tile.querySelector('[data-part="see"]')?.getAttribute('href')).toBe('/stock/valuation');
  });

  it('says the stock is clear when nothing is below zero or expired', async () => {
    levels.mockResolvedValue({ rows: [], total: 0 });
    const { find } = await render();

    expect(find('home-stock')?.querySelector('[data-part="blank"]')?.textContent?.trim()).toBe(
      'Ni stock sous zéro ni lot périmé.',
    );
  });

  it('says the stock could not be read rather than drawing nothing', async () => {
    levels.mockRejectedValue(new Error('down'));
    const { find } = await render();

    expect(find('home-stock-error')?.getAttribute('role')).toBe('alert');
    expect(find('home-stock')).toBeNull();
  });
});

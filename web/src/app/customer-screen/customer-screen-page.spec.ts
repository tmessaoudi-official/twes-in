// SPDX-License-Identifier: AGPL-3.0-or-later

import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CustomerView } from '../shared/customer-view/customer-view';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { PageMemoryStorage } from '../shared/settings/settings-facade';
import { CUSTOMER_SCREEN_PLACE_STORAGE } from './customer-screen-place';
import { CustomerScreenApi } from './customer-screen-api';
import { CustomerScreenPage } from './customer-screen-page';
import type { ScreenPlace, ScreenProduct } from './customer-screen-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const screw: ScreenProduct = {
  id: 'p1',
  name: 'Vis 6x40',
  reference: 'VIS-6X40',
  barcode: '3017620422003',
  finalPrice: '21.420',
  photo: '/api/companies/c1/customer-screen/products/p1/photos/ph1?size=large',
  inStock: true,
  promotions: [
    { price: '17.850', minQuantity: '10.000', startsOn: '2026-10-01', endsOn: '2026-10-09' },
    { price: '19.000', minQuantity: '1.000', startsOn: null, endsOn: '2026-10-09' },
  ],
};

const nut: ScreenProduct = {
  id: 'p2',
  name: 'Écrou',
  reference: 'ECR-6',
  barcode: null,
  finalPrice: '5.000',
  photo: null,
  inStock: null,
  promotions: [],
};

describe('CustomerScreenPage', () => {
  const api = { find: vi.fn(), places: vi.fn() };
  let storage: PageMemoryStorage;
  const main: ScreenPlace = { id: 'e1', code: '000', name: 'Acme', isDefault: true };
  const sfax: ScreenPlace = { id: 'e2', code: '001', name: 'Agence de Sfax', isDefault: false };
  const view = { on: vi.fn(), leave: vi.fn() };
  const router = { navigateByUrl: vi.fn() };
  let fixture: ComponentFixture<CustomerScreenPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const all = (testId: string): HTMLElement[] =>
    Array.from(fixture.nativeElement.querySelectorAll(`[data-testid="${testId}"]`));

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function look(words: string): Promise<void> {
    const input = q('customer-screen-words') as HTMLInputElement;
    input.value = words;
    input.dispatchEvent(new Event('input'));
    await settle();
    (q('customer-screen-form') as HTMLFormElement).dispatchEvent(new Event('submit'));
    await settle();
  }

  beforeEach(async () => {
    api.find.mockReset();
    api.places.mockReset().mockResolvedValue([main]);
    storage = new PageMemoryStorage();
    view.on.mockReset().mockResolvedValue(true);
    view.leave.mockReset().mockResolvedValue(true);
    router.navigateByUrl.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      imports: [CustomerScreenPage],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: CustomerScreenApi, useValue: api },
        { provide: CUSTOMER_SCREEN_PLACE_STORAGE, useFactory: () => storage },
        { provide: CustomerView, useValue: view },
        { provide: Router, useValue: router },
        {
          provide: Session,
          useValue: {
            me: () => ({
              user: { id: 'u1' },
              company: { id: 'c1', currency: 'TND' },
            }),
          },
        },
        {
          provide: FormatFacade,
          useValue: {
            amount: (value: string) => `~${value}`,
            day: (value: string) => `d${value}`,
            decimal: (value: string) => value,
          },
        },
      ],
    });
    fixture = TestBed.createComponent(CustomerScreenPage);
    await settle();
  });

  afterEach(() => fixture?.destroy());

  it('locks the tab on itself as it opens, and waits with a welcome', () => {
    expect(view.on).toHaveBeenCalledTimes(1);
    expect(q('customer-screen-welcome')).not.toBeNull();
  });

  it('shows what was found: the name, the final price, our reference and barcode, and stock when it is said', async () => {
    api.find.mockResolvedValue([screw, nut]);

    await look('vis');

    expect(api.find).toHaveBeenCalledWith('c1', 'vis', null);
    expect(all('customer-screen-name').map((e) => e.textContent?.trim())).toEqual([
      'Vis 6x40',
      'Écrou',
    ]);
    expect(all('customer-screen-price')[0].textContent).toContain('~21.420 TND');
    expect(all('customer-screen-codes')[0].textContent).toContain('VIS-6X40');
    expect(all('customer-screen-codes')[0].textContent).toContain('3017620422003');
    expect(all('customer-screen-codes')[1].textContent).not.toContain('customer_screen.barcode');
    // One product's stock is said, the other's is not: a company that keeps its shelves to itself shows no chip.
    expect(all('customer-screen-stock').length).toBe(1);
    expect(all('customer-screen-stock')[0].textContent).toContain('customer_screen.in_stock');
  });

  it('shows a product’s main photo the screen may read, and leaves the place empty where it has none', async () => {
    api.find.mockResolvedValue([screw, nut]);

    await look('vis');

    const photos = all('customer-screen-photo');
    expect(photos.length).toBe(1);
    expect(photos[0].getAttribute('src')).toBe(
      '/api/companies/c1/customer-screen/products/p1/photos/ph1?size=large',
    );
    expect(photos[0].getAttribute('alt')).toBe('');
  });

  it('says out of stock as plainly as in stock', async () => {
    api.find.mockResolvedValue([{ ...screw, inStock: false }]);

    await look('vis');

    expect(q('customer-screen-stock')?.textContent).toContain('customer_screen.out_of_stock');
  });

  it('shows each promotion with its price, its minimum quantity and its dates', async () => {
    api.find.mockResolvedValue([screw]);

    await look('vis');

    const offers = all('customer-screen-promotion').map((e) => e.textContent ?? '');
    expect(offers).toHaveLength(2);
    expect(offers[0]).toContain('~17.850 TND');
    expect(offers[0]).toContain('customer_screen.from_quantity');
    expect(offers[0]).toContain('customer_screen.between');
    // One unit is no condition worth stating, and a list with an end only says until when.
    expect(offers[1]).toContain('~19.000 TND');
    expect(offers[1]).not.toContain('customer_screen.from_quantity');
    expect(offers[1]).toContain('customer_screen.until');
  });

  it('says so when nothing answers the words, and keeps the field ready for the next scan', async () => {
    api.find.mockResolvedValue([]);

    await look('nope');

    expect(q('customer-screen-none')).not.toBeNull();
    expect(q('customer-screen-results')).toBeNull();
    expect((q('customer-screen-words') as HTMLInputElement).value).toBe('');
  });

  // Audit 2026-10-06, C-7: the screen faces a customer, so a price or stock changed elsewhere shows without a reload.
  it('asks its last search again when a price, a product or the stock changes elsewhere', async () => {
    api.find.mockResolvedValue([screw]);
    await look('vis');
    for (const kind of ['price_list', 'product', 'stock', 'delivery_note', 'invoice']) {
      api.find.mockClear();
      api.find.mockResolvedValue([{ ...screw, inStock: false }]);
      await announceSaved(kind, 'x1');
      await settle();
      expect(api.find, kind).toHaveBeenCalledWith('c1', 'vis', null);
    }
  });

  it('asks nothing again before a first search', async () => {
    await announceSaved('price_list', 'x1');
    expect(api.find).not.toHaveBeenCalled();
  });

  it('does not search for nothing', async () => {
    await look('   ');

    expect(api.find).not.toHaveBeenCalled();
    expect(q('customer-screen-welcome')).not.toBeNull();
  });

  it('drops a list it can no longer vouch for when a search fails, instead of leaving an old answer up', async () => {
    api.find.mockResolvedValueOnce([screw]);
    await look('vis');
    expect(q('customer-screen-results')).not.toBeNull();

    api.find.mockRejectedValueOnce(new Error('network'));
    await look('ecrou');

    expect(q('customer-screen-failed')).not.toBeNull();
    expect(q('customer-screen-results')).toBeNull();
  });

  it('keeps the latest search when an earlier, slower one answers after it', async () => {
    let slow: (rows: ScreenProduct[]) => void = () => undefined;
    api.find.mockReturnValueOnce(new Promise<ScreenProduct[]>((resolve) => (slow = resolve)));
    api.find.mockResolvedValueOnce([nut]);

    await look('vis');
    await look('ecrou');
    slow([screw]);
    await settle();

    expect(all('customer-screen-name').map((e) => e.textContent?.trim())).toEqual(['Écrou']);
  });

  it('says so and goes home when the API would not hold the sign-in on the screen', async () => {
    fixture.destroy();
    view.on.mockResolvedValueOnce(false);
    fixture = TestBed.createComponent(CustomerScreenPage);
    await settle();

    const said = (TestBed.inject(Feedback) as RecordedFeedback).said.map((toast) => toast.key);
    expect(said).toContain('customer_screen.hold_failed');
    expect(router.navigateByUrl).toHaveBeenCalledWith('/');
  });

  it('leaves for the home page only once the person proved who they are', async () => {
    view.leave.mockResolvedValueOnce(false);
    (q('customer-screen-leave') as HTMLButtonElement).click();
    await settle();
    expect(router.navigateByUrl).not.toHaveBeenCalled();

    view.leave.mockResolvedValueOnce(true);
    (q('customer-screen-leave') as HTMLButtonElement).click();
    await settle();
    expect(router.navigateByUrl).toHaveBeenCalledWith('/');
  });

  // Audit 2026-10-06, B-13: the screen stands at one establishment and says that establishment's own stock.
  describe('at a company with several establishments', () => {
    async function reopen(): Promise<void> {
      fixture.destroy();
      fixture = TestBed.createComponent(CustomerScreenPage);
      await settle();
      await settle();
    }

    it('asks where it stands before it looks anything up, and remembers it for the company', async () => {
      api.places.mockResolvedValue([main, sfax]);
      await reopen();

      expect(q('customer-screen-places')).not.toBeNull();
      expect(q('customer-screen-form')).toBeNull();
      // Nothing invites a scan the screen cannot take yet.
      expect(q('customer-screen-welcome')).toBeNull();
      q('customer-screen-place-001')!.click();
      await settle();

      expect(q('customer-screen-places')).toBeNull();
      expect(q('customer-screen-place')!.textContent).toContain('Agence de Sfax');
      expect(JSON.parse(storage.getItem('twes.customer-screen.place') ?? '{}')).toEqual({
        c1: 'e2',
      });
      api.find.mockResolvedValue([screw]);
      await look('vis');
      expect(api.find).toHaveBeenCalledWith('c1', 'vis', 'e2');
    });

    it('stands where it stood last time, and is moved on request', async () => {
      api.places.mockResolvedValue([main, sfax]);
      storage.setItem('twes.customer-screen.place', JSON.stringify({ c1: 'e2', c9: 'e9' }));
      await reopen();

      expect(q('customer-screen-places')).toBeNull();
      expect(q('customer-screen-place')!.textContent).toContain('Agence de Sfax');
      q('customer-screen-place-change')!.click();
      await settle();
      expect(q('customer-screen-places')).not.toBeNull();
    });

    it("asks again when the place it remembers is no longer one of the company's", async () => {
      api.places.mockResolvedValue([main, sfax]);
      storage.setItem('twes.customer-screen.place', JSON.stringify({ c1: 'gone' }));
      await reopen();

      expect(q('customer-screen-places')).not.toBeNull();
    });

    // Audit 2026-10-06, N-d: a place deleted while the screen stands there answers its stock as « not said »; the
    // screen forgets it and asks again rather than going on silently.
    it('asks again when the place it stands at goes away while it is open', async () => {
      api.places.mockResolvedValue([main, sfax]);
      storage.setItem('twes.customer-screen.place', JSON.stringify({ c1: 'e2' }));
      await reopen();
      api.places.mockResolvedValue([main, { ...sfax, id: 'e3', name: 'Agence de Sousse' }]);
      api.find.mockResolvedValue([{ ...screw, inStock: null }]);

      await look('vis');
      await settle();

      expect(q('customer-screen-places')).not.toBeNull();
      expect(JSON.parse(storage.getItem('twes.customer-screen.place') ?? '{}')).toEqual({});
    });

    it('keeps its place when stock is simply not said there', async () => {
      api.places.mockResolvedValue([main, sfax]);
      storage.setItem('twes.customer-screen.place', JSON.stringify({ c1: 'e2' }));
      await reopen();
      api.find.mockResolvedValue([{ ...screw, inStock: null }]);

      await look('vis');
      await settle();

      expect(q('customer-screen-places')).toBeNull();
      expect(q('customer-screen-place')!.textContent).toContain('Agence de Sfax');
    });

    it('asks nothing of a company with one establishment', async () => {
      api.places.mockResolvedValue([main]);
      await reopen();

      expect(q('customer-screen-places')).toBeNull();
      expect(q('customer-screen-place')).toBeNull();
      expect(q('customer-screen-form')).not.toBeNull();
    });
  });
});

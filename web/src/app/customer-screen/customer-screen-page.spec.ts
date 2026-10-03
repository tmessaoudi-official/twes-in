// SPDX-License-Identifier: AGPL-3.0-or-later

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
import { CustomerScreenApi } from './customer-screen-api';
import { CustomerScreenPage } from './customer-screen-page';
import type { ScreenProduct } from './customer-screen-types';

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
  inStock: null,
  promotions: [],
};

describe('CustomerScreenPage', () => {
  const api = { find: vi.fn() };
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
    view.on.mockReset();
    view.leave.mockReset().mockResolvedValue(true);
    router.navigateByUrl.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      imports: [CustomerScreenPage],
      providers: [
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: CustomerScreenApi, useValue: api },
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

    expect(api.find).toHaveBeenCalledWith('c1', 'vis');
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
});

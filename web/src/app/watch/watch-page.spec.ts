// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { WatchFacade } from './watch-facade';
import { WatchPage } from './watch-page';
import type { WatchList } from './watch-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      watch: {
        none: 'Rien à surveiller.',
        kinds: {
          'invoices.late_customer':
            '{{customer}} : {{amount}} {{currency}} en retard de {{days}} jours',
          'stock.lot_expiring': 'Lot {{lot}} de {{product}} : {{quantity}}, le {{expiresOn}}',
          'stock.lot_expired': 'Lot {{lot}} de {{product}} périmé',
        },
      },
    });
  }
}

const list: WatchList = {
  count: 2,
  items: [
    {
      kind: 'invoices.late_customer',
      subjectId: 'c1',
      params: { customer: 'Carthage', invoices: 1, amount: '1190.000', currency: 'TND', days: 40 },
    },
    {
      kind: 'stock.lot_expiring',
      subjectId: 'p1',
      params: {
        product: 'Colle',
        reference: 'COLLE',
        lot: 'L-1',
        expiresOn: '2026-10-05',
        quantity: '3.000',
        days: 10,
      },
    },
  ],
};

// docs/SPEC.md § 7, 2026-09-24 12:10: « À surveiller », the conditions true now, each with its figure and a link.
describe('WatchPage', () => {
  const current = signal<WatchList | null>(null);
  const facade = {
    list: current.asReadonly(),
    error: signal(null).asReadonly(),
    load: vi.fn(),
  };
  const live = { reloadOn: vi.fn() };
  let fixture: ComponentFixture<WatchPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(WatchPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    current.set(list);
    facade.load.mockReset().mockResolvedValue(undefined);
    live.reloadOn.mockReset();
    TestBed.configureTestingModule({
      imports: [WatchPage],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: WatchFacade, useValue: facade },
        { provide: LiveChanges, useValue: live },
        { provide: AuthFacade, useValue: { me: () => ({ company: { id: 'k1' } }) } },
        { provide: FormatFacade, useValue: { locale: signal('fr-FR') } },
      ],
    });
  });

  it('reads the list for the working company and reads it again when what it watches changes', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('k1');
    const [kinds, reload] = live.reloadOn.mock.calls[0]!;
    expect(kinds).toEqual(expect.arrayContaining(['invoice', 'payment', 'stock', 'setting']));
    reload();
    expect(facade.load).toHaveBeenCalledTimes(2);
  });

  it('says each condition in a sentence, its figures in the locale, with a link to what it is about', async () => {
    await open();

    const late = q('watch-item-0')!;
    expect(late.textContent?.replace(/\s/g, '')).toContain('Carthage:1190,000TNDenretardde40jours');
    expect(late.querySelector('a')?.getAttribute('href')).toBe(
      '/invoices?status=overdue&q=Carthage',
    );

    const lot = q('watch-item-1')!;
    expect(lot.textContent).toContain('Lot L-1 de Colle : 3, le 05/10/2026');
    expect(lot.querySelector('a')?.getAttribute('href')).toBe('/products/p1');
  });

  it('says a lot already past its date is expired', async () => {
    current.set({
      count: 1,
      items: [{ ...list.items[1]!, params: { ...list.items[1]!.params, days: -2 } }],
    });
    await open();

    expect(q('watch-item-0')?.textContent).toContain('Lot L-1 de Colle périmé');
  });

  it('says there is nothing to watch when nothing is', async () => {
    current.set({ count: 0, items: [] });
    await open();

    expect(q('watch-none')?.textContent).toContain('Rien à surveiller.');
    expect(q('watch-item-0')).toBeNull();
  });
});

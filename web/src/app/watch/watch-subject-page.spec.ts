// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { SESSION_KINDS } from '../shared/session/session-kinds';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { WatchFacade } from './watch-facade';
import { WatchSubjectPage } from './watch-subject-page';
import type { WatchRow, WatchSummary } from './watch-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      invoices: { portfolio: { open: 'Portefeuille chèques et traites' } },
      watch: {
        none: 'Rien.',
        subjects: {
          stock: {
            below_zero: {
              hint: 'Comptez ce qui s’y trouve.',
              hint_reader: 'Il revient à qui peut modifier le stock.',
            },
          },
        },
        elsewhere: {
          below_zero: 'Tout le stock sous zéro',
          sold_without_stock: 'Les sorties sans stock',
        },
      },
    });
  }
}

const summary: WatchSummary = {
  count: 3,
  subjects: [
    { kind: 'invoices.instruments_due', count: 2 },
    { kind: 'invoices.late_customer', count: 1 },
  ],
};

// docs/SPEC.md § 7, audit B-2: the cheques fallen due lead to the whole portfolio, which no other screen linked to.
describe('WatchSubjectPage', () => {
  let fixture: ComponentFixture<WatchSubjectPage>;
  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(
    kind: string,
    rows: readonly WatchRow[] = [],
    may: (permission: string) => boolean = () => true,
  ): Promise<void> {
    TestBed.configureTestingModule({
      imports: [WatchSubjectPage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of(convertToParamMap({ kind })),
            queryParamMap: of(convertToParamMap({})),
            snapshot: {
              paramMap: convertToParamMap({ kind }),
              queryParamMap: convertToParamMap({}),
            },
          },
        },
        {
          provide: WatchFacade,
          useValue: {
            summary: signal(summary).asReadonly(),
            rows: signal({
              status: 'ready',
              page: { rows, total: rows.length },
            }).asReadonly(),
            load: vi.fn().mockResolvedValue(undefined),
            loadRows: vi.fn().mockResolvedValue(undefined),
          },
        },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
            hasPermission: may,
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: LiveChanges, useValue: { reloadOn: vi.fn() } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(WatchSubjectPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  it('leads from the cheques fallen due to the whole portfolio', async () => {
    await open('invoices.instruments_due');

    const link = q('watch-elsewhere');
    expect(link?.getAttribute('href')).toBe('/instruments');
    expect(link?.textContent).toContain('Portefeuille chèques et traites');
  });

  it('leads from the drafts waiting to be validated to the delivery notes list showing its drafts', async () => {
    await open('delivery_note.to_validate');

    expect(q('watch-elsewhere')?.getAttribute('href')).toBe('/delivery-notes?status=draft');
  });

  it('leads from the orders whose goods are due to the orders list showing the open ones', async () => {
    await open('purchase_order.to_receive');

    expect(q('watch-elsewhere')?.getAttribute('href')).toBe(
      '/purchases?status=sent,partly_received',
    );
  });

  it('reads its rows again when the session changes, which decides whether the reader may see the subject', async () => {
    await open('delivery_note.to_validate');

    const reloadOn = vi.mocked(TestBed.inject(LiveChanges).reloadOn);
    expect(reloadOn.mock.calls[0]![0]).toEqual(expect.arrayContaining([...SESSION_KINDS]));
  });

  // Decisions Log 2026-10-28 00:13: every product below zero at a place, with « Compter » beside it.
  const belowZero: WatchRow = {
    kind: 'stock.below_zero',
    subjectId: 'p1',
    params: {
      product: 'Vis 6x40',
      reference: 'VIS',
      establishment: 'Quincaillerie',
      location: '000 — Siège',
      locationId: 'l1',
      onHand: '-3.000',
    },
  };

  it('counts a product below zero at the place it went short, and leads to the stock tab’s own « Sous zéro »', async () => {
    await open('stock.below_zero', [belowZero]);

    const count = q('row-action-count-0') ?? q('list-card-next-0');
    expect(count?.getAttribute('href')).toBe('/stock/locations/l1?product=p1');
    expect(fixture.nativeElement.textContent).toContain('Comptez ce qui s’y trouve.');
    expect(q('watch-elsewhere')?.getAttribute('href')).toBe('/stock?negative=yes');
    // And the sales that took it there, until a count follows them.
    const sold = q('watch-elsewhere-1');
    expect(sold?.getAttribute('href')).toBe('/stock/movements?withoutStock=yes');
    expect(sold?.textContent).toContain('Les sorties sans stock');
  });

  it('names the lot to count when the place is short of one lot', async () => {
    await open('stock.below_zero', [{ ...belowZero, params: { ...belowZero.params, lot: 'L-7' } }]);

    const count = q('row-action-count-0') ?? q('list-card-next-0');
    expect(count?.getAttribute('href')).toBe('/stock/locations/l1?product=p1&lot=L-7');
  });

  it('offers no count to a reader who may see the stock but not write it', async () => {
    await open('stock.below_zero', [belowZero], (permission) => permission !== 'stock.write');

    expect(q('row-action-count-0')).toBeNull();
    expect(q('list-card-next-0')).toBeNull();
    expect(q('row-action-open-product-0')).not.toBeNull();
    // Nor told to count: the hint says who does.
    const page = fixture.nativeElement.textContent as string;
    expect(page).toContain('Il revient à qui peut modifier le stock.');
    expect(page).not.toContain('Comptez');
  });

  it('offers no way elsewhere on a subject that has none', async () => {
    await open('invoices.late_customer');

    expect(q('watch-subject-title')).not.toBeNull();
    expect(q('watch-elsewhere')).toBeNull();
  });
});

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
import { LiveChanges } from '../shared/realtime/live-changes';
import { WatchFacade } from './watch-facade';
import { WatchPage } from './watch-page';
import type { WatchError, WatchSummary } from './watch-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      watch: {
        none: 'Rien à surveiller.',
        open: 'Ouvrir',
        errors: { unavailable: 'Indisponible.' },
        subjects: {
          'invoices.late_customer': { title: 'Clients en retard', hint: 'Relancez-les.' },
          'stock.lot_expired': { title: 'Lots périmés', hint: 'À sortir du stock.' },
        },
      },
    });
  }
}

const summary: WatchSummary = {
  count: 4437,
  subjects: [
    { kind: 'invoices.late_customer', count: 212 },
    { kind: 'stock.lot_expired', count: 9 },
    { kind: 'future.kind', count: 4216 },
  ],
};

// docs/SPEC.md § 7, the subject pages: « À surveiller » is one card per subject with its count; the rows are a page away.
describe('WatchPage', () => {
  const current = signal<WatchSummary | null>(null);
  const failed = signal<WatchError | null>(null);
  const facade = { summary: current.asReadonly(), error: failed.asReadonly(), load: vi.fn() };
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
    current.set(summary);
    failed.set(null);
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
      ],
    });
  });

  it('reads the summary for the working company and again when what it watches changes', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('k1');
    const [kinds, reload] = live.reloadOn.mock.calls[0]!;
    expect(kinds).toEqual(expect.arrayContaining(['invoice', 'payment', 'stock', 'setting']));
    reload();
    expect(facade.load).toHaveBeenCalledTimes(2);
  });

  it('draws one card per subject it knows, with its count and a link to its table', async () => {
    await open();

    const late = q('watch-subject-invoices.late_customer')!;
    expect(late.textContent).toContain('Clients en retard');
    expect(late.querySelector('[data-testid="watch-subject-count"]')?.textContent?.trim()).toBe(
      '212',
    );
    expect(late.getAttribute('href')).toBe('/watch/invoices.late_customer');
    expect(q('watch-subject-stock.lot_expired')?.textContent).toContain('Lots périmés');
  });

  it('leaves out a subject this screen does not know yet, rather than drawing it blank', async () => {
    await open();

    expect(q('watch-subject-future.kind')).toBeNull();
    expect(fixture.nativeElement.querySelectorAll('[data-testid="watch-subjects"] li').length).toBe(
      2,
    );
  });

  it('says there is nothing to watch when nothing is', async () => {
    current.set({ count: 0, subjects: [] });
    await open();

    expect(q('watch-none')?.textContent).toContain('Rien à surveiller.');
    expect(q('watch-subjects')).toBeNull();
  });

  it('says so when the summary cannot be read', async () => {
    failed.set('unavailable');
    await open();

    expect(q('watch-error')?.textContent).toContain('Indisponible.');
  });
});

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
import { SESSION_KINDS } from '../shared/session/session-kinds';
import { WatchFacade } from './watch-facade';
import { WatchHome } from './watch-home';
import type { WatchSummary } from './watch-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      watch: {
        title: 'À surveiller',
        count: 'À surveiller : {{count}}',
        none: 'Rien à surveiller.',
      },
      feedback: { retry: 'Réessayer', panel_failed: 'Ce panneau n’a pas pu être lu.' },
    });
  }
}

// docs/SPEC.md § 7, 2026-09-24 12:10: the home shows how many conditions « À surveiller » holds, the whole count the API reports.
describe('WatchHome', () => {
  const current = signal<WatchSummary | null>(null);
  const failure = signal<'unavailable' | null>(null);
  const facade = { summary: current.asReadonly(), error: failure.asReadonly(), load: vi.fn() };
  const live = { reloadOn: vi.fn() };
  let fixture: ComponentFixture<WatchHome>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(WatchHome);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    facade.load.mockReset().mockResolvedValue(undefined);
    current.set(null);
    failure.set(null);
    live.reloadOn.mockReset();
    TestBed.configureTestingModule({
      imports: [WatchHome],
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

  it('shows how many things to watch, linked to the list, and keeps the count live', async () => {
    current.set({ count: 3, subjects: [{ kind: 'a', count: 3 }] });
    await open();

    expect(facade.load).toHaveBeenCalledWith('k1');
    // What it counts is filtered by the reader's role and modules: a role changed must move the count at once.
    expect(live.reloadOn.mock.calls[0]![0]).toEqual(expect.arrayContaining([...SESSION_KINDS]));
    expect(q('home-watch')?.textContent).toContain('À surveiller : 3');
    expect(q('home-watch')?.querySelector('a')?.getAttribute('href')).toBe('/watch');
  });

  it('says there is nothing to watch when nothing is', async () => {
    current.set({ count: 0, subjects: [] });
    await open();

    expect(q('home-watch')?.textContent).toContain('Rien à surveiller.');
  });

  it('says the count could not be read and reads it again from the line, rather than drawing nothing', async () => {
    failure.set('unavailable');
    await open();

    expect(q('home-watch-error')?.getAttribute('role')).toBe('alert');
    expect(q('home-watch-error')?.textContent).toContain('Ce panneau n’a pas pu être lu.');

    facade.load.mockImplementation(() => {
      failure.set(null);
      current.set({ count: 0, subjects: [] });
      return Promise.resolve();
    });
    q('home-watch-retry')?.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(facade.load).toHaveBeenLastCalledWith('k1');
    expect(q('home-watch-error')).toBeNull();
    expect(q('home-watch')?.textContent).toContain('Rien à surveiller.');
  });

  it('keeps the count it read when a later read fails', async () => {
    current.set({ count: 3, subjects: [{ kind: 'a', count: 3 }] });
    failure.set('unavailable');
    await open();

    expect(q('home-watch-error')).toBeNull();
    expect(q('home-watch')?.textContent).toContain('À surveiller : 3');
  });
});

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
import { WatchHome } from './watch-home';
import type { WatchList } from './watch-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      watch: {
        title: 'À surveiller',
        count: 'À surveiller : {{count}}',
        none: 'Rien à surveiller.',
      },
    });
  }
}

// docs/SPEC.md § 7, 2026-09-24 12:10: the home shows how many conditions « À surveiller » lists.
describe('WatchHome', () => {
  const current = signal<WatchList | null>(null);
  const facade = { list: current.asReadonly(), load: vi.fn() };
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
    current.set({
      count: 3,
      items: [
        { kind: 'a', subjectId: null, params: {} },
        { kind: 'b', subjectId: null, params: {} },
        { kind: 'c', subjectId: null, params: {} },
      ],
    });
    await open();

    expect(facade.load).toHaveBeenCalledWith('k1');
    expect(live.reloadOn).toHaveBeenCalled();
    expect(q('home-watch')?.textContent).toContain('À surveiller : 3');
    expect(q('home-watch')?.querySelector('a')?.getAttribute('href')).toBe('/watch');
  });

  it('says there is nothing to watch when nothing is', async () => {
    current.set({ count: 0, items: [] });
    await open();

    expect(q('home-watch')?.textContent).toContain('Rien à surveiller.');
  });
});

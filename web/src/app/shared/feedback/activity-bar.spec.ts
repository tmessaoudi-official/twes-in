// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { ActivityBar } from './activity-bar';
import { RequestActivity } from './request-activity';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      feedback: {
        loading: 'Chargement',
        slow: 'Cela prend plus de temps que d’habitude…',
        unavailable: 'Le service est injoignable. Nouvelle tentative dans {{seconds}} s.',
        retry: 'Réessayer',
        offline: 'Vous êtes hors ligne.',
      },
    });
  }
}

describe('ActivityBar', () => {
  const state = {
    busy: signal(false),
    slow: signal(false),
    unavailable: signal(false),
    retryIn: signal<number | null>(null),
    offline: signal(false),
    retryNow: vi.fn(),
  };

  beforeEach(async () => {
    state.busy.set(false);
    state.slow.set(false);
    state.unavailable.set(false);
    state.retryIn.set(null);
    state.offline.set(false);
    state.retryNow.mockReset();
    await TestBed.configureTestingModule({
      imports: [ActivityBar],
      providers: [
        { provide: RequestActivity, useValue: state },
        provideTranslateService({
          lang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(ActivityBar);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    return {
      fixture,
      el,
      find: (id: string) => el.querySelector<HTMLElement>(`[data-testid="${id}"]`),
    };
  }

  it('shows nothing while nothing is waited for', async () => {
    const { el } = await render();
    expect(el.textContent?.trim()).toBe('');
  });

  it('draws a named progress bar while working, and says when it is slow', async () => {
    const { fixture, find } = await render();
    state.busy.set(true);
    await fixture.whenStable();
    expect(find('activity-progress')?.getAttribute('aria-label')).toBe('Chargement');
    expect(find('activity-slow')).toBeNull();

    state.slow.set(true);
    await fixture.whenStable();
    expect(find('activity-slow')?.getAttribute('role')).toBe('status');
    expect(find('activity-slow')?.textContent).toContain('plus de temps');
  });

  it('says the service cannot be reached, when it tries again, and offers to try now', async () => {
    const { fixture, find } = await render();
    state.unavailable.set(true);
    state.retryIn.set(8);
    await fixture.whenStable();

    expect(find('activity-unavailable')?.getAttribute('role')).toBe('alert');
    expect(find('activity-unavailable')?.textContent).toContain('Nouvelle tentative dans 8 s.');
    find('activity-retry')?.click();
    expect(state.retryNow).toHaveBeenCalledOnce();
  });

  it('says the browser is offline before anything else', async () => {
    const { fixture, find } = await render();
    state.unavailable.set(true);
    state.retryIn.set(8);
    state.offline.set(true);
    await fixture.whenStable();

    expect(find('activity-offline')?.textContent).toContain('hors ligne');
    expect(find('activity-unavailable')).toBeNull();
  });
});

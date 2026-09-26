// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Brand } from '../shared/brand/brand';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { LockedSubscriptionPage } from './locked-subscription-page';
import { SubscriptionPage } from './subscription-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ auth: { awaiting: { sign_out: 'Se déconnecter' } } });
  }
}

/** The page itself is covered by its own spec; here only where it is put matters. */
@Component({
  selector: 'app-subscription-page',
  template: `<p data-testid="stub-page">Abonnement</p>`,
})
class StubSubscriptionPage {}

describe('LockedSubscriptionPage', () => {
  const logout = vi.fn().mockResolvedValue(undefined);
  let navigateByUrl: ReturnType<typeof vi.spyOn>;

  beforeEach(async () => {
    logout.mockClear();

    await TestBed.configureTestingModule({
      imports: [LockedSubscriptionPage],
      providers: [
        { provide: AuthFacade, useValue: { logout } },
        // A real router: the legal line under the page links through it (row 147).
        provideRouter([]),
        {
          provide: Brand,
          useValue: { name: signal('twes-in'), tagline: signal('Rien ne se perd.') },
        },
        provideQuietFeedback(),
        { provide: ThemeFacade, useValue: { preference: signal('auto'), setScheme: vi.fn() } },
        { provide: LanguageFacade, useValue: { current: signal('fr'), use: vi.fn() } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    })
      .overrideComponent(LockedSubscriptionPage, {
        remove: { imports: [SubscriptionPage] },
        add: { imports: [StubSubscriptionPage] },
      })
      .compileComponents();
    navigateByUrl = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
  });

  async function render(): Promise<HTMLElement> {
    const fixture = TestBed.createComponent(LockedSubscriptionPage);
    await fixture.whenStable();
    return fixture.nativeElement as HTMLElement;
  }

  it('shows the page at its own width, not squeezed into the sign-in card', async () => {
    // A whole page inside the 26.25rem card lays its form out in two columns all the same, and the inputs collapse
    // to nothing: the one way out of a lock would be visible and unusable (2026-09-17).
    const el = await render();
    const page = el.querySelector('[data-testid="stub-page"]');

    expect(page).not.toBeNull();
    expect(page?.closest('.twes-auth-card')).toBeNull();
    expect(page?.closest('.twes-auth-page')).not.toBeNull();
  });

  it('signs out, which is the other thing a locked company may do', async () => {
    const el = await render();
    el.querySelector<HTMLButtonElement>('[data-testid="locked-sign-out"]')?.click();
    await Promise.resolve();

    expect(logout).toHaveBeenCalled();
    expect(navigateByUrl).toHaveBeenCalledWith('/login');
  });
});

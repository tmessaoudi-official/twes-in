// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from './auth-facade';
import { DemoBanner } from './demo-banner';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      auth: {
        demo_banner: 'Démo : une entreprise fictive.',
        demo_banner_mails: 'Voir les courriers',
      },
    });
  }
}

describe('DemoBanner', () => {
  const me = signal<unknown>(null);

  function shown(demo: boolean): HTMLElement {
    me.set({ user: { id: 'u1', demo }, company: { id: 'c1' } });
    const fixture = TestBed.createComponent(DemoBanner);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DemoBanner],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        provideRouter([]),
        { provide: AuthFacade, useValue: { me } },
      ],
    });
  });

  it('tells a demo visitor, on every page, that it is a demo', () => {
    const banner = shown(true).querySelector('[data-testid="demo-banner"]');

    expect(banner?.getAttribute('role')).toBe('status');
    expect(banner?.textContent).toContain('Démo : une entreprise fictive.');
  });

  it('opens the mails the demo kept instead of sending them', () => {
    const link = shown(true).querySelector('[data-testid="demo-banner-mails"]');

    expect(link?.getAttribute('href')).toBe('/demo-mails');
    expect(link?.textContent?.trim()).toBe('Voir les courriers');
  });

  it('says nothing to anyone else', () => {
    expect(shown(false).querySelector('[data-testid="demo-banner"]')).toBeNull();
  });
});

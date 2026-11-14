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
import { TextsParts } from '../shared/i18n/texts-part';
import { AuthFacade } from './auth-facade';
import { PASSKEYS_MOVED_TEXTS, PasskeysMovedBanner } from './passkeys-moved-banner';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      passkeys_moved: {
        banner: 'Vos clés d’accès ne fonctionnent plus.',
        open: 'Ajouter une clé d’accès',
      },
    });
  }
}

describe('PasskeysMovedBanner', () => {
  const me = signal<unknown>(null);
  const open = vi.fn(() => Promise.resolve());

  async function shown(passkeysElsewhere: number): Promise<HTMLElement> {
    me.set({ user: { id: 'u1' }, company: { id: 'c1' }, mfa: { passkeysElsewhere } });
    const fixture = TestBed.createComponent(PasskeysMovedBanner);
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    open.mockClear();
    TestBed.configureTestingModule({
      imports: [PasskeysMovedBanner],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        provideRouter([]),
        { provide: AuthFacade, useValue: { me } },
        { provide: TextsParts, useValue: { open } },
      ],
    });
  });

  it('tells, on every page, whoever holds a passkey made for another address, with the way to add one', async () => {
    const el = await shown(1);
    const banner = el.querySelector('[data-testid="passkeys-moved-banner"]');

    expect(open).toHaveBeenCalledWith(PASSKEYS_MOVED_TEXTS);
    expect(banner?.getAttribute('role')).toBe('status');
    expect(banner?.textContent).toContain('Vos clés d’accès ne fonctionnent plus.');
    expect(el.querySelector('[data-testid="passkeys-moved-open"]')?.getAttribute('href')).toBe(
      '/two-factor',
    );
  });

  it('says nothing, and opens no words, while every passkey works', async () => {
    const el = await shown(0);

    expect(el.querySelector('[data-testid="passkeys-moved-banner"]')).toBeNull();
    expect(open).not.toHaveBeenCalled();
  });
});

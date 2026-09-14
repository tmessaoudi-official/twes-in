// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../../auth/auth-facade';
import { FormatFacade } from './format-facade';
import { LanguageFacade } from './language-facade';

describe('FormatFacade', () => {
  const me = signal<{ company: { countryCode: string } | null } | null>(null);

  beforeEach(() => {
    me.set(null);
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        { provide: AuthFacade, useValue: { me } },
      ],
    });
  });

  it('formats in the interface language for the working company’s country', () => {
    const format = TestBed.inject(FormatFacade);
    expect(format.locale()).toBe('fr');

    me.set({ company: { countryCode: 'TN' } });

    expect(format.locale()).toBe('fr-TN');
    expect(format.amount('2975', 3).replace(/\s/g, ' ')).toBe('2 975,000');
    expect(format.day('2026-09-05')).toBe('05/09/2026');
    expect(format.moment('2026-09-13T10:00:00+00:00', 'UTC')).toBe('13/09/2026 10:00');
  });

  it('follows the interface language when it changes', async () => {
    me.set({ company: { countryCode: 'TN' } });
    const format = TestBed.inject(FormatFacade);

    await TestBed.inject(LanguageFacade).use('en');

    expect(format.locale()).toBe('en-TN');
    expect(format.amount('2975', 3)).toBe('2,975.000');
    expect(format.day('2026-09-05')).toBe('09/05/2026');
  });
});

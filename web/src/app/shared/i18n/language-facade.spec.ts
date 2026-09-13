// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
  TranslateService,
} from '@ngx-translate/core';
import { firstValueFrom, of } from 'rxjs';
import { LanguageFacade, SUPPORTED_LANGUAGES } from './language-facade';

class StaticLoader implements TranslateLoader {
  getTranslation(lang: string) {
    return of({ greeting: lang === 'en' ? 'Hello' : 'Bonjour' });
  }
}

describe('LanguageFacade', () => {
  beforeEach(() => {
    TestBed.resetTestingModule();
    document.documentElement.lang = 'fr';
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    });
  });

  it('ships French first and English second', () => {
    expect(SUPPORTED_LANGUAGES).toEqual(['fr', 'en']);
  });

  it('starts in French', () => {
    expect(TestBed.inject(LanguageFacade).current()).toBe('fr');
  });

  it('switches the translations and the document language together', async () => {
    const facade = TestBed.inject(LanguageFacade);
    const translate = TestBed.inject(TranslateService);

    await facade.use('en');

    expect(facade.current()).toBe('en');
    expect(document.documentElement.lang).toBe('en');
    expect(await firstValueFrom(translate.get('greeting'))).toBe('Hello');
  });

  it('refuses a language it does not ship and stays where it was', async () => {
    const facade = TestBed.inject(LanguageFacade);

    await expect(facade.use('de')).rejects.toThrow('"de"');

    expect(facade.current()).toBe('fr');
    expect(document.documentElement.lang).toBe('fr');
  });
});

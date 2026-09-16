// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
  TranslateService,
} from '@ngx-translate/core';
import { firstValueFrom, of } from 'rxjs';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';
import { LANGUAGE_NAMES, LanguageFacade, SUPPORTED_LANGUAGES } from './language-facade';

class StaticLoader implements TranslateLoader {
  getTranslation(lang: string) {
    return of({ greeting: lang === 'en' ? 'Hello' : 'Bonjour' });
  }
}

describe('LanguageFacade', () => {
  let storage: PageMemoryStorage;

  beforeEach(() => {
    TestBed.resetTestingModule();
    document.documentElement.lang = 'fr';
    storage = new PageMemoryStorage();
    TestBed.configureTestingModule({
      providers: [
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: storage },
        { provide: Session, useValue: { me: () => ({ user: { id: 'u1' } }) } },
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

  it('names each language in itself, never with a flag', () => {
    expect(LANGUAGE_NAMES).toEqual({ fr: 'Français', en: 'English' });
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

  it('remembers the choice as a presentation setting, so the next page load starts in it', async () => {
    const facade = TestBed.inject(LanguageFacade);
    await facade.use('en');

    expect(TestBed.inject(SettingsFacade).value(PRESENTATION.language)()).toBe('en');
  });

  it('applies a language the settings answer later, such as the one a person chose before signing in', async () => {
    const facade = TestBed.inject(LanguageFacade);
    const translate = TestBed.inject(TranslateService);
    TestBed.tick();

    TestBed.inject(SettingsFacade).set(PRESENTATION.language, 'en');
    TestBed.tick();

    await vi.waitFor(() => expect(facade.current()).toBe('en'));
    expect(document.documentElement.lang).toBe('en');
    expect(await firstValueFrom(translate.get('greeting'))).toBe('Hello');
  });
});

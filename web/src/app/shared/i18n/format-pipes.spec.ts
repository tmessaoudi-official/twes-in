// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { SignedPipe } from './format-pipes';
import { LanguageFacade } from './language-facade';

describe('SignedPipe', () => {
  const me = signal<{ user: { id: string }; company: { countryCode: string } } | null>(null);

  beforeEach(() => {
    me.set({ user: { id: 'u1' }, company: { countryCode: 'TN' } });
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        { provide: Session, useValue: { me } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        SignedPipe,
      ],
    });
  });

  const spaced = (text: string): string => text.replace(/\s/g, ' ');

  it('writes a change in the screen’s language, a percent unless asked for a plain figure', async () => {
    const pipe = TestBed.inject(SignedPipe);
    expect(spaced(pipe.transform('6.2', 1))).toBe('+6,2 %');
    expect(spaced(pipe.transform('-3.1', 1, 'figure'))).toBe('−3,1');

    await TestBed.inject(LanguageFacade).use('en');
    expect(pipe.transform('-3.1', 1)).toBe('−3.1%');

    await TestBed.inject(LanguageFacade).use('ar');
    expect(pipe.transform('6.2', 1)).toBe('‎+6,2‎%‎');
  });

  it('takes a figure worked out on screen as well as a decimal string', () => {
    const pipe = TestBed.inject(SignedPipe);
    expect(spaced(pipe.transform(-33.456, 1))).toBe('−33,5 %');
    expect(spaced(pipe.transform(0, 1))).toBe('0,0 %');
  });

  it('leaves nothing as nothing', () => {
    const pipe = TestBed.inject(SignedPipe);
    expect(pipe.transform(null, 1)).toBe('');
    expect(pipe.transform(undefined, 1)).toBe('');
    expect(pipe.transform(Number.NaN, 1)).toBe('');
  });
});

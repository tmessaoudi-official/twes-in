// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { provideTranslateService, TranslateCompiler, TranslateService } from '@ngx-translate/core';
import ar from '../../public/i18n/ar.json';
import en from '../../public/i18n/en.json';
import fr from '../../public/i18n/fr.json';
import { DurationPipe } from './shared/i18n/duration-pipe';
import { PluralCompiler } from './shared/i18n/plural-compiler';

// Beside the parity spec, as it reads the shipped texts, which a spec under shared/ may not import.
describe('DurationPipe', () => {
  let translate: TranslateService;
  let pipe: DurationPipe;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          compiler: { provide: TranslateCompiler, useClass: PluralCompiler },
        }),
        DurationPipe,
      ],
    });
    translate = TestBed.inject(TranslateService);
    translate.setTranslation('fr', fr);
    translate.setTranslation('en', en);
    translate.setTranslation('ar', ar);
    translate.use('fr');
    pipe = TestBed.inject(DurationPipe);
  });

  it('says a whole number of hours in hours, the rest in minutes, and seconds only when minutes would round it', () => {
    expect(pipe.transform(3600)).toBe('1 heure');
    expect(pipe.transform(86400)).toBe('24 heures');
    expect(pipe.transform(900)).toBe('15 minutes');
    expect(pipe.transform(5400)).toBe('90 minutes');
    expect(pipe.transform(60)).toBe('1 minute');
    // A proof held a minute and a half is not said « 1 minute »: the screen would promise less than is so.
    expect(pipe.transform(90)).toBe('90 secondes');
  });

  it('says it in the screen’s language and its plural forms', () => {
    translate.use('en');
    expect(pipe.transform(3600)).toBe('1 hour');
    expect(pipe.transform(300)).toBe('5 minutes');
    translate.use('ar');
    expect(pipe.transform(3600)).toBe('ساعة واحدة');
    expect(pipe.transform(7200)).toBe('ساعتين');
    expect(pipe.transform(300)).toBe('5 دقائق');
    expect(pipe.transform(86400)).toBe('24 ساعة');
  });
});

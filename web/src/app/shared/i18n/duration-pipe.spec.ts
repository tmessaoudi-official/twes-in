// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { WEB_PARAMETERS_UNTIL_TOLD, WebParameters } from '../build/web-parameters';
import { Session, type SessionState } from '../session/session';
import { DurationWords } from './duration-pipe';

describe('DurationWords', () => {
  const me = signal<SessionState | null>(null);

  function words(): DurationWords {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        { provide: Session, useValue: { me } },
      ],
    });
    TestBed.inject(TranslateService).setTranslation('fr', {
      duration: { hours: '{{count}} h' },
    });
    return TestBed.inject(DurationWords);
  }

  beforeEach(() => me.set(null));

  it('says the working company’s own undo window, which its deletes keep', () => {
    const durations = words();
    me.set({
      user: { id: 'u1' },
      company: { id: 'c1', countryCode: 'TN', currency: 'TND', recordsUndoableSeconds: 7200 },
    });

    expect(durations.recordsUndoableSeconds()).toBe(7200);
    expect(durations.recordsUndoable()).toBe('2 h');
  });

  it('says the operator’s window while no company is open', () => {
    const durations = words();
    TestBed.inject(WebParameters).tell({
      ...WEB_PARAMETERS_UNTIL_TOLD,
      recordsUndoableSeconds: 3 * 3600,
    });

    expect(durations.recordsUndoableSeconds()).toBe(3 * 3600);
  });
});

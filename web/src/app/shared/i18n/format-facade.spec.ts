// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { Session } from '../session/session';
import { FormatFacade } from './format-facade';
import { LanguageFacade } from './language-facade';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';

describe('FormatFacade', () => {
  const me = signal<{ user?: { id: string }; company: { countryCode: string } | null } | null>(
    null,
  );

  beforeEach(() => {
    me.set(null);
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        { provide: Session, useValue: { me } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('formats in the interface language for the working company’s country', () => {
    const format = TestBed.inject(FormatFacade);
    expect(format.locale()).toBe('fr');

    me.set({ user: { id: 'u1' }, company: { countryCode: 'TN' } });

    expect(format.locale()).toBe('fr-TN');
    expect(format.amount('2975', 3).replace(/\s/g, ' ')).toBe('2 975,000');
    expect(format.day('2026-09-05')).toBe('05/09/2026');
    expect(format.moment('2026-09-13T10:00:00+00:00', 'UTC')).toBe('13/09/2026 10:00');
  });

  it('follows the interface language when it changes', async () => {
    me.set({ user: { id: 'u1' }, company: { countryCode: 'TN' } });
    const format = TestBed.inject(FormatFacade);

    await TestBed.inject(LanguageFacade).use('en');

    expect(format.locale()).toBe('en-TN');
    expect(format.amount('2975', 3)).toBe('2,975.000');
    expect(format.day('2026-09-05')).toBe('09/05/2026');
  });

  it('writes days and figures as the person chose, whatever the language', () => {
    me.set({ user: { id: 'u1' }, company: { countryCode: 'TN' } });
    const format = TestBed.inject(FormatFacade);
    const settings = TestBed.inject(SettingsFacade);

    settings.set(PRESENTATION.dateFormat, 'ymd');
    settings.set(PRESENTATION.numberFormat, 'comma-dot');

    expect(format.amount('2975', 3)).toBe('2,975.000');
    expect(format.decimal('2975.5')).toBe('2975.5');
    expect(format.day('2026-09-05')).toBe('2026-09-05');
    expect(format.moment('2026-09-13T10:00:00+00:00', 'UTC')).toBe('2026-09-13 10:00');

    settings.set(PRESENTATION.dateFormat, 'auto');
    settings.set(PRESENTATION.numberFormat, 'auto');

    expect(format.day('2026-09-05')).toBe('05/09/2026');
    expect(format.decimal('2975.5')).toBe('2975,5');
  });
});

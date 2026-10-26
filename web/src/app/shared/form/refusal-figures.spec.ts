// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { RefusalFigures } from './refusal-figures';

describe('RefusalFigures', () => {
  const me = signal<{
    user: { id: string };
    company: { id: string; countryCode: string; currency: string } | null;
  } | null>(null);

  beforeEach(() => {
    me.set({ user: { id: 'u1' }, company: { id: 'c1', countryCode: 'TN', currency: 'TND' } });
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        { provide: Session, useValue: { me } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  const spaced = (params: Readonly<Record<string, unknown>>): Record<string, unknown> =>
    Object.fromEntries(
      Object.entries(params).map(([name, value]) => [
        name,
        typeof value === 'string' ? value.replace(/\s/g, ' ') : value,
      ]),
    );

  it('writes an amount at the currency’s scale, a day, a quantity and a price as the reader reads them', () => {
    const figures = TestBed.inject(RefusalFigures);

    expect(
      spaced(
        figures.written({
          amount: '1250.5',
          day: '2026-10-05',
          quantity: '1500.250',
          price: '12.5000',
          reference: 'REF-1',
          max: 64,
        }),
      ),
    ).toEqual({
      amount: '1 250,500',
      day: '05/10/2026',
      quantity: '1 500,250',
      price: '12,5000',
      reference: 'REF-1',
      max: 64,
    });
  });

  it('writes an amount the API stored with three decimals at the euro’s two', () => {
    me.set({ user: { id: 'u1' }, company: { id: 'c1', countryCode: 'FR', currency: 'EUR' } });

    expect(spaced(TestBed.inject(RefusalFigures).written({ amount: '12.340' }))).toEqual({
      amount: '12,34',
    });
  });

  it('leaves a figure it cannot read as it came, rather than blank', () => {
    expect(TestBed.inject(RefusalFigures).written({ amount: 'many', day: 'soon' })).toEqual({
      amount: 'many',
      day: 'soon',
    });
  });
});

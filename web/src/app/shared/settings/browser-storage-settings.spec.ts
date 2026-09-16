// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Session } from '../session/session';
import { BrowserStorageSettings } from './browser-storage-settings';
import { SETTINGS_STORAGE, SettingsFacade, UnregisteredSetting } from './settings-facade';
import {
  defineSetting,
  listPreferencesSetting,
  listViewsSetting,
  PRESENTATION,
} from './settings-registry';

/** An in-memory Storage, so a test controls exactly what "the browser kept" and can make it fail. */
class MemoryStorage implements Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> {
  readonly items = new Map<string, string>();
  failing = false;

  getItem(key: string): string | null {
    if (this.failing) throw new DOMException('denied', 'SecurityError');
    return this.items.get(key) ?? null;
  }

  setItem(key: string, value: string): void {
    if (this.failing) throw new DOMException('denied', 'QuotaExceededError');
    this.items.set(key, value);
  }

  removeItem(key: string): void {
    this.items.delete(key);
  }
}

describe('BrowserStorageSettings', () => {
  const userId = signal<string | null>('u1');
  let storage: MemoryStorage;

  function settings(): SettingsFacade {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: storage },
        {
          provide: Session,
          useValue: { me: () => (userId() ? { user: { id: userId() } } : null) },
        },
      ],
    });
    return TestBed.inject(SettingsFacade);
  }

  beforeEach(() => {
    storage = new MemoryStorage();
    userId.set('u1');
  });

  it('answers the declared default when nothing was chosen', () => {
    expect(settings().value(PRESENTATION.scheme)()).toBe('light');
    expect(settings().value(PRESENTATION.density)()).toBe('comfortable');
  });

  it('keeps a choice for the next page load', () => {
    settings().set(PRESENTATION.scheme, 'dark');

    expect(settings().value(PRESENTATION.scheme)()).toBe('dark');
  });

  it('keeps each user their own choices', () => {
    settings().set(PRESENTATION.scheme, 'dark');

    userId.set('u2');

    expect(settings().value(PRESENTATION.scheme)()).toBe('light');
  });

  it('follows the signed-in user while the page stays open', () => {
    const facade = settings();
    facade.set(PRESENTATION.density, 'compact');
    const density = facade.value(PRESENTATION.density);

    userId.set('u2');

    expect(density()).toBe('comfortable');
  });

  it('ignores a stored value that is not a valid one and answers the default', () => {
    storage.items.set('twes.settings.u1.presentation.scheme', JSON.stringify('purple'));
    storage.items.set('twes.settings.u1.presentation.density', '{not json');

    expect(settings().value(PRESENTATION.scheme)()).toBe('light');
    expect(settings().value(PRESENTATION.density)()).toBe('comfortable');
  });

  it('still works for the page when the browser refuses storage', () => {
    storage.failing = true;
    const facade = settings();

    facade.set(PRESENTATION.scheme, 'dark');

    expect(facade.value(PRESENTATION.scheme)()).toBe('dark');
  });

  it('forgets a choice on reset, back to the default', () => {
    const facade = settings();
    facade.set(PRESENTATION.scheme, 'dark');

    facade.reset(PRESENTATION.scheme);

    expect(facade.value(PRESENTATION.scheme)()).toBe('light');
    expect(storage.items.has('twes.settings.u1.presentation.scheme')).toBe(false);
  });

  it('keeps a list screen its column preferences under its own key', () => {
    const members = listPreferencesSetting('members');
    settings().set(members, { hidden: ['email'], order: ['role', 'name'], widths: {}, sort: null });

    expect(settings().value(members)().hidden).toEqual(['email']);
    expect(settings().value(listPreferencesSetting('customers'))().hidden).toEqual([]);
  });

  it('refuses a key nobody registered, so no preference is read ad hoc', () => {
    const invented = defineSetting('presentation.invented', 1, (raw) =>
      typeof raw === 'number' ? raw : undefined,
    );

    expect(() => settings().value(invented)).toThrow(UnregisteredSetting);
    expect(() => settings().set(invented, 2)).toThrow(UnregisteredSetting);
  });

  it('keeps a list screen its saved views, dropping any stored view that is not a valid one', () => {
    const good = {
      id: 'v1',
      name: 'Tunisie',
      query: '',
      filters: { country: 'TN' },
      layout: { hidden: [], order: [], widths: {}, sort: null },
    };
    storage.items.set(
      'twes.settings.u1.presentation.list.customers.views',
      JSON.stringify([good, { id: 'v2', name: '' }, 'not a view']),
    );

    expect(settings().value(listViewsSetting('customers'))()).toEqual([good]);
    expect(settings().value(listViewsSetting('members'))()).toEqual([]);
  });
});

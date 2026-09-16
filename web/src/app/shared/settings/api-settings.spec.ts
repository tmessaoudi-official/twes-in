// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Session } from '../session/session';
import { ApiSettings } from './api-settings';
import { BrowserStorageSettings } from './browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
  UnregisteredSetting,
} from './settings-facade';
import { defineSetting, listPreferencesSetting, PRESENTATION } from './settings-registry';
import { SettingsApi } from './settings-api';
import type { SettingLevel, SettingLevelValue, SettingRow } from './settings-types';

function row(key: string, defaultValue: unknown, ...levels: SettingLevelValue[]): SettingRow {
  const last = levels.at(-1);
  return {
    key,
    chain: 'presentation',
    type: 'enum',
    labelKey: key,
    module: 'core',
    defaultValue,
    value: last ? last.value : defaultValue,
    source: last ? last.level : null,
    levels,
    overridableLevels: ['company', 'role', 'user'],
    writableLevels: ['user'],
    choices: [],
    min: null,
    max: null,
    maxLength: null,
    pattern: null,
  };
}

const at = (level: SettingLevel, value: unknown): SettingLevelValue => ({ level, value });

/** A promise the test settles when it chooses, so "while the chain is loading" is a state it can hold. */
function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((settle) => (resolve = settle));
  return { promise, resolve };
}

describe('ApiSettings', () => {
  const me = signal<{ user: { id: string }; company: { id: string } | null } | null>(null);
  let storage: PageMemoryStorage;
  let api: {
    chain: ReturnType<typeof vi.fn>;
    change: ReturnType<typeof vi.fn>;
    reset: ReturnType<typeof vi.fn>;
  };

  function settings(): SettingsFacade {
    TestBed.configureTestingModule({
      providers: [
        { provide: SettingsFacade, useClass: ApiSettings },
        BrowserStorageSettings,
        { provide: SETTINGS_STORAGE, useValue: storage },
        { provide: Session, useValue: { me } },
        { provide: SettingsApi, useValue: api },
      ],
    });
    const facade = TestBed.inject(SettingsFacade);
    TestBed.tick();
    return facade;
  }

  async function settle(): Promise<void> {
    TestBed.tick();
    await new Promise((done) => setTimeout(done));
    TestBed.tick();
  }

  beforeEach(() => {
    TestBed.resetTestingModule();
    storage = new PageMemoryStorage();
    me.set({ user: { id: 'u1' }, company: { id: 'c1' } });
    api = {
      chain: vi.fn().mockResolvedValue([]),
      change: vi.fn().mockResolvedValue(undefined),
      reset: vi.fn().mockResolvedValue(undefined),
    };
  });

  it("answers the declared default until the company's chain arrives, then the value in force", async () => {
    const answer = deferred<SettingRow[]>();
    api.chain.mockReturnValue(answer.promise);
    const density = settings().value(PRESENTATION.density);

    expect(density()).toBe('comfortable');
    expect(api.chain).toHaveBeenCalledWith('c1', 'presentation');

    answer.resolve([row('presentation.density', 'comfortable', at('company', 'compact'))]);
    await settle();
    expect(density()).toBe('compact');
  });

  it('keeps a choice at the user level through the API and shows it at once', async () => {
    api.chain.mockResolvedValue([
      row('presentation.density', 'comfortable', at('company', 'compact')),
    ]);
    const facade = settings();
    await settle();

    facade.set(PRESENTATION.density, 'comfortable');

    expect(facade.value(PRESENTATION.density)()).toBe('comfortable');
    expect(api.change).toHaveBeenCalledWith(
      'c1',
      'presentation.density',
      'user',
      'comfortable',
      undefined,
      undefined,
      true,
    );
    expect(storage.getItem('twes.settings.u1.presentation.density')).toBeNull();
  });

  it('falls back to the level above on reset, without reading the chain again', async () => {
    api.chain.mockResolvedValue([
      row(
        'presentation.density',
        'comfortable',
        at('company', 'compact'),
        at('user', 'comfortable'),
      ),
    ]);
    const facade = settings();
    await settle();
    expect(facade.value(PRESENTATION.density)()).toBe('comfortable');

    facade.reset(PRESENTATION.density);

    expect(facade.value(PRESENTATION.density)()).toBe('compact');
    expect(api.reset).toHaveBeenCalledWith(
      'c1',
      'presentation.density',
      'user',
      undefined,
      undefined,
      true,
    );
    expect(api.chain).toHaveBeenCalledTimes(1);
  });

  it('reads the chain again when the working company changes', async () => {
    api.chain.mockImplementation(async (companyId: string) => [
      row('presentation.scheme', 'light', at('company', companyId === 'c2' ? 'dark' : 'light')),
    ]);
    const scheme = settings().value(PRESENTATION.scheme);
    await settle();
    expect(scheme()).toBe('light');

    me.set({ user: { id: 'u1' }, company: { id: 'c2' } });
    await settle();

    expect(api.chain).toHaveBeenLastCalledWith('c2', 'presentation');
    expect(scheme()).toBe('dark');
  });

  it('does not lose a choice made while the chain is still loading', async () => {
    const answer = deferred<SettingRow[]>();
    api.chain.mockReturnValue(answer.promise);
    const facade = settings();

    facade.set(PRESENTATION.density, 'compact');
    answer.resolve([row('presentation.density', 'comfortable')]);
    await settle();

    expect(facade.value(PRESENTATION.density)()).toBe('compact');
  });

  it('keeps the browser for the pages shown before anyone signs in', async () => {
    me.set(null);
    const facade = settings();

    facade.set(PRESENTATION.scheme, 'dark');
    await settle();

    expect(facade.value(PRESENTATION.scheme)()).toBe('dark');
    expect(storage.getItem('twes.settings.anonymous.presentation.scheme')).toBe('"dark"');
    expect(api.chain).not.toHaveBeenCalled();
    expect(api.change).not.toHaveBeenCalled();
  });

  it('answers the default for a value the API holds that the web does not accept', async () => {
    api.chain.mockResolvedValue([row('presentation.density', 'comfortable', at('user', 'cosy'))]);
    const facade = settings();
    await settle();

    expect(facade.value(PRESENTATION.density)()).toBe('comfortable');
  });

  it('answers a list screen its own layout, and the empty layout for one never arranged', async () => {
    const layout = { hidden: ['email'], order: [], widths: {}, sort: null };
    api.chain.mockResolvedValue([row('presentation.list.members', null, at('user', layout))]);
    const facade = settings();
    await settle();

    expect(facade.value(listPreferencesSetting('members'))()).toEqual(layout);
    expect(facade.value(listPreferencesSetting('customers'))().hidden).toEqual([]);
  });

  it('keeps the choice for the page when the API refuses it', async () => {
    api.change.mockRejectedValue(new Error('offline'));
    const facade = settings();
    await settle();

    facade.set(PRESENTATION.scheme, 'dark');
    await settle();

    expect(facade.value(PRESENTATION.scheme)()).toBe('dark');
  });

  it('refuses a key nobody registered', () => {
    const invented = defineSetting('presentation.invented', 1, (raw) =>
      typeof raw === 'number' ? raw : undefined,
    );
    const facade = settings();

    expect(() => facade.value(invented)).toThrow(UnregisteredSetting);
    expect(() => facade.set(invented, 2)).toThrow(UnregisteredSetting);
    expect(() => facade.reset(invented)).toThrow(UnregisteredSetting);
  });
});

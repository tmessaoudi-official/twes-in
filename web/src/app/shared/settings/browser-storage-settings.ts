// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, type Signal, signal } from '@angular/core';
import { AuthFacade } from '../../auth/auth-facade';
import {
  SETTINGS_STORAGE,
  type SettingDefinition,
  SettingsFacade,
  UnregisteredSetting,
} from './settings-facade';
import { isRegisteredSetting } from './settings-registry';

const FORGOTTEN = Symbol('forgotten');

/**
 * Presentation preferences kept in the browser, one key per user and setting (`twes.settings.<user>.<key>`).
 * Every write is also remembered for the page, so a browser that refuses storage still gets a working screen.
 */
@Injectable()
export class BrowserStorageSettings extends SettingsFacade {
  private readonly auth = inject(AuthFacade);
  private readonly storage = inject(SETTINGS_STORAGE);
  private readonly written = signal(new Map<string, unknown>());

  value<T>(setting: SettingDefinition<T>): Signal<T> {
    this.assertRegistered(setting);
    return computed(() => {
      const key = this.storageKey(setting);
      const written = this.written();
      if (written.has(key)) {
        const value = written.get(key);
        return value === FORGOTTEN ? setting.fallback : (value as T);
      }
      return this.read(key, setting);
    });
  }

  set<T>(setting: SettingDefinition<T>, value: T): void {
    this.assertRegistered(setting);
    const key = this.storageKey(setting);
    this.remember(key, value);
    try {
      this.storage.setItem(key, JSON.stringify(value));
    } catch {
      // Blocked or full storage: the page keeps the choice until it is reloaded.
    }
  }

  reset<T>(setting: SettingDefinition<T>): void {
    this.assertRegistered(setting);
    const key = this.storageKey(setting);
    this.remember(key, FORGOTTEN);
    try {
      this.storage.removeItem(key);
    } catch {
      // Same as set: the page already answers the default.
    }
  }

  private read<T>(key: string, setting: SettingDefinition<T>): T {
    try {
      const raw = this.storage.getItem(key);
      return raw === null ? setting.fallback : (setting.parse(JSON.parse(raw)) ?? setting.fallback);
    } catch {
      return setting.fallback;
    }
  }

  private remember(key: string, value: unknown): void {
    this.written.update((written) => new Map(written).set(key, value));
  }

  private storageKey(setting: SettingDefinition<unknown>): string {
    return `twes.settings.${this.auth.me()?.user.id ?? 'anonymous'}.${setting.key}`;
  }

  private assertRegistered(setting: SettingDefinition<unknown>): void {
    if (!isRegisteredSetting(setting.key)) throw new UnregisteredSetting(setting.key);
  }
}

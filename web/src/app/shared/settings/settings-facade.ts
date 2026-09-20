// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, InjectionToken, type Signal } from '@angular/core';

/**
 * One named preference: its key, the value used until someone chooses, and the parser that turns whatever was
 * stored into a valid value or `undefined` (stored data is never trusted, it may predate a release).
 */
export interface SettingDefinition<T> {
  readonly key: string;
  readonly fallback: T;
  readonly parse: (raw: unknown) => T | undefined;
}

export class UnregisteredSetting extends Error {
  constructor(key: string) {
    super(`setting "${key}" is not registered in settings-registry.ts`);
    this.name = 'UnregisteredSetting';
  }
}

/**
 * The port every screen reads its presentation preferences through. The browser-storage adapter answers it
 * until G3b, when the API adapter (platform → company → role → user) replaces it without touching a caller.
 */
export abstract class SettingsFacade {
  /** The current value for the signed-in user, following sign-in changes. Throws UnregisteredSetting. */
  abstract value<T>(setting: SettingDefinition<T>): Signal<T>;

  /** Throws UnregisteredSetting. */
  abstract set<T>(setting: SettingDefinition<T>, value: T): void;

  /** Forgets the choice: the value returns to the declared default. */
  abstract reset<T>(setting: SettingDefinition<T>): void;

  /**
   * Reads the chain again. A change made through this facade is already live; this is for a change written by
   * another route — the company settings screen writes whole rows through `SettingsApi` — which otherwise only
   * reaches OTHER tabs, since a live change never comes back to the tab that caused it. Without it, changing the
   * company's default colour changed nothing until the browser was refreshed (developer, 2026-09-20).
   */
  abstract refresh(): void;
}

export type SettingsStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

/** A storage that lasts as long as the page: what the adapter uses when the browser refuses its own. */
export class PageMemoryStorage implements SettingsStorage {
  private readonly items = new Map<string, string>();

  getItem(key: string): string | null {
    return this.items.get(key) ?? null;
  }

  setItem(key: string, value: string): void {
    this.items.set(key, value);
  }

  removeItem(key: string): void {
    this.items.delete(key);
  }
}

export const SETTINGS_STORAGE = new InjectionToken<SettingsStorage>('SETTINGS_STORAGE', {
  providedIn: 'root',
  factory: () => {
    try {
      // Reading the property itself throws when site data is blocked.
      const storage = inject(DOCUMENT).defaultView?.localStorage;
      return storage ?? new PageMemoryStorage();
    } catch {
      return new PageMemoryStorage();
    }
  },
});

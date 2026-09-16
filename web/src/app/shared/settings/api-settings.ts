// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  computed,
  effect,
  inject,
  Injectable,
  type Signal,
  signal,
  untracked,
} from '@angular/core';
import { Session } from '../session/session';
import { BrowserStorageSettings } from './browser-storage-settings';
import { type SettingDefinition, SettingsFacade, UnregisteredSetting } from './settings-facade';
import { SettingsApi } from './settings-api';
import { isRegisteredSetting } from './settings-registry';
import type { SettingLevel, SettingRow } from './settings-types';

const FORGOTTEN = Symbol('forgotten');

interface Scope {
  readonly userId: string;
  readonly companyId: string;
}

/** One setting as the web knows it: its default and what each level holds, most general first. */
interface Known {
  readonly defaultValue: unknown;
  readonly levels: ReadonlyMap<SettingLevel, unknown>;
}

interface State {
  readonly scope: Scope | null;
  readonly known: ReadonlyMap<string, Known>;
}

const sameScope = (a: Scope | null, b: Scope | null): boolean =>
  a?.userId === b?.userId && a?.companyId === b?.companyId;

/**
 * Presentation settings through the API's presentation chain, platform → company → role → user (docs/SPEC.md
 * § 3 Settings). The chain is read once per person and working company; a choice is stored at the user level and
 * shown at once, and a reset falls back to what the levels above hold. A refused or unreachable write keeps the
 * choice for the page, as the browser adapter does. The pages shown before anyone signs in, and a session with no
 * working company, keep the browser adapter.
 */
@Injectable()
export class ApiSettings extends SettingsFacade {
  private readonly session = inject(Session);
  private readonly api = inject(SettingsApi);
  private readonly browser = inject(BrowserStorageSettings);
  private readonly scope = computed<Scope | null>(
    () => {
      const me = this.session.me();
      return me?.company ? { userId: me.user.id, companyId: me.company.id } : null;
    },
    { equal: sameScope },
  );
  private readonly state = signal<State>({ scope: null, known: new Map() });
  /** Choices made while the chain is being read, which its answer must not overwrite. */
  private pending = new Map<string, unknown>();

  constructor() {
    super();
    effect(() => {
      const scope = this.scope();
      untracked(() => void this.load(scope));
    });
  }

  value<T>(setting: SettingDefinition<T>): Signal<T> {
    this.assertRegistered(setting);
    const inBrowser = this.browser.value(setting);
    return computed(() => {
      const scope = this.scope();
      if (scope === null) return inBrowser();
      const state = this.state();
      const known = sameScope(state.scope, scope) ? state.known.get(setting.key) : undefined;
      const raw = known === undefined ? undefined : inForce(known);
      return raw === undefined ? setting.fallback : (setting.parse(raw) ?? setting.fallback);
    });
  }

  set<T>(setting: SettingDefinition<T>, value: T): void {
    this.assertRegistered(setting);
    const scope = this.scope();
    if (scope === null) {
      this.browser.set(setting, value);
      return;
    }
    this.keep(scope, setting.key, value);
    this.api.change(scope.companyId, setting.key, 'user', value).catch(() => {
      // Refused or unreachable: the page keeps the choice until it is reloaded.
    });
  }

  reset<T>(setting: SettingDefinition<T>): void {
    this.assertRegistered(setting);
    const scope = this.scope();
    if (scope === null) {
      this.browser.reset(setting);
      return;
    }
    this.keep(scope, setting.key, FORGOTTEN);
    this.api.reset(scope.companyId, setting.key, 'user').catch(() => {
      // Same as set: the page already answers the level above.
    });
  }

  private async load(scope: Scope | null): Promise<void> {
    this.pending = new Map();
    this.state.set({ scope, known: new Map() });
    if (scope === null) return;

    let rows: SettingRow[] = [];
    try {
      rows = await this.api.chain(scope.companyId, 'presentation');
    } catch {
      // An unreadable chain leaves the declared defaults in force; choices made meanwhile are still kept.
    }
    if (!sameScope(this.state().scope, scope)) return;

    let known: ReadonlyMap<string, Known> = new Map(rows.map((row) => [row.key, toKnown(row)]));
    for (const [key, value] of this.pending) known = withUserValue(known, key, value);
    this.pending = new Map();
    this.state.set({ scope, known });
  }

  private keep(scope: Scope, key: string, value: unknown): void {
    this.pending.set(key, value);
    this.state.update((state) =>
      sameScope(state.scope, scope)
        ? { scope, known: withUserValue(state.known, key, value) }
        : state,
    );
  }

  private assertRegistered(setting: SettingDefinition<unknown>): void {
    if (!isRegisteredSetting(setting.key)) throw new UnregisteredSetting(setting.key);
  }
}

function toKnown(row: SettingRow): Known {
  return {
    defaultValue: row.defaultValue,
    levels: new Map(row.levels.map((level) => [level.level, level.value])),
  };
}

/** The most specific level holding a value, else the default; `undefined` when the web knows neither. */
function inForce(known: Known): unknown {
  let value = known.defaultValue;
  for (const held of known.levels.values()) value = held;
  return value === null ? undefined : value;
}

function withUserValue(
  known: ReadonlyMap<string, Known>,
  key: string,
  value: unknown,
): ReadonlyMap<string, Known> {
  const entry = known.get(key);
  const levels = new Map(entry?.levels ?? []);
  levels.delete('user');
  if (value !== FORGOTTEN) levels.set('user', value);
  return new Map(known).set(key, { defaultValue: entry?.defaultValue, levels });
}

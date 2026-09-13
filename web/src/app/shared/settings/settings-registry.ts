// SPDX-License-Identifier: AGPL-3.0-or-later

import { assertAccentColour, type ColourScheme } from '../theme/accent-theme';
import type { ListFilterValues, ListPreferences, ListSort, ListView } from '../list/list-types';
import { NO_LIST_PREFERENCES } from '../list/list-types';
import { type SettingDefinition, UnregisteredSetting } from './settings-facade';

export type Density = 'comfortable' | 'compact';

/**
 * The accent used until someone chooses one; the platform → company → role → user chain takes over at G3b
 * (docs/SPEC.md § 3 Settings). A declared default, not a hardcoded look.
 */
export const DEFAULT_ACCENT = '#1f6feb';

export function defineSetting<T>(
  key: string,
  fallback: T,
  parse: (raw: unknown) => T | undefined,
): SettingDefinition<T> {
  return { key, fallback, parse };
}

const oneOf =
  <T extends string>(...allowed: T[]) =>
  (raw: unknown): T | undefined =>
    allowed.find((value) => value === raw);

function parseAccent(raw: unknown): string | undefined {
  if (typeof raw !== 'string') return undefined;
  try {
    assertAccentColour(raw);
    return raw.toLowerCase();
  } catch {
    return undefined;
  }
}

/** Every application-wide presentation preference. A key missing here cannot be read or written. */
export const PRESENTATION = {
  accent: defineSetting('presentation.accent', DEFAULT_ACCENT, parseAccent),
  scheme: defineSetting<ColourScheme>('presentation.scheme', 'light', oneOf('light', 'dark')),
  density: defineSetting<Density>(
    'presentation.density',
    'comfortable',
    oneOf('comfortable', 'compact'),
  ),
} as const;

const LIST_ID = /^[a-z][a-z0-9-]*$/;
const LIST_KEY = /^presentation\.list\.[a-z][a-z0-9-]*(\.views)?$/;

const isStrings = (raw: unknown): raw is string[] =>
  Array.isArray(raw) && raw.every((item) => typeof item === 'string');

function parseSort(raw: unknown): ListSort | null | undefined {
  if (raw === null) return null;
  if (typeof raw !== 'object') return undefined;
  const { column, direction } = raw as Record<string, unknown>;
  return typeof column === 'string' && (direction === 'asc' || direction === 'desc')
    ? { column, direction }
    : undefined;
}

function parseListPreferences(raw: unknown): ListPreferences | undefined {
  if (typeof raw !== 'object' || raw === null) return undefined;
  const { hidden, order, widths, sort } = raw as Record<string, unknown>;
  if (!isStrings(hidden) || !isStrings(order) || typeof widths !== 'object' || widths === null)
    return undefined;
  const widthEntries = Object.entries(widths);
  if (
    !widthEntries.every(
      ([, width]) => typeof width === 'number' && Number.isFinite(width) && width > 0,
    )
  ) {
    return undefined;
  }
  const parsedSort = parseSort(sort);
  if (parsedSort === undefined) return undefined;
  return {
    hidden,
    order,
    widths: Object.fromEntries(widthEntries) as Record<string, number>,
    sort: parsedSort,
  };
}

/** One list screen's column, width and sort choices, under `presentation.list.<listId>`. */
export function listPreferencesSetting(listId: string): SettingDefinition<ListPreferences> {
  if (!LIST_ID.test(listId)) throw new UnregisteredSetting(`presentation.list.${listId}`);
  return defineSetting(`presentation.list.${listId}`, NO_LIST_PREFERENCES, parseListPreferences);
}

function parseFilterValues(raw: unknown): ListFilterValues | undefined {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return undefined;
  const entries = Object.entries(raw);
  return entries.every(([, value]) => typeof value === 'string')
    ? (Object.fromEntries(entries) as ListFilterValues)
    : undefined;
}

function parseView(raw: unknown): ListView | undefined {
  if (typeof raw !== 'object' || raw === null) return undefined;
  const { id, name, query, filters, layout } = raw as Record<string, unknown>;
  if (typeof id !== 'string' || id === '' || typeof name !== 'string' || name.trim() === '')
    return undefined;
  if (typeof query !== 'string') return undefined;
  const parsedFilters = parseFilterValues(filters);
  const parsedLayout = parseListPreferences(layout);
  if (!parsedFilters || !parsedLayout) return undefined;
  return { id, name, query, filters: parsedFilters, layout: parsedLayout };
}

/** Stored views that no longer parse are dropped one by one, so a single bad entry does not cost the others. */
function parseViews(raw: unknown): ListView[] | undefined {
  if (!Array.isArray(raw)) return undefined;
  return raw.map(parseView).filter((view): view is ListView => view !== undefined);
}

/** One list screen's saved views, under `presentation.list.<listId>.views`. */
export function listViewsSetting(listId: string): SettingDefinition<ListView[]> {
  if (!LIST_ID.test(listId)) throw new UnregisteredSetting(`presentation.list.${listId}.views`);
  return defineSetting(`presentation.list.${listId}.views`, [], parseViews);
}

const PRESENTATION_KEYS = new Set<string>(
  Object.values(PRESENTATION).map((setting) => setting.key),
);

export function isRegisteredSetting(key: string): boolean {
  return PRESENTATION_KEYS.has(key) || LIST_KEY.test(key);
}

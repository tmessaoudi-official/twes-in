// SPDX-License-Identifier: AGPL-3.0-or-later

import { DEFAULT_SHORTCUTS, parseShellShortcuts, type ShellKeys } from '../actions/shortcuts';
import { DATE_FORMATS, type DateFormat, NUMBER_FORMATS, type NumberStyle } from '../i18n/format';
import { assertAccentColour } from '../theme/accent-theme';
import type { ListFilterValues, ListPreferences, ListSort, ListView } from '../list/list-types';
import { NO_LIST_PREFERENCES } from '../list/list-types';
import { type SettingDefinition, UnregisteredSetting } from './settings-facade';

export type Density = 'comfortable' | 'compact';
/** What a person chose: a scheme, or Automatique, which follows the device (docs/SPEC.md § 7, 2026-09-16 review). */
export type SchemePreference = 'auto' | 'light' | 'dark';
/** The interface languages, French first (Tunisia, France); Arabic and right-to-left come after the POC. */
export const SUPPORTED_LANGUAGES = ['fr', 'en'] as const;
export type Language = (typeof SUPPORTED_LANGUAGES)[number];
/** The sidebar on a wide screen: icons and labels, or icons alone with the labels as tooltips. */
export type SidebarState = 'expanded' | 'rail';
/**
 * What the stock plan writes on a rectangle. Declared here and not beside the plan because `shared/` imports no
 * feature: the registry owns the value a key may hold, exactly as it owns `SidebarState` and `SUPPORTED_LANGUAGES`.
 */
export const PLAN_LABEL_MODES = ['code', 'name', 'both'] as const;
export type PlanLabelMode = (typeof PLAN_LABEL_MODES)[number];

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

function parseBool(raw: unknown): boolean | undefined {
  return typeof raw === 'boolean' ? raw : undefined;
}

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
  scheme: defineSetting<SchemePreference>(
    'presentation.scheme',
    'auto',
    oneOf('auto', 'light', 'dark'),
  ),
  density: defineSetting<Density>(
    'presentation.density',
    'comfortable',
    oneOf('comfortable', 'compact'),
  ),
  sidebar: defineSetting<SidebarState>(
    'presentation.sidebar',
    'expanded',
    oneOf('expanded', 'rail'),
  ),
  /**
   * The settings area keeps its own answer, so folding the menu beside the settings list does not fold it everywhere
   * else. It opens labelled, as the round-6 settings board draws it (docs/SPEC.md § 7, 2026-09-25 17:22).
   */
  settingsSidebar: defineSetting<SidebarState>(
    'presentation.sidebar-settings',
    'expanded',
    oneOf('expanded', 'rail'),
  ),
  /**
   * What the stock plan writes on a rectangle: its code, its name, or both. Registered here with the other
   * application-wide preferences although one screen reads it, exactly as `sidebar-settings` is — the registry is
   * the list of keys that may exist, not a list of screens.
   */
  planLabels: defineSetting<PlanLabelMode>(
    'presentation.plan-labels',
    'code',
    oneOf<PlanLabelMode>(...PLAN_LABEL_MODES),
  ),
  language: defineSetting<Language>(
    'presentation.language',
    SUPPORTED_LANGUAGES[0],
    oneOf(...SUPPORTED_LANGUAGES),
  ),
  /**
   * What customer view hides on screen (docs/SPEC.md § 7, 2026-09-23 slice 5), set by the company alone: a
   * product's cost, and the codes its suppliers print. Both, until the company says otherwise.
   */
  customerViewCost: defineSetting<boolean>('presentation.customer-view.cost', true, parseBool),
  customerViewSupplierCodes: defineSetting<boolean>(
    'presentation.customer-view.supplier-codes',
    true,
    parseBool,
  ),
  /**
   * How days and figures are written (docs/SPEC.md § 7, 2026-09-25 12:45, row 130): the locale's until someone
   * chooses, person then role then company, as the API declares them.
   */
  dateFormat: defineSetting<DateFormat>('presentation.date-format', 'auto', oneOf(...DATE_FORMATS)),
  numberFormat: defineSetting<NumberStyle>(
    'presentation.number-format',
    'auto',
    oneOf(...NUMBER_FORMATS),
  ),
  /** « Montrer ce qui arrive » (docs/SPEC.md § 7, 2026-09-25 17:22): the vision's parts not built yet, marked. */
  showComing: defineSetting<boolean>('presentation.show-coming', true, parseBool),
  /** The shell's single keys (docs/SPEC.md § 7, 2026-09-24 22:51, row 125), each person's own; C, N, E and / until then. */
  shortcuts: defineSetting<ShellKeys>(
    'presentation.shortcuts',
    DEFAULT_SHORTCUTS,
    parseShellShortcuts,
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

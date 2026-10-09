// SPDX-License-Identifier: AGPL-3.0-or-later

import type { PlannedModule } from '../auth/auth-types';
import type { NavigateCommand } from './commands';
import { type Coming, COMING_NAV, COMING_ROUTE, type NavEntry } from './nav-manifest';
import type { IconName } from '../shared/icons/icons';

/** Where a planned module sits in the menu and how it is drawn: the only thing the web declares about it. */
export interface PlannedPlace {
  /** The module's key in the API's catalogue. */
  readonly key: string;
  /** A Material Symbols ligature. */
  readonly icon: IconName;
  readonly section: 'sell' | 'manage';
  /** The entry it will follow once its module ships; until then it sits after its section's working entries. */
  readonly after: string;
  /** The docs/SPEC.md § 8 row that builds it, when one does (see `Coming.row`). */
  readonly row?: number;
  /** What to use until it exists, when something does the job today. */
  readonly meanwhile?: string;
  /** Whether « Créer » will make something with it, a document or a record, named under `coming.<key>.create`. */
  readonly create?: true;
}

/**
 * The modules of the complete product not built yet, each in the section where it will live. Which of them exist, and
 * for which version, is the API's catalogue's to say (`plannedModules` in the signed-in state); this list only places
 * and draws them. An entry leaves it in the change that ships its module, its « coming » texts with it.
 * `scripts/gates/planned-module-labels.sh` keeps a place here for every planned key, and `coming-texts.sh` no place or
 * text for a module that shipped.
 */
export const PLANNED_NAV: readonly PlannedPlace[] = [
  // Selling.
  { key: 'mailing', icon: 'forward_to_inbox', section: 'sell', after: 'customers' },
  { key: 'whatsapp', icon: 'chat', section: 'sell', after: 'mailing', row: 95 },
  { key: 'portal', icon: 'web', section: 'sell', after: 'whatsapp' },
  { key: 'composites', icon: 'widgets', section: 'sell', after: 'price_lists' },
  {
    key: 'register',
    icon: 'point_of_sale',
    section: 'sell',
    after: 'composites',
    row: 82,
    meanwhile: '/invoices/new',
    create: true,
  },
  {
    key: 'works',
    icon: 'construction',
    section: 'sell',
    after: 'register',
    row: 80,
    create: true,
  },
  // Café and restaurant.
  { key: 'venue', icon: 'table_restaurant', section: 'sell', after: 'works' },
  { key: 'menu', icon: 'menu_book', section: 'sell', after: 'venue' },
  { key: 'service', icon: 'room_service', section: 'sell', after: 'menu' },
  { key: 'guests', icon: 'loyalty', section: 'sell', after: 'service' },
  { key: 'ratings', icon: 'reviews', section: 'sell', after: 'guests' },
  // Buying and stock.
  {
    key: 'purchases',
    icon: 'shopping_cart',
    section: 'manage',
    after: 'vendors',
    row: 81,
    create: true,
  },
  // Money and compliance.
  {
    key: 'reports',
    icon: 'bar_chart',
    section: 'manage',
    after: 'expenses',
    row: 89,
    meanwhile: '/',
  },
  { key: 'declarations', icon: 'event_note', section: 'manage', after: 'reports', row: 91 },
  {
    key: 'einvoicing',
    icon: 'receipt_long',
    section: 'manage',
    after: 'accounting_export',
    row: 145,
  },
  { key: 'currencies', icon: 'currency_exchange', section: 'manage', after: 'einvoicing' },
  { key: 'zakat', icon: 'volunteer_activism', section: 'manage', after: 'currencies', row: 120 },
];

/**
 * The planned modules the folded rail names by `nav.short.<key>`: their whole name runs past the two lines its 80 px
 * leave, where every other one fits.
 */
const SHORT_IN_RAIL: ReadonlySet<string> = new Set(['mailing']);

/** The menu entries of the modules the API lists as planned, in the order they are placed; a key without a place is left out. */
export function plannedNav(
  planned: readonly PlannedModule[] | undefined,
): readonly (NavEntry & { readonly coming: Coming })[] {
  const version = new Map((planned ?? []).map((module) => [module.key, module.planned]));
  return PLANNED_NAV.flatMap((place) => {
    const planned = version.get(place.key);
    if (planned === undefined) return [];
    return [
      {
        key: place.key,
        labelKey: `modules.${place.key}`,
        ...(SHORT_IN_RAIL.has(place.key) ? { shortLabelKey: `nav.short.${place.key}` } : {}),
        icon: place.icon,
        route: `${COMING_ROUTE}/${place.key}`,
        section: place.section,
        coming: {
          after: place.after,
          version: planned,
          ...(place.row === undefined ? {} : { row: place.row }),
          ...(place.meanwhile === undefined ? {} : { meanwhile: place.meanwhile }),
        },
      },
    ];
  });
}

/** Everything not built yet the menus show: the planned modules, then the settings pages that are not modules. */
export function comingEntries(
  planned: readonly PlannedModule[] | undefined,
): readonly (NavEntry & { readonly coming: Coming })[] {
  return [...plannedNav(planned), ...COMING_NAV];
}

/**
 * The planned modules in the Ctrl K palette: a way to each one's page, and to the document or record it will create
 * in « Créer ». Both are marked coming and open the « En construction » page: they never create anything.
 */
export function plannedCommands(
  planned: readonly PlannedModule[] | undefined,
): readonly NavigateCommand[] {
  const places = new Map(PLANNED_NAV.map((place) => [place.key, place]));
  return plannedNav(planned).flatMap((entry) => {
    const creates = places.get(entry.key)?.create === true;
    const goto: NavigateCommand = {
      key: `goto-${entry.key}`,
      labelKey: entry.labelKey,
      icon: entry.icon,
      route: entry.route,
      group: 'goto',
      coming: true,
    };
    return !creates
      ? [goto]
      : [
          {
            key: `new-${entry.key}`,
            labelKey: `coming.${entry.key}.create`,
            icon: entry.icon,
            route: entry.route,
            group: 'create' as const,
            coming: true as const,
          },
          goto,
        ];
  });
}

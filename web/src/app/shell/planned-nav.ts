// SPDX-License-Identifier: AGPL-3.0-or-later

import type { PlannedModule } from '../auth/auth-types';
import type { NavigateCommand } from './commands';
import { type Coming, COMING_NAV, COMING_ROUTE, type NavEntry } from './nav-manifest';

/** Where a planned module sits in the menu and how it is drawn: the only thing the web declares about it. */
export interface PlannedPlace {
  /** The module's key in the API's catalogue. */
  readonly key: string;
  /** A Material Symbols ligature. */
  readonly icon: string;
  readonly section: 'sell' | 'manage';
  /** The entry it follows in its section; the chain below is read in order. */
  readonly after: string;
  /** What to use until it exists, when something does the job today. */
  readonly meanwhile?: string;
  /** Whether a § 8 row builds it yet: its « En construction » page then names that row. */
  readonly plan?: true;
  /** Whether « Créer » will make something with it, a document or a record, named under `coming.<key>.create`. */
  readonly create?: true;
}

/**
 * The modules of the complete product not built yet (docs/SPEC.md § 7, 2026-09-26 10:08 and 12:05, row 150), each in
 * the section where it will live. Which of them exist, and for which version, is the API's catalogue's to say
 * (`plannedModules` in the signed-in state); this list only places and draws them. An entry leaves it in the change
 * that ships its module. `scripts/gates/planned-module-labels.sh` keeps a place here for every planned key.
 */
export const PLANNED_NAV: readonly PlannedPlace[] = [
  // Selling.
  {
    key: 'quotes',
    icon: 'request_quote',
    section: 'sell',
    after: 'invoices',
    plan: true,
    create: true,
  },
  {
    key: 'recurring',
    icon: 'event_repeat',
    section: 'sell',
    after: 'quotes',
    plan: true,
    create: true,
  },
  {
    key: 'statements',
    icon: 'account_balance_wallet',
    section: 'sell',
    after: 'customers',
    plan: true,
  },
  { key: 'mailing', icon: 'forward_to_inbox', section: 'sell', after: 'statements', plan: true },
  { key: 'whatsapp', icon: 'chat', section: 'sell', after: 'mailing', plan: true },
  { key: 'portal', icon: 'web', section: 'sell', after: 'whatsapp' },
  {
    key: 'price_lists',
    icon: 'sell',
    section: 'sell',
    after: 'products',
    plan: true,
    create: true,
  },
  { key: 'composites', icon: 'widgets', section: 'sell', after: 'price_lists' },
  {
    key: 'register',
    icon: 'point_of_sale',
    section: 'sell',
    after: 'composites',
    meanwhile: '/invoices/new',
    plan: true,
    create: true,
  },
  {
    key: 'works',
    icon: 'construction',
    section: 'sell',
    after: 'register',
    plan: true,
    create: true,
  },
  // Café and restaurant.
  { key: 'venue', icon: 'table_restaurant', section: 'sell', after: 'works' },
  { key: 'menu', icon: 'menu_book', section: 'sell', after: 'venue' },
  { key: 'service', icon: 'room_service', section: 'sell', after: 'menu' },
  { key: 'guests', icon: 'loyalty', section: 'sell', after: 'service' },
  { key: 'ratings', icon: 'reviews', section: 'sell', after: 'guests' },
  // Buying and stock.
  { key: 'stock_valuation', icon: 'price_check', section: 'manage', after: 'stock', plan: true },
  {
    key: 'purchases',
    icon: 'shopping_cart',
    section: 'manage',
    after: 'vendors',
    plan: true,
    create: true,
  },
  // Money and compliance.
  {
    key: 'reports',
    icon: 'bar_chart',
    section: 'manage',
    after: 'expenses',
    meanwhile: '/',
    plan: true,
  },
  { key: 'declarations', icon: 'event_note', section: 'manage', after: 'reports', plan: true },
  {
    key: 'accounting_export',
    icon: 'output',
    section: 'manage',
    after: 'declarations',
    plan: true,
  },
  {
    key: 'einvoicing',
    icon: 'receipt_long',
    section: 'manage',
    after: 'accounting_export',
    plan: true,
  },
  { key: 'currencies', icon: 'currency_exchange', section: 'manage', after: 'einvoicing' },
  { key: 'zakat', icon: 'volunteer_activism', section: 'manage', after: 'currencies', plan: true },
];

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
        icon: place.icon,
        route: `${COMING_ROUTE}/${place.key}`,
        section: place.section,
        coming: {
          after: place.after,
          version: planned,
          ...(place.meanwhile === undefined ? {} : { meanwhile: place.meanwhile }),
          ...(place.plan === undefined ? {} : { plan: place.plan }),
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

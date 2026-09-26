// SPDX-License-Identifier: AGPL-3.0-or-later

import { CUSTOMERS_NAV } from '../customers/customers-nav';
import { DELIVERY_NOTES_NAV } from '../delivery-notes/delivery-notes-nav';
import { INVENTORY_NAV } from '../inventory/inventory-nav';
import { INVOICES_NAV } from '../invoices/invoices-nav';
import { PRODUCTS_NAV } from '../products/products-nav';
import { VENDORS_NAV } from '../vendors/vendors-nav';
import { EXPENSES_NAV } from '../expenses/expenses-nav';

/**
 * What the shell offers. The sidebar keeps the daily entries in two groups, Vendre and Gérer (docs/SPEC.md § 7,
 * 2026-09-25 09:03): the core ones, then each module's, declared by the module's web feature beside its routes and
 * shown while the working company has the module on (docs/SPEC.md § 3 Modules). The company settings sit behind
 * « Paramètres » at the foot of the sidebar, grouped in their own area.
 */
export type NavSection = 'sell' | 'manage' | 'company' | 'fiscal' | 'team' | 'customisation';

/** What decides whether a user sees something the shell offers: a navigation entry or a command. */
export interface Gated {
  /** The permission string it needs; without one, every signed-in user sees it. */
  readonly permission?: string;
  /** Present in development builds only, such as the design checkpoint screens. */
  readonly devOnly?: boolean;
  /** The module it belongs to: shown only while the working company has that module on. */
  readonly module?: string;
}

export interface NavEntry extends Gated {
  readonly key: string;
  readonly labelKey: string;
  /** What the 80 px rail writes under the icon when the label is too long for it (docs/SPEC.md § 8 row 123). */
  readonly shortLabelKey?: string;
  /** A Material Symbols ligature. */
  readonly icon: string;
  readonly route: string;
  readonly section: NavSection;
  /** Present on an entry of the vision not built yet: it shows « Bientôt » and opens the « En construction » page. */
  readonly coming?: Coming;
}

/**
 * What the « En construction » page says of an entry not built yet (docs/SPEC.md § 7, 2026-09-25 11:17): what it will
 * do, whether it is for version 1 or later, the § 8 row that builds it, and what to use meanwhile. Its texts live
 * under `coming.<key>` in the translations.
 */
export interface Coming {
  /** The entry it follows in its section; an entry the person cannot see is skipped over, never waited for. */
  readonly after: string;
  readonly version: 'v1' | 'later';
  /** What to use until it exists, when something does the job today. */
  readonly meanwhile?: string;
  /** Whether a § 8 row builds it yet: its page then names that row, under `coming.<key>.plan`. */
  readonly plan?: true;
}

export interface NavGroup {
  readonly section: NavSection;
  readonly entries: readonly NavEntry[];
}

export const SIDEBAR_SECTIONS: readonly NavSection[] = ['sell', 'manage'];
export const SETTINGS_SECTIONS: readonly NavSection[] = [
  'company',
  'fiscal',
  'team',
  'customisation',
];

export const CORE_NAV: readonly NavEntry[] = [
  { key: 'home', labelKey: 'nav.home', icon: 'home', route: '/', section: 'sell' },
];

/**
 * What the sidebar lists after the modules: « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10), last of Gérer, for
 * whoever may read the company, each condition on it gated by its own module and permission.
 */
export const MANAGE_NAV: readonly NavEntry[] = [
  {
    key: 'watch',
    labelKey: 'nav.watch',
    icon: 'visibility',
    route: '/watch',
    section: 'manage',
    permission: 'company.read',
  },
];

/** The company settings, in the order of the settings area's groups; each needs a permission. */
export const SETTINGS_NAV: readonly NavEntry[] = [
  {
    key: 'company-profile',
    labelKey: 'nav.company_profile',
    icon: 'business',
    route: '/company/profile',
    section: 'company',
    permission: 'company.settings',
  },
  {
    key: 'company-security',
    labelKey: 'nav.company_security',
    icon: 'shield',
    route: '/company/security',
    section: 'company',
    permission: 'company.settings',
  },
  {
    key: 'establishments',
    labelKey: 'nav.establishments',
    icon: 'store',
    route: '/company/establishments',
    section: 'company',
    permission: 'company.settings',
  },
  {
    key: 'numbering',
    labelKey: 'nav.numbering',
    icon: 'format_list_numbered',
    route: '/company/numbering',
    section: 'company',
    permission: 'company.settings',
  },
  {
    key: 'subscription',
    labelKey: 'nav.subscription',
    icon: 'card_membership',
    route: '/company/subscription',
    section: 'company',
    permission: 'subscription.read',
  },
  {
    // Not `settings`: its test id would be `nav-settings`, which the main menu's gear already carries (row 153).
    key: 'defaults',
    labelKey: 'nav.settings',
    icon: 'tune',
    route: '/settings',
    section: 'company',
    permission: 'company.settings',
  },
  {
    key: 'taxes',
    labelKey: 'nav.taxes',
    icon: 'percent',
    route: '/fiscal/taxes',
    section: 'fiscal',
    permission: 'fiscal.read',
  },
  {
    key: 'units',
    labelKey: 'nav.units',
    icon: 'straighten',
    route: '/fiscal/units',
    section: 'fiscal',
    permission: 'fiscal.read',
  },
  {
    key: 'members',
    labelKey: 'nav.members',
    icon: 'group',
    route: '/members',
    section: 'team',
    permission: 'user.read',
  },
  {
    key: 'roles',
    labelKey: 'nav.roles',
    icon: 'admin_panel_settings',
    route: '/company/roles',
    section: 'team',
    permission: 'company.settings',
  },
  {
    key: 'custom-fields',
    labelKey: 'nav.custom_fields',
    icon: 'dynamic_form',
    route: '/company/custom-fields',
    section: 'customisation',
    permission: 'company.settings',
  },
  {
    key: 'modules',
    labelKey: 'nav.modules',
    icon: 'extension',
    route: '/company/modules',
    section: 'customisation',
    permission: 'company.settings',
  },
];

/** Where an entry not built yet opens: one shared page, keyed by the entry (docs/SPEC.md § 7, 2026-09-25 11:17). */
export const COMING_ROUTE = '/coming';
/** The same page inside the settings area, so it opens beside the settings list. */
export const COMING_SETTINGS_ROUTE = '/company/coming';

/**
 * The settings pages of the vision not built yet (docs/SPEC.md § 7, 2026-09-25 11:17; the round-6 settings board),
 * each placed after the one it follows. They are not modules; the planned modules come from the API's catalogue
 * (`planned-nav.ts`). An entry leaves this list in the change that builds it.
 */
export const COMING_NAV: readonly (NavEntry & { readonly coming: Coming })[] = [
  {
    key: 'document-templates',
    labelKey: 'nav.document_templates',
    icon: 'article',
    route: `${COMING_SETTINGS_ROUTE}/document-templates`,
    section: 'company',
    permission: 'company.settings',
    coming: { after: 'numbering', version: 'v1', meanwhile: '/company/profile', plan: true },
  },
  {
    key: 'alerts',
    labelKey: 'nav.alerts',
    icon: 'notifications_active',
    route: `${COMING_SETTINGS_ROUTE}/alerts`,
    section: 'company',
    permission: 'company.settings',
    coming: { after: 'defaults', version: 'v1', meanwhile: '/watch', plan: true },
  },
  {
    key: 'fiscal-preset',
    labelKey: 'nav.fiscal_preset',
    icon: 'gavel',
    route: `${COMING_SETTINGS_ROUTE}/fiscal-preset`,
    section: 'fiscal',
    permission: 'company.settings',
    coming: { after: 'units', version: 'later', meanwhile: '/fiscal/taxes', plan: true },
  },
  {
    key: 'support-access',
    labelKey: 'nav.support_access',
    icon: 'support_agent',
    route: `${COMING_SETTINGS_ROUTE}/support-access`,
    section: 'team',
    permission: 'company.settings',
    coming: { after: 'roles', version: 'later', plan: true },
  },
  {
    key: 'texts',
    labelKey: 'nav.texts',
    icon: 'translate',
    route: `${COMING_SETTINGS_ROUTE}/texts`,
    section: 'customisation',
    permission: 'company.settings',
    coming: { after: 'modules', version: 'later', plan: true },
  },
];

/**
 * The entries with the vision's coming ones placed among them, each right after the entry it follows, or last of its
 * section when that entry is hidden. A coming entry joins only a section the person already has: somebody who sells
 * nothing is not shown the till. With `show` off, only what works.
 */
export function withComing(
  entries: readonly NavEntry[],
  coming: readonly (NavEntry & { readonly coming: Coming })[],
  show: boolean,
): readonly NavEntry[] {
  if (!show) return entries;
  const result = [...entries];
  for (const entry of coming) {
    const at = result.findIndex((placed) => placed.key === entry.coming.after);
    if (at >= 0) {
      result.splice(at + 1, 0, entry);
      continue;
    }
    // The entry it follows is hidden here: it goes after the last one of its section, if the person has that section.
    const lastOfSection = result.map((placed) => placed.section).lastIndexOf(entry.section);
    if (lastOfSection >= 0) result.splice(lastOfSection + 1, 0, entry);
  }
  return result;
}

/**
 * What a phone's bottom bar puts around « Créer », most used first (the round-6 phone boards: Accueil, Factures,
 * Créer, Clients). A company without one of these modules gets the sidebar's next destinations in their place.
 */
export const PHONE_BAR_FIRST: readonly string[] = ['home', 'invoices', 'customers'];

/**
 * Entries for development builds only, never shipped (`devOnly`). Empty since the design checkpoint's fixture screens
 * were retired with the invoice screens (docs/SPEC.md § 7, 2026-09-16); the gate stays for the next one.
 */
export const DEV_NAV: readonly NavEntry[] = [];

/** Every module's entries, each declared by its module's web feature. */
export const MODULE_NAV: readonly NavEntry[] = [
  ...INVOICES_NAV,
  ...DELIVERY_NOTES_NAV,
  ...CUSTOMERS_NAV,
  ...PRODUCTS_NAV,
  ...INVENTORY_NAV,
  ...VENDORS_NAV,
  ...EXPENSES_NAV,
];

/**
 * The entries this user may see in this build, in the working company. Hiding is a courtesy: the API refuses what
 * the voter refuses, and answers 404 for a module the company has off.
 */
export function visibleEntries<T extends Gated>(
  entries: readonly T[],
  can: (permission: string) => boolean,
  developmentBuild: boolean,
  enabled: (module: string) => boolean,
): readonly T[] {
  return entries.filter(
    (entry) =>
      (entry.devOnly !== true || developmentBuild) &&
      (entry.permission === undefined || can(entry.permission)) &&
      (entry.module === undefined || enabled(entry.module)),
  );
}

export function navSections(
  entries: readonly NavEntry[],
  order: readonly NavSection[],
): readonly NavGroup[] {
  return order
    .map((section) => ({
      section,
      entries: entries.filter((entry) => entry.section === section),
    }))
    .filter((group) => group.entries.length > 0);
}

/**
 * The settings area's own address: on a phone the list of settings stands alone here, and on a wider window this is
 * where the area opens. Declared beside the routes it belongs with rather than imported from the component, so the
 * shell can ask about a URL without loading the area.
 */
export const SETTINGS_AREA_INDEX = '/company';

/**
 * Whether an address is inside the settings area. The shell reads it to fold its menu to the rail while in settings
 * (docs/SPEC.md § 7, 2026-09-19 23:21), so it must not answer yes to an address that merely starts with the same
 * letters: `/settings-of-mine` is not `/settings`, and `/companies` is not `/company`. A query or a fragment is not
 * part of the address for this purpose.
 */
export function isSettingsUrl(url: string): boolean {
  const path = url.split(/[?#]/)[0].replace(/\/+$/, '') || '/';
  return [SETTINGS_AREA_INDEX, ...SETTINGS_NAV.map((entry) => entry.route)].some(
    (route) => path === route || path.startsWith(`${route}/`),
  );
}

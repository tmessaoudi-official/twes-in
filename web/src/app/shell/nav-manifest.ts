// SPDX-License-Identifier: AGPL-3.0-or-later

import { CUSTOMERS_NAV } from '../customers/customers-nav';
import { DELIVERY_NOTES_NAV } from '../delivery-notes/delivery-notes-nav';
import { INVENTORY_NAV } from '../inventory/inventory-nav';
import { PRODUCTS_NAV } from '../products/products-nav';
import { VENDORS_NAV } from '../vendors/vendors-nav';
import { EXPENSES_NAV } from '../expenses/expenses-nav';

/**
 * What the shell offers. The sidebar keeps the daily entries: the core ones, then each module's, declared by the
 * module's web feature beside its routes and shown while the working company has the module on (docs/SPEC.md § 3
 * Modules). The company settings sit behind the gear in the top bar, grouped in their own area.
 */
export type NavSection = 'main' | 'company' | 'fiscal' | 'team' | 'customisation';

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
  /** A Material Symbols ligature. */
  readonly icon: string;
  readonly route: string;
  readonly section: NavSection;
}

export interface NavGroup {
  readonly section: NavSection;
  readonly entries: readonly NavEntry[];
}

export const SIDEBAR_SECTIONS: readonly NavSection[] = ['main'];
export const SETTINGS_SECTIONS: readonly NavSection[] = [
  'company',
  'fiscal',
  'team',
  'customisation',
];

export const CORE_NAV: readonly NavEntry[] = [
  { key: 'home', labelKey: 'nav.home', icon: 'home', route: '/', section: 'main' },
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
    key: 'settings',
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

/** The design checkpoint's fixture screens: a development build only, never shipped, last in the sidebar. */
export const DEV_NAV: readonly NavEntry[] = [
  {
    key: 'design',
    labelKey: 'nav.design',
    icon: 'palette',
    route: '/design',
    section: 'main',
    devOnly: true,
  },
];

/** Every module's entries, each declared by its module's web feature. */
export const MODULE_NAV: readonly NavEntry[] = [
  ...CUSTOMERS_NAV,
  ...PRODUCTS_NAV,
  ...DELIVERY_NOTES_NAV,
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

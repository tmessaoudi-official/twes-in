// SPDX-License-Identifier: AGPL-3.0-or-later

import { CUSTOMERS_NAV } from '../customers/customers-nav';
import { PRODUCTS_NAV } from '../products/products-nav';

/**
 * What the sidebar offers: the core entries, then each module's entries, declared by the module's web feature beside
 * its routes and shown while the working company has the module on (docs/SPEC.md § 3 Modules).
 */
export type NavSection = 'main' | 'admin';

export interface NavEntry {
  readonly key: string;
  readonly labelKey: string;
  /** A Material Symbols ligature. */
  readonly icon: string;
  readonly route: string;
  readonly section: NavSection;
  /** The permission string the entry needs; without one, every signed-in user sees it. */
  readonly permission?: string;
  /** Present in development builds only, such as the design checkpoint screens. */
  readonly devOnly?: boolean;
  /** The module the entry belongs to: shown only while the working company has that module on. */
  readonly module?: string;
}

export interface NavGroup {
  readonly section: NavSection;
  readonly entries: readonly NavEntry[];
}

const SECTION_ORDER: readonly NavSection[] = ['main', 'admin'];

export const CORE_NAV: readonly NavEntry[] = [
  { key: 'home', labelKey: 'nav.home', icon: 'home', route: '/', section: 'main' },
  {
    key: 'members',
    labelKey: 'nav.members',
    icon: 'group',
    route: '/members',
    section: 'admin',
    permission: 'user.read',
  },
  {
    key: 'taxes',
    labelKey: 'nav.taxes',
    icon: 'percent',
    route: '/fiscal/taxes',
    section: 'admin',
    permission: 'fiscal.read',
  },
  {
    key: 'units',
    labelKey: 'nav.units',
    icon: 'straighten',
    route: '/fiscal/units',
    section: 'admin',
    permission: 'fiscal.read',
  },
  {
    key: 'settings',
    labelKey: 'nav.settings',
    icon: 'tune',
    route: '/settings',
    section: 'admin',
    permission: 'company.settings',
  },
  {
    key: 'company-profile',
    labelKey: 'nav.company_profile',
    icon: 'business',
    route: '/company/profile',
    section: 'admin',
    permission: 'company.settings',
  },
  {
    key: 'establishments',
    labelKey: 'nav.establishments',
    icon: 'store',
    route: '/company/establishments',
    section: 'admin',
    permission: 'company.settings',
  },
  {
    key: 'numbering',
    labelKey: 'nav.numbering',
    icon: 'format_list_numbered',
    route: '/company/numbering',
    section: 'admin',
    permission: 'company.settings',
  },
  {
    key: 'custom-fields',
    labelKey: 'nav.custom_fields',
    icon: 'dynamic_form',
    route: '/company/custom-fields',
    section: 'admin',
    permission: 'company.settings',
  },
  {
    key: 'modules',
    labelKey: 'nav.modules',
    icon: 'extension',
    route: '/company/modules',
    section: 'admin',
    permission: 'company.settings',
  },
  // The design checkpoint's fixture screens: a development build only, never shipped.
  {
    key: 'design',
    labelKey: 'nav.design',
    icon: 'palette',
    route: '/design',
    section: 'admin',
    devOnly: true,
  },
];

/** Every module's entries, each declared by its module's web feature. */
export const MODULE_NAV: readonly NavEntry[] = [...CUSTOMERS_NAV, ...PRODUCTS_NAV];

/**
 * The entries this user may see in this build, in the working company. Hiding is a courtesy: the API refuses what
 * the voter refuses, and answers 404 for a module the company has off.
 */
export function visibleEntries(
  entries: readonly NavEntry[],
  can: (permission: string) => boolean,
  developmentBuild: boolean,
  enabled: (module: string) => boolean,
): readonly NavEntry[] {
  return entries.filter(
    (entry) =>
      (entry.devOnly !== true || developmentBuild) &&
      (entry.permission === undefined || can(entry.permission)) &&
      (entry.module === undefined || enabled(entry.module)),
  );
}

export function navSections(entries: readonly NavEntry[]): readonly NavGroup[] {
  return SECTION_ORDER.map((section) => ({
    section,
    entries: entries.filter((entry) => entry.section === section),
  })).filter((group) => group.entries.length > 0);
}

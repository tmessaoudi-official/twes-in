// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavEntry } from './nav-manifest';

/*
 * The core entries the routes guard as well as the menu shows: data alone, apart from the functions of nav-manifest.ts,
 * so the routes on the first page carry these arrays and nothing else of the shell.
 */

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
    // How the company's documents print: a layout, the accent and a live preview (docs/SPEC.md § 7, 2026-10-06 10:19).
    key: 'document-templates',
    labelKey: 'nav.document_templates',
    icon: 'article',
    route: '/company/documents',
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
    // The closed period: no document is dated on or before its last day.
    key: 'closing',
    labelKey: 'nav.closing',
    icon: 'lock',
    route: '/company/closing',
    section: 'fiscal',
    permission: 'company.settings',
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
    // « Journal d'activité »: what was done in the company, by whom (docs/SPEC.md § 7, 2026-09-26 23:04).
    key: 'activity',
    labelKey: 'nav.activity',
    icon: 'history',
    route: '/company/activity',
    section: 'team',
    permission: 'audit.read',
  },
  {
    // « Accès du support »: the owner's to open, like everything that lets someone outside the company in.
    key: 'support-access',
    labelKey: 'nav.support_access',
    icon: 'support_agent',
    route: '/company/support-access',
    section: 'team',
    permission: 'company.settings',
    role: 'owner',
  },
  {
    // « Récemment supprimés »: open to every member, each seeing only the deletes of what they may write.
    key: 'recently-deleted',
    labelKey: 'nav.recently_deleted',
    icon: 'history',
    route: '/company/recently-deleted',
    section: 'data',
    permission: 'company.read',
  },
  {
    // « Effacer des données »: the owner's alone, behind a fresh proof of who is at the screen (docs/SPEC.md § 7).
    key: 'data-erasure',
    labelKey: 'nav.data_erasure',
    icon: 'delete_forever',
    route: '/company/data-erasure',
    section: 'data',
    permission: 'company.settings',
    role: 'owner',
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
  {
    // The application's words in the company's own, screens, mails and printed labels alike.
    key: 'texts',
    labelKey: 'nav.texts',
    icon: 'translate',
    route: '/company/texts',
    section: 'customisation',
    permission: 'company.texts',
  },
];

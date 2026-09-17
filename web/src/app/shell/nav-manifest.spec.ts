// SPDX-License-Identifier: AGPL-3.0-or-later

import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import { CUSTOMERS_NAV } from '../customers/customers-nav';
import { DELIVERY_NOTES_NAV } from '../delivery-notes/delivery-notes-nav';
import { INVOICES_NAV } from '../invoices/invoices-nav';
import { INVENTORY_NAV } from '../inventory/inventory-nav';
import { PRODUCTS_NAV } from '../products/products-nav';
import { VENDORS_NAV } from '../vendors/vendors-nav';
import { EXPENSES_NAV } from '../expenses/expenses-nav';
import {
  CORE_NAV,
  DEV_NAV,
  MODULE_NAV,
  type NavEntry,
  navSections,
  SETTINGS_NAV,
  SETTINGS_SECTIONS,
  SIDEBAR_SECTIONS,
  visibleEntries,
} from './nav-manifest';

const entries: readonly NavEntry[] = [
  { key: 'home', labelKey: 'nav.home', icon: 'home', route: '/', section: 'main' },
  {
    key: 'customers',
    labelKey: 'nav.customers',
    icon: 'contacts',
    route: '/customers',
    section: 'main',
    permission: 'customer.read',
    module: 'customers',
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
    key: 'design',
    labelKey: 'nav.design',
    icon: 'palette',
    route: '/design',
    section: 'main',
    devOnly: true,
  },
];

const allOn = () => true;

function keys(list: readonly NavEntry[]): string[] {
  return list.map((entry) => entry.key);
}

function hasKey(json: unknown, path: string): boolean {
  let node: unknown = json;
  for (const part of path.split('.')) {
    if (typeof node !== 'object' || node === null || !(part in node)) {
      return false;
    }
    node = (node as Record<string, unknown>)[part];
  }
  return typeof node === 'string' && node !== '';
}

describe('visibleEntries', () => {
  it('keeps entries that need no permission and those whose permission the user holds', () => {
    const can = (permission: string) => permission === 'user.read';
    expect(keys(visibleEntries(entries, can, false, allOn))).toEqual(['home', 'members']);
  });

  it('hides an entry whose permission the user lacks', () => {
    expect(keys(visibleEntries(entries, () => false, false, allOn))).toEqual(['home']);
  });

  it('shows development-only entries in a development build only', () => {
    expect(keys(visibleEntries(entries, () => true, true, allOn))).toEqual([
      'home',
      'customers',
      'members',
      'design',
    ]);
    expect(keys(visibleEntries(entries, () => true, false, allOn))).not.toContain('design');
  });

  it("keeps a module's entries only while the company has the module on", () => {
    const on = (module: string) => module !== 'customers';
    expect(keys(visibleEntries(entries, () => true, false, on))).toEqual(['home', 'members']);
    expect(
      keys(
        visibleEntries(
          entries,
          () => true,
          false,
          () => false,
        ),
      ),
    ).toEqual(['home', 'members']);
    expect(keys(visibleEntries(entries, () => false, false, allOn))).not.toContain('customers');
  });
});

describe('navSections', () => {
  it('groups entries by section in the given order and drops empty sections', () => {
    expect(
      navSections(entries, ['main', 'company', 'team']).map((s) => [s.section, keys(s.entries)]),
    ).toEqual([
      ['main', ['home', 'customers', 'design']],
      ['team', ['members']],
    ]);
    expect(navSections(entries, ['team']).map((s) => s.section)).toEqual(['team']);
  });
});

describe('the navigation manifest', () => {
  const sidebar = [...CORE_NAV, ...MODULE_NAV, ...DEV_NAV];
  const all = [...sidebar, ...SETTINGS_NAV];

  it('has unique keys and routes across the core and the modules', () => {
    expect(new Set(keys(all)).size).toBe(all.length);
    expect(new Set(all.map((entry) => entry.route)).size).toBe(all.length);
  });

  it('names every entry and its section in both languages', () => {
    for (const entry of all) {
      expect(hasKey(fr, entry.labelKey), `fr ${entry.labelKey}`).toBe(true);
      expect(hasKey(en, entry.labelKey), `en ${entry.labelKey}`).toBe(true);
      expect(hasKey(fr, `nav.sections.${entry.section}`), `fr section ${entry.section}`).toBe(true);
      expect(hasKey(en, `nav.sections.${entry.section}`), `en section ${entry.section}`).toBe(true);
    }
  });

  it('keeps the daily entries in the sidebar and the company settings behind the gear', () => {
    expect(sidebar.filter((entry) => !SIDEBAR_SECTIONS.includes(entry.section))).toEqual([]);
    expect(
      navSections(SETTINGS_NAV, SETTINGS_SECTIONS).map((group) => [
        group.section,
        keys(group.entries),
      ]),
    ).toEqual([
      [
        'company',
        [
          'company-profile',
          'company-security',
          'establishments',
          'numbering',
          'subscription',
          'settings',
        ],
      ],
      ['fiscal', ['taxes', 'units']],
      ['team', ['members']],
      ['customisation', ['custom-fields', 'modules']],
    ]);
    expect(SETTINGS_NAV.every((entry) => entry.permission !== undefined)).toBe(true);
    expect(DEV_NAV.every((entry) => entry.devOnly === true)).toBe(true);
  });

  it('ties every module entry to its module and no core entry to one', () => {
    expect(
      [...CORE_NAV, ...SETTINGS_NAV, ...DEV_NAV].filter((entry) => entry.module !== undefined),
    ).toEqual([]);
    // Invoices come first: on a phone the bottom bar shows the first four destinations.
    expect(
      INVOICES_NAV.map((entry) => [entry.key, entry.module, entry.permission, entry.route]),
    ).toEqual([['invoices', 'invoices', 'invoice.read', '/invoices']]);
    // Customer groups and product categories are tabs of their module's screen, not sidebar entries.
    expect(CUSTOMERS_NAV.map((entry) => [entry.key, entry.module])).toEqual([
      ['customers', 'customers'],
    ]);
    expect(PRODUCTS_NAV.map((entry) => [entry.key, entry.module, entry.permission])).toEqual([
      ['products', 'products', 'product.read'],
    ]);
    expect(
      DELIVERY_NOTES_NAV.map((entry) => [entry.key, entry.module, entry.permission, entry.route]),
    ).toEqual([['delivery-notes', 'delivery_notes', 'delivery_note.read', '/delivery-notes']]);
    // Movements and locations are tabs of the stock screen.
    expect(
      INVENTORY_NAV.map((entry) => [entry.key, entry.module, entry.permission, entry.route]),
    ).toEqual([['stock', 'inventory', 'stock.read', '/stock']]);
    expect(
      VENDORS_NAV.map((entry) => [entry.key, entry.module, entry.permission, entry.route]),
    ).toEqual([['vendors', 'vendors', 'vendor.read', '/vendors']]);
    // Categories are a tab of the expenses screen.
    expect(
      EXPENSES_NAV.map((entry) => [entry.key, entry.module, entry.permission, entry.route]),
    ).toEqual([['expenses', 'expenses', 'expense.read', '/expenses']]);
    expect(MODULE_NAV).toEqual([
      ...INVOICES_NAV,
      ...CUSTOMERS_NAV,
      ...PRODUCTS_NAV,
      ...DELIVERY_NOTES_NAV,
      ...INVENTORY_NAV,
      ...VENDORS_NAV,
      ...EXPENSES_NAV,
    ]);
    expect(MODULE_NAV.filter((entry) => entry.module === undefined)).toEqual([]);
  });

  it('offers the modules screen to whoever may change the company settings', () => {
    expect(SETTINGS_NAV.find((entry) => entry.key === 'modules')).toEqual({
      key: 'modules',
      labelKey: 'nav.modules',
      icon: 'extension',
      route: '/company/modules',
      section: 'customisation',
      permission: 'company.settings',
    });
  });
});

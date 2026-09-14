// SPDX-License-Identifier: AGPL-3.0-or-later

import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import { CUSTOMERS_NAV } from '../customers/customers-nav';
import { PRODUCTS_NAV } from '../products/products-nav';
import { CORE_NAV, MODULE_NAV, type NavEntry, navSections, visibleEntries } from './nav-manifest';

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
    section: 'admin',
    permission: 'user.read',
  },
  {
    key: 'design',
    labelKey: 'nav.design',
    icon: 'palette',
    route: '/design',
    section: 'admin',
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
  it('groups entries by section in manifest order and drops empty sections', () => {
    expect(navSections(entries).map((s) => [s.section, keys(s.entries)])).toEqual([
      ['main', ['home', 'customers']],
      ['admin', ['members', 'design']],
    ]);
    expect(navSections([entries[0]]).map((s) => s.section)).toEqual(['main']);
  });
});

describe('the navigation manifest', () => {
  const all = [...CORE_NAV, ...MODULE_NAV];

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

  it('ties every module entry to its module and no core entry to one', () => {
    expect(CORE_NAV.filter((entry) => entry.module !== undefined)).toEqual([]);
    expect(CUSTOMERS_NAV.map((entry) => [entry.key, entry.module])).toEqual([
      ['customers', 'customers'],
      ['customer-groups', 'customers'],
    ]);
    expect(PRODUCTS_NAV.map((entry) => [entry.key, entry.module, entry.permission])).toEqual([
      ['products', 'products', 'product.read'],
      ['product-categories', 'products', 'product.read'],
    ]);
    expect(MODULE_NAV).toEqual([...CUSTOMERS_NAV, ...PRODUCTS_NAV]);
    expect(MODULE_NAV.filter((entry) => entry.module === undefined)).toEqual([]);
  });

  it('offers the modules screen to whoever may change the company settings', () => {
    expect(CORE_NAV.find((entry) => entry.key === 'modules')).toEqual({
      key: 'modules',
      labelKey: 'nav.modules',
      icon: 'extension',
      route: '/company/modules',
      section: 'admin',
      permission: 'company.settings',
    });
  });
});

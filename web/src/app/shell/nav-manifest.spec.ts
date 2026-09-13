// SPDX-License-Identifier: AGPL-3.0-or-later

import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import { CORE_NAV, type NavEntry, navSections, visibleEntries } from './nav-manifest';

const entries: readonly NavEntry[] = [
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
    key: 'design',
    labelKey: 'nav.design',
    icon: 'palette',
    route: '/design',
    section: 'admin',
    devOnly: true,
  },
];

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
    expect(keys(visibleEntries(entries, can, false))).toEqual(['home', 'members']);
  });

  it('hides an entry whose permission the user lacks', () => {
    expect(keys(visibleEntries(entries, () => false, false))).toEqual(['home']);
  });

  it('shows development-only entries in a development build only', () => {
    expect(keys(visibleEntries(entries, () => true, true))).toEqual(['home', 'members', 'design']);
    expect(keys(visibleEntries(entries, () => true, false))).not.toContain('design');
  });
});

describe('navSections', () => {
  it('groups entries by section in manifest order and drops empty sections', () => {
    expect(navSections(entries).map((s) => [s.section, keys(s.entries)])).toEqual([
      ['main', ['home']],
      ['admin', ['members', 'design']],
    ]);
    expect(navSections([entries[0]]).map((s) => s.section)).toEqual(['main']);
  });
});

describe('CORE_NAV', () => {
  it('has unique keys and routes', () => {
    expect(new Set(keys(CORE_NAV)).size).toBe(CORE_NAV.length);
    expect(new Set(CORE_NAV.map((entry) => entry.route)).size).toBe(CORE_NAV.length);
  });

  it('names every entry and its section in both languages', () => {
    for (const entry of CORE_NAV) {
      expect(hasKey(fr, entry.labelKey), `fr ${entry.labelKey}`).toBe(true);
      expect(hasKey(en, entry.labelKey), `en ${entry.labelKey}`).toBe(true);
      expect(hasKey(fr, `nav.sections.${entry.section}`), `fr section ${entry.section}`).toBe(true);
      expect(hasKey(en, `nav.sections.${entry.section}`), `en section ${entry.section}`).toBe(true);
    }
  });
});

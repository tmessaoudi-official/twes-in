// SPDX-License-Identifier: AGPL-3.0-or-later

import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import type { PlannedModule } from '../auth/auth-types';
import {
  COMING_NAV,
  CORE_NAV,
  DEV_NAV,
  MANAGE_NAV,
  MODULE_NAV,
  navSections,
  SIDEBAR_SECTIONS,
  withComing,
} from './nav-manifest';
import { comingEntries, PLANNED_NAV, plannedCommands, plannedNav } from './planned-nav';

const hasKey = (json: unknown, path: string): boolean =>
  typeof path
    .split('.')
    .reduce<unknown>(
      (node, part) =>
        node !== null && typeof node === 'object'
          ? (node as Record<string, unknown>)[part]
          : undefined,
      json,
    ) === 'string';

/** Every planned module, as the API's catalogue lists them (api/src/ModuleRegistry/Application/PlannedModules.php). */
const catalogue: readonly PlannedModule[] = PLANNED_NAV.map((place) => ({
  key: place.key,
  planned: 'v1',
}));

// docs/SPEC.md § 7, 2026-09-26 10:08 and 12:05 (row 150): the planned modules reach the menu from the API's catalogue,
// each in the section where it will live, opening its « En construction » page.
describe('the planned modules in the menu', () => {
  const sidebar = [...CORE_NAV, ...MODULE_NAV, ...MANAGE_NAV, ...DEV_NAV];

  it('shows exactly what the API lists as planned, at the version it gives, never from a list of its own', () => {
    const shown = plannedNav([
      { key: 'zakat', planned: 'later' },
      { key: 'quotes', planned: 'v1' },
      // A key the web has no place for yet is left out rather than drawn without an icon or a section.
      { key: 'teleportation', planned: 'v1' },
    ]);

    expect(shown.map((entry) => [entry.key, entry.coming.version, entry.route])).toEqual([
      ['quotes', 'v1', '/coming/quotes'],
      ['zakat', 'later', '/coming/zakat'],
    ]);
    expect(shown[0].labelKey).toBe('modules.quotes');
    expect(plannedNav([])).toEqual([]);
    expect(plannedNav(undefined)).toEqual([]);
  });

  it('places each in the section where it will live, after the entry it follows', () => {
    const shown = withComing(sidebar, comingEntries(catalogue), true);

    expect(
      navSections(shown, SIDEBAR_SECTIONS).map((g) => [g.section, g.entries.map((e) => e.key)]),
    ).toEqual([
      [
        'sell',
        [
          'home',
          'invoices',
          'quotes',
          'recurring',
          'delivery-notes',
          'customers',
          'statements',
          'mailing',
          'whatsapp',
          'portal',
          'products',
          'price_lists',
          'composites',
          'register',
          'works',
          'venue',
          'menu',
          'service',
          'guests',
          'ratings',
        ],
      ],
      [
        'manage',
        [
          'stock',
          'stock_valuation',
          'vendors',
          'purchases',
          'expenses',
          'reports',
          'declarations',
          'accounting_export',
          'einvoicing',
          'currencies',
          'zakat',
          'watch',
        ],
      ],
    ]);
  });

  it('keeps the settings pages not built yet, which are not modules, in the web’s own list', () => {
    expect(COMING_NAV.map((entry) => entry.key)).toEqual([
      'document-templates',
      'alerts',
      'fiscal-preset',
      'support-access',
      'texts',
    ]);
    expect(comingEntries(undefined)).toEqual(COMING_NAV);
    const keys = comingEntries(catalogue).map((entry) => entry.key);
    expect(new Set(keys).size).toBe(keys.length);
  });

  it('names each, says what it will do, and its plan row and meanwhile where it has them, in both languages', () => {
    expect(PLANNED_NAV.length).toBe(23);
    for (const entry of plannedNav(catalogue)) {
      for (const json of [fr, en]) {
        for (const key of [
          entry.labelKey,
          `coming.${entry.key}.heading`,
          `coming.${entry.key}.does`,
        ]) {
          expect(hasKey(json, key), key).toBe(true);
        }
        expect(hasKey(json, `coming.${entry.key}.plan`), `${entry.key} plan`).toBe(
          entry.coming.plan === true,
        );
        if (entry.coming.meanwhile !== undefined) {
          expect(hasKey(json, `coming.${entry.key}.meanwhile`), entry.key).toBe(true);
          expect(hasKey(json, `coming.${entry.key}.meanwhile_link`), entry.key).toBe(true);
        }
      }
    }
  });

  // The same entries reach « Créer » and the Ctrl K palette, marked, and never create anything (row 150, slice 4).
  it('offers each in the palette, and the documents they will create in « Créer », all opening their page', () => {
    const commands = plannedCommands([
      { key: 'quotes', planned: 'v1' },
      { key: 'zakat', planned: 'later' },
    ]);

    expect(commands.map((c) => [c.key, c.group, c.route, c.labelKey, c.coming])).toEqual([
      ['new-quotes', 'create', '/coming/quotes', 'coming.quotes.create', true],
      ['goto-quotes', 'goto', '/coming/quotes', 'modules.quotes', true],
      ['goto-zakat', 'goto', '/coming/zakat', 'modules.zakat', true],
    ]);
    expect(plannedCommands(undefined)).toEqual([]);
  });

  it('names what each creation will make, in both languages', () => {
    const creations = plannedCommands(catalogue).filter((c) => c.group === 'create');
    expect(creations.map((c) => c.key)).toEqual([
      'new-quotes',
      'new-recurring',
      'new-price_lists',
      'new-register',
      'new-works',
      'new-purchases',
    ]);
    for (const command of creations) {
      for (const json of [fr, en]) {
        expect(hasKey(json, command.labelKey), command.labelKey).toBe(true);
      }
    }
  });
});

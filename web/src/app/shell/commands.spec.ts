// SPDX-License-Identifier: AGPL-3.0-or-later

import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import { CUSTOMERS_COMMANDS } from '../customers/customers-nav';
import { DELIVERY_NOTES_COMMANDS } from '../delivery-notes/delivery-notes-nav';
import { EXPENSES_COMMANDS } from '../expenses/expenses-nav';
import { INVOICES_COMMANDS } from '../invoices/invoices-nav';
import { PRODUCTS_COMMANDS } from '../products/products-nav';
import { VENDORS_COMMANDS } from '../vendors/vendors-nav';
import { type Command, matchCommands, MODULE_COMMANDS, navCommands } from './commands';
import { CORE_NAV, MODULE_NAV, SETTINGS_NAV, visibleEntries } from './nav-manifest';

function hasKey(tree: unknown, path: string): boolean {
  let node = tree;
  for (const part of path.split('.')) {
    if (typeof node !== 'object' || node === null || !(part in node)) {
      return false;
    }
    node = (node as Record<string, unknown>)[part];
  }
  return typeof node === 'string';
}

const LABELS: Record<string, string> = {
  'customers.new_title': 'Nouveau client',
  'expenses.new_title': 'Nouvelle dépense',
  'nav.home': 'Accueil',
  'nav.expenses': 'Dépenses',
  'nav.customers': 'Clients',
};
const label = (command: Command) => LABELS[command.labelKey] ?? command.labelKey;

const create = (key: string, labelKey: string): Command => ({
  key,
  labelKey,
  icon: 'add',
  route: `/${key}/new`,
  group: 'create',
});

describe('navCommands', () => {
  it('turns each navigation entry into a "go to" command keeping its route and its gates', () => {
    expect(navCommands(MODULE_NAV.slice(0, 1))).toEqual([
      {
        key: 'goto-invoices',
        labelKey: 'nav.invoices',
        icon: 'receipt_long',
        route: '/invoices',
        group: 'goto',
        permission: 'invoice.read',
        module: 'invoices',
      },
    ]);
  });
});

describe('matchCommands', () => {
  const commands: readonly Command[] = [
    ...navCommands([...CORE_NAV, ...MODULE_NAV.filter((entry) => entry.key === 'expenses')]),
    create('customers', 'customers.new_title'),
    create('expenses', 'expenses.new_title'),
  ];

  it('lists every command, creations first, when nothing is typed', () => {
    expect(matchCommands(commands, label, '  ').map((command) => command.key)).toEqual([
      'customers',
      'expenses',
      'goto-home',
      'goto-expenses',
    ]);
  });

  it('ignores case and accents, as a French keyboard without dead keys types them', () => {
    expect(matchCommands(commands, label, 'DEPENSE').map((command) => command.key)).toEqual([
      'expenses',
      'goto-expenses',
    ]);
  });

  it('needs every typed word, in any order', () => {
    expect(matchCommands(commands, label, 'dépense nouv').map((command) => command.key)).toEqual([
      'expenses',
    ]);
    expect(matchCommands(commands, label, 'client facture')).toEqual([]);
  });
});

describe('MODULE_COMMANDS', () => {
  it("gathers each module's own commands, each gated by its module and a write permission", () => {
    expect(MODULE_COMMANDS).toEqual([
      ...INVOICES_COMMANDS,
      ...CUSTOMERS_COMMANDS,
      ...PRODUCTS_COMMANDS,
      ...DELIVERY_NOTES_COMMANDS,
      ...VENDORS_COMMANDS,
      ...EXPENSES_COMMANDS,
    ]);
    expect(
      MODULE_COMMANDS.map((command) => [command.route, command.module, command.permission]),
    ).toEqual([
      ['/invoices/new', 'invoices', 'invoice.write'],
      ['/customers/new', 'customers', 'customer.write'],
      ['/products/new', 'products', 'product.write'],
      ['/delivery-notes/new', 'delivery_notes', 'delivery_note.write'],
      ['/vendors/new', 'vendors', 'vendor.write'],
      ['/expenses/new', 'expenses', 'expense.write'],
    ]);
    expect(MODULE_COMMANDS.every((command) => command.group === 'create')).toBe(true);
  });

  it('hides a command whose module is off or whose permission the user lacks', () => {
    const visible = visibleEntries(
      MODULE_COMMANDS,
      (permission) => permission === 'customer.write' || permission === 'expense.write',
      false,
      (module) => module !== 'expenses',
    );
    expect(visible.map((command) => command.key)).toEqual(['new-customer']);
  });

  it('has unique keys, and names every command in both languages', () => {
    const all = [...MODULE_COMMANDS, ...navCommands([...CORE_NAV, ...MODULE_NAV, ...SETTINGS_NAV])];
    expect(new Set(all.map((command) => command.key)).size).toBe(all.length);
    for (const command of all) {
      expect(hasKey(fr, command.labelKey), `fr ${command.labelKey}`).toBe(true);
      expect(hasKey(en, command.labelKey), `en ${command.labelKey}`).toBe(true);
    }
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { CUSTOMERS_COMMANDS } from '../customers/customers-nav';
import { DELIVERY_NOTES_COMMANDS } from '../delivery-notes/delivery-notes-nav';
import { EXPENSES_COMMANDS } from '../expenses/expenses-nav';
import { INVOICES_COMMANDS } from '../invoices/invoices-nav';
import { PRODUCTS_COMMANDS } from '../products/products-nav';
import { VENDORS_COMMANDS } from '../vendors/vendors-nav';
import type { Gated, NavEntry } from './nav-manifest';

/** Where a command sits in the palette: what it creates, then where it goes. */
export type CommandGroup = 'create' | 'goto';
export const COMMAND_GROUPS: readonly CommandGroup[] = ['create', 'goto'];

/**
 * One line of the command palette (Ctrl K). A module declares its own beside its navigation, gated like a navigation
 * entry, and the shell adds a "go to" command for every entry the user may see (docs/SPEC.md § 7, 2026-09-16).
 */
export interface Command extends Gated {
  readonly key: string;
  readonly labelKey: string;
  /** A Material Symbols ligature. */
  readonly icon: string;
  readonly route: string;
  readonly group: CommandGroup;
}

/** Every module's commands, each declared by its module's web feature. */
export const MODULE_COMMANDS: readonly Command[] = [
  ...INVOICES_COMMANDS,
  ...CUSTOMERS_COMMANDS,
  ...PRODUCTS_COMMANDS,
  ...DELIVERY_NOTES_COMMANDS,
  ...VENDORS_COMMANDS,
  ...EXPENSES_COMMANDS,
];

export function navCommands(entries: readonly NavEntry[]): readonly Command[] {
  return entries.map(({ key, labelKey, icon, route, permission, module, devOnly }) => ({
    key: `goto-${key}`,
    labelKey,
    icon,
    route,
    group: 'goto',
    ...(permission === undefined ? {} : { permission }),
    ...(module === undefined ? {} : { module }),
    ...(devOnly === undefined ? {} : { devOnly }),
  }));
}

/** Lower case without accents: "Dépenses" and "DEPENSES" are the same word to someone typing fast. */
function fold(text: string): string {
  return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
}

/**
 * The commands whose label holds every typed word, in any order, creations before destinations and otherwise in
 * the order given.
 */
export function matchCommands(
  commands: readonly Command[],
  label: (command: Command) => string,
  query: string,
): readonly Command[] {
  const words = fold(query).split(/\s+/).filter(Boolean);
  const matching = commands.filter((command) => {
    const text = fold(label(command));
    return words.every((word) => text.includes(word));
  });
  return COMMAND_GROUPS.flatMap((group) => matching.filter((command) => command.group === group));
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Command } from '../shell/commands';
import type { NavEntry } from '../shell/nav-manifest';
import type { PageTab } from '../shared/ui/page-tabs';

/** The key the API's module registry knows the expenses module by. */
export const EXPENSES_MODULE = 'expenses';

/** The expenses module's navigation, shown while the working company has the module on (docs/SPEC.md § 3 Modules). */
export const EXPENSES_NAV: readonly NavEntry[] = [
  {
    key: 'expenses',
    labelKey: 'nav.expenses',
    icon: 'receipt_long',
    route: '/expenses',
    section: 'main',
    permission: 'expense.read',
    module: EXPENSES_MODULE,
  },
];

/** The expenses and their categories as tabs, one sidebar entry between them. */
export const EXPENSES_TABS: readonly PageTab[] = [
  { labelKey: 'nav.expenses', route: '/expenses', testId: 'expenses-tab' },
  {
    labelKey: 'nav.expense_categories',
    route: '/expenses/categories',
    testId: 'expense-categories-link',
  },
];

/** What the module adds to the command palette (Ctrl K): creating one, for whoever may. */
export const EXPENSES_COMMANDS: readonly Command[] = [
  {
    key: 'new-expense',
    labelKey: 'expenses.new_title',
    icon: 'receipt_long',
    route: '/expenses/new',
    group: 'create',
    permission: 'expense.write',
    module: EXPENSES_MODULE,
  },
];

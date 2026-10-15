// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavigateCommand } from '../shell/commands';
import type { HomePanel } from '../shell/home-manifest';
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
    section: 'manage',
    permission: 'expense.read',
    module: EXPENSES_MODULE,
  },
];

/** The key the API's module registry knows the recurring expenses by: a module of their own, beside the expenses. */
export const RECURRING_EXPENSES_MODULE = 'recurring_expenses';

/**
 * The expenses, their recurring ones while the company has them, and their categories as tabs, one sidebar entry
 * between them: a recurring expense is a model that makes expenses, never a menu entry of its own (docs/SPEC.md § 7).
 */
export function expensesTabs(recurring: boolean): readonly PageTab[] {
  return [
    { labelKey: 'nav.expenses', route: '/expenses', testId: 'expenses-tab' },
    ...(recurring
      ? [
          {
            labelKey: 'recurring.tab',
            route: '/expenses/recurring',
            testId: 'recurring-expenses-tab',
          },
        ]
      : []),
    {
      labelKey: 'nav.expense_categories',
      route: '/expenses/categories',
      testId: 'expense-categories-link',
    },
  ];
}

/** What the module adds to the command palette (Ctrl K): creating one, for whoever may. */
export const EXPENSES_COMMANDS: readonly NavigateCommand[] = [
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

/** What the module shows on the home page: what was recorded in expenses this month, never called a profit. */
export const EXPENSES_HOME: readonly HomePanel[] = [
  {
    key: 'expenses',
    permission: 'expense.read',
    module: EXPENSES_MODULE,
    load: () => import('./expenses-home').then((feature) => feature.ExpensesHome),
  },
];

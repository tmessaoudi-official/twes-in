// SPDX-License-Identifier: AGPL-3.0-or-later

import { EXPENSES_HOME } from '../expenses/expenses-nav';
import { ExpensesHome } from '../expenses/expenses-home';
import { FIRST_STEPS_HOME } from '../first-steps/first-steps-nav';
import { FirstStepsHome } from '../first-steps/first-steps-home';
import { INSTALL_HOME } from '../install/install-nav';
import { InstallHome } from '../install/install-home';
import { INVENTORY_HOME } from '../inventory/inventory-nav';
import { StockHome } from '../inventory/home/stock-home';
import { INVOICES_HOME } from '../invoices/invoices-nav';
import { InvoicesHome } from '../invoices/invoices-home';
import { WATCH_HOME } from '../watch/watch-nav';
import { WatchHome } from '../watch/watch-home';
import { HOME_PANELS, NO_HOME_PANEL } from './home-manifest';
import { MODULE_NAV, visibleEntries } from './nav-manifest';

describe('home manifest', () => {
  /**
   * One order for every profile (D5): the install offer a phone may take, « Premiers pas » right under the header while
   * steps remain (T2), the invoices' figures across the page, then what to watch, the stock and the expenses side by
   * side.
   */
  it('collects each module’s panels: the install offer, the first steps, the invoices, then what to watch, the stock and the expenses', () => {
    expect(HOME_PANELS.map((panel) => panel.key)).toEqual([
      ...INSTALL_HOME.map((panel) => panel.key),
      ...FIRST_STEPS_HOME.map((panel) => panel.key),
      ...INVOICES_HOME.map((panel) => panel.key),
      ...WATCH_HOME.map((panel) => panel.key),
      ...INVENTORY_HOME.map((panel) => panel.key),
      ...EXPENSES_HOME.map((panel) => panel.key),
    ]);
    // Installing is the device's: no module, no permission.
    expect(HOME_PANELS[0]).toEqual({ key: 'install', load: expect.any(Function) });
    expect(HOME_PANELS[1]).toMatchObject({ key: 'first-steps', permission: 'company.read' });
    expect(HOME_PANELS[1]).not.toHaveProperty('module');
    expect(HOME_PANELS[2]).toMatchObject({
      key: 'invoices',
      module: 'invoices',
      permission: 'invoice.read',
    });
    expect(HOME_PANELS[3]).toMatchObject({
      key: 'watch',
      permission: 'company.read',
      span: 'half',
    });
    expect(HOME_PANELS[4]).toMatchObject({
      key: 'stock',
      module: 'inventory',
      permission: 'stock.read',
      span: 'half',
    });
  });

  /** The class « a module contributes no home panel »: every module the menu offers has a panel or says why it has none. */
  it('gives every module of the menu a panel on the home, or the reason it has none', () => {
    const modules = [...new Set(MODULE_NAV.map((entry) => entry.module))];
    const withPanel = new Set(HOME_PANELS.map((panel) => panel.module));
    expect(modules.length).toBeGreaterThanOrEqual(12);
    for (const module of modules) {
      expect(
        module !== undefined && (withPanel.has(module) || (NO_HOME_PANEL[module] ?? '') !== ''),
        `${module} has no home panel and no reason`,
      ).toBe(true);
    }
    // A reason kept for a module that has since been given a panel, or that is gone, would hide the next one.
    for (const module of Object.keys(NO_HOME_PANEL)) {
      expect(modules, `${module} is no menu module`).toContain(module);
      expect(withPanel.has(module), `${module} has a panel and a reason`).toBe(false);
    }
  });

  it('shows the invoices panel only to a reader of invoices in a company that has them on', () => {
    const shown = (permissions: string[], modules: string[]) =>
      visibleEntries(
        HOME_PANELS,
        (permission) => permissions.includes(permission),
        false,
        (module) => modules.includes(module),
      ).map((panel) => panel.key);
    expect(shown(['invoice.read'], ['invoices'])).toEqual(['install', 'invoices']);
    expect(shown(['expense.read'], ['expenses'])).toEqual(['install', 'expenses']);
    expect(shown(['expense.read'], ['invoices'])).toEqual(['install']);
    expect(shown([], ['invoices'])).toEqual(['install']);
    expect(shown(['invoice.read'], [])).toEqual(['install']);
    expect(shown(['company.read'], [])).toEqual(['install', 'first-steps', 'watch']);
    expect(shown(['stock.read'], ['inventory'])).toEqual(['install', 'stock']);
    expect(shown(['stock.read'], [])).toEqual(['install']);
    expect(shown([], ['inventory'])).toEqual(['install']);
  });

  it('loads the invoices panel’s component only when it is shown', async () => {
    await expect(INVOICES_HOME[0]?.load()).resolves.toBe(InvoicesHome);
    await expect(EXPENSES_HOME[0]?.load()).resolves.toBe(ExpensesHome);
    await expect(WATCH_HOME[0]?.load()).resolves.toBe(WatchHome);
    await expect(FIRST_STEPS_HOME[0]?.load()).resolves.toBe(FirstStepsHome);
    await expect(INSTALL_HOME[0]?.load()).resolves.toBe(InstallHome);
    await expect(INVENTORY_HOME[0]?.load()).resolves.toBe(StockHome);
  });
});

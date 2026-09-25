// SPDX-License-Identifier: AGPL-3.0-or-later

import { FIRST_STEPS_HOME } from '../first-steps/first-steps-nav';
import { FirstStepsHome } from '../first-steps/first-steps-home';
import { INVOICES_HOME } from '../invoices/invoices-nav';
import { InvoicesHome } from '../invoices/invoices-home';
import { WATCH_HOME } from '../watch/watch-nav';
import { WatchHome } from '../watch/watch-home';
import { HOME_PANELS } from './home-manifest';
import { visibleEntries } from './nav-manifest';

describe('home manifest', () => {
  it('collects each module’s panels: the first steps, then what to watch, then the invoices', () => {
    expect(HOME_PANELS.map((panel) => panel.key)).toEqual([
      ...FIRST_STEPS_HOME.map((panel) => panel.key),
      ...WATCH_HOME.map((panel) => panel.key),
      ...INVOICES_HOME.map((panel) => panel.key),
    ]);
    expect(HOME_PANELS[0]).toMatchObject({ key: 'first-steps', permission: 'company.read' });
    expect(HOME_PANELS[0]).not.toHaveProperty('module');
    expect(HOME_PANELS[1]).toMatchObject({ key: 'watch', permission: 'company.read' });
    expect(HOME_PANELS[2]).toMatchObject({
      key: 'invoices',
      module: 'invoices',
      permission: 'invoice.read',
    });
  });

  it('shows the invoices panel only to a reader of invoices in a company that has them on', () => {
    const shown = (permissions: string[], modules: string[]) =>
      visibleEntries(
        HOME_PANELS,
        (permission) => permissions.includes(permission),
        false,
        (module) => modules.includes(module),
      ).map((panel) => panel.key);
    expect(shown(['invoice.read'], ['invoices'])).toEqual(['invoices']);
    expect(shown([], ['invoices'])).toEqual([]);
    expect(shown(['invoice.read'], [])).toEqual([]);
    expect(shown(['company.read'], [])).toEqual(['first-steps', 'watch']);
  });

  it('loads the invoices panel’s component only when it is shown', async () => {
    await expect(INVOICES_HOME[0]?.load()).resolves.toBe(InvoicesHome);
    await expect(WATCH_HOME[0]?.load()).resolves.toBe(WatchHome);
    await expect(FIRST_STEPS_HOME[0]?.load()).resolves.toBe(FirstStepsHome);
  });
});

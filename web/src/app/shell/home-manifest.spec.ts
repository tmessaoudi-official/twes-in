// SPDX-License-Identifier: AGPL-3.0-or-later

import { INVOICES_HOME } from '../invoices/invoices-nav';
import { InvoicesHome } from '../invoices/invoices-home';
import { HOME_PANELS } from './home-manifest';
import { visibleEntries } from './nav-manifest';

describe('home manifest', () => {
  it('collects each module’s panels, the invoices first', () => {
    expect(HOME_PANELS.map((panel) => panel.key)).toEqual(INVOICES_HOME.map((panel) => panel.key));
    expect(HOME_PANELS[0]).toMatchObject({
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
  });

  it('loads the invoices panel’s component only when it is shown', async () => {
    await expect(INVOICES_HOME[0]?.load()).resolves.toBe(InvoicesHome);
  });
});

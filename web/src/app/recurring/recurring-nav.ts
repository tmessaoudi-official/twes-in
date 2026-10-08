// SPDX-License-Identifier: AGPL-3.0-or-later

import type { PageTab } from '../shared/ui/page-tabs';

/** The key the API's module registry knows the recurring invoices by. */
export const RECURRING_MODULE = 'recurring';

/**
 * The invoices and the recurring invoices as two tabs of Factures, one sidebar entry between them: a recurring invoice
 * is a model that makes invoices, so it sits beside them (docs/SPEC.md § 7, 2026-09-21 15:20).
 */
export const RECURRING_TABS: readonly PageTab[] = [
  { labelKey: 'nav.invoices', route: '/invoices', testId: 'invoices-tab' },
  { labelKey: 'recurring.tab', route: '/invoices/recurring', testId: 'recurring-tab' },
];

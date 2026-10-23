// SPDX-License-Identifier: AGPL-3.0-or-later

import type { RecordAction } from '../shared/actions/record-action';
import type { PlatformCompanyRow } from './platform-types';

/** What the operator's page does when one of a company's actions runs. */
export interface CompanyActionRunner {
  approve(company: PlatformCompanyRow): void;
  suspend(company: PlatformCompanyRow): void;
  busy(): boolean;
}

const ACTIONS = 'platform.companies.actions';

/**
 * What an operator does to a company, declared once and read by every place a company appears: its row in the list,
 * its card among those waiting, and its sheet, whose bar the keys, Ctrl K and the « ? » sheet read too. The API opens a
 * company that waits or was suspended, and suspends one that waits or is open (the operator's suspend stays available at
 * any time, docs/SPEC.md § 7, 2026-09-17), so each action is offered wherever its transition exists.
 *
 * Both are corrected rather than final: the other one takes each back. Opening a company also mails its owners, which
 * its question says before it runs.
 */
export function companyActions(runner: CompanyActionRunner): RecordAction<PlatformCompanyRow>[] {
  return [
    {
      id: 'approve',
      label: (company) => `${ACTIONS}.${company.status === 'suspended' ? 'restore' : 'approve'}`,
      icon: 'check_circle',
      group: 'state',
      frequency: 'next',
      effect: {
        kind: 'corrigeable',
        preview: (company) => {
          const verb = company.status === 'suspended' ? 'restore' : 'approve';
          return {
            title: `${ACTIONS}.${verb}_title`,
            message: `${ACTIONS}.${verb}_message`,
            messageParams: { name: company.name },
            confirmLabel: `${ACTIONS}.${verb}`,
            keepLabel: `${ACTIONS}.keep`,
          };
        },
      },
      shown: (company) => company.status === 'pending' || company.status === 'suspended',
      disabled: () => runner.busy(),
      run: (company) => runner.approve(company),
    },
    {
      id: 'suspend',
      label: (company) => `${ACTIONS}.${company.status === 'pending' ? 'reject' : 'suspend'}`,
      icon: 'block',
      group: 'state',
      // Refusing is half of the decision on a company that waits; suspending an open one is rare.
      frequency: (company) => (company.status === 'pending' ? 'frequent' : 'rare'),
      effect: {
        kind: 'corrigeable',
        preview: (company) => {
          const verb = company.status === 'pending' ? 'reject' : 'suspend';
          return {
            title: `${ACTIONS}.${verb}_title`,
            message: `${ACTIONS}.${verb}_message`,
            messageParams: { name: company.name },
            confirmLabel: `${ACTIONS}.${verb}`,
            keepLabel: `${ACTIONS}.keep`,
          };
        },
      },
      shown: (company) => company.status === 'pending' || company.status === 'active',
      disabled: () => runner.busy(),
      run: (company) => runner.suspend(company),
    },
  ];
}

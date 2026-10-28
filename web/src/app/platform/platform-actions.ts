// SPDX-License-Identifier: AGPL-3.0-or-later

import type { RecordAction } from '../shared/actions/record-action';
import type { ActionConfirm } from '../shared/actions/screen-action';
import type { PlatformCompanyRow } from './platform-types';

/** What the operator's page does when one of a company's actions runs. */
export interface CompanyActionRunner {
  approve(company: PlatformCompanyRow): void;
  suspend(company: PlatformCompanyRow): void;
  busy(): boolean;
}

const ACTIONS = 'platform.companies.actions';

/** The most a refusal's or a suspension's reason holds, as the API holds it. */
export const REASON_MAX_LENGTH = 500;

/**
 * Refusing a waiting company or suspending an open one, asked with the reason its owners' mail and their sign-in page
 * will give, which the operator may leave out (docs/SPEC.md § 7, 2026-10-27 21:37).
 */
export function suspendQuestion(company: PlatformCompanyRow): ActionConfirm {
  const verb = company.status === 'pending' ? 'reject' : 'suspend';
  return {
    kind: 'corrigeable',
    title: `${ACTIONS}.${verb}_title`,
    message: `${ACTIONS}.${verb}_message`,
    messageParams: { name: company.name },
    confirmLabel: `${ACTIONS}.${verb}`,
    keepLabel: `${ACTIONS}.keep`,
    note: {
      label: `${ACTIONS}.reason`,
      hint: `${ACTIONS}.reason_hint`,
      maxLength: REASON_MAX_LENGTH,
    },
  };
}

/**
 * What an operator does to a company, declared once and read by every place a company appears: its row in the list,
 * its card among those waiting, and its sheet, whose bar the keys, Ctrl K and the « ? » sheet read too. The API opens a
 * company that waits or was suspended, and suspends one that waits or is open (the operator's suspend stays available at
 * any time, docs/SPEC.md § 7, 2026-09-17), so each action is offered wherever its transition exists.
 *
 * Both are corrected rather than final: the other one takes each back. Opening a company also mails its owners, which
 * its question says before it runs.
 */
/** Opening a suspended company gives back an access it had; a refused one, never opened, is approved instead. */
function restores(company: PlatformCompanyRow): boolean {
  return company.status === 'suspended' && company.opened;
}

export function companyActions(runner: CompanyActionRunner): RecordAction<PlatformCompanyRow>[] {
  return [
    {
      id: 'approve',
      label: (company) => `${ACTIONS}.${restores(company) ? 'restore' : 'approve'}`,
      icon: 'check_circle',
      group: 'state',
      frequency: 'next',
      effect: {
        kind: 'corrigeable',
        preview: (company) => {
          const verb = restores(company) ? 'restore' : 'approve';
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
      // Its question asks the reason the owners' mail gives, so the runner asks it itself (`suspendQuestion`), as
      // « Encaisser » opens its own dialog.
      effect: { kind: 'plain' },
      shown: (company) => company.status === 'pending' || company.status === 'active',
      disabled: () => runner.busy(),
      run: (company) => runner.suspend(company),
    },
  ];
}

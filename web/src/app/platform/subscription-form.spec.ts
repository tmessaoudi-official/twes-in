// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { buildFormGroup } from '../shared/form/form-builder';
import { subscriptionForm, subscriptionTerms, subscriptionValues } from './subscription-form';
import type { PlatformSubscriptionRow } from './platform-types';

const held: PlatformSubscriptionRow = {
  companyId: 'c1',
  periodCount: 3,
  periodUnit: 'month',
  trialEndsOn: null,
  paidThrough: '2026-12-31',
  price: '600.000',
  currency: 'TND',
  graceDays: 5,
  unpaidMode: 'read_only',
  holdDays: null,
  stage: 'paid',
  access: 'full',
  coveredUntil: '2026-12-31T23:59:59+01:00',
  graceEndsAt: '2027-01-05T23:59:59+01:00',
  daysLeft: 75,
  updatedAt: '2026-10-17T10:00:00+00:00',
  version: 2,
};

describe('the subscription form', () => {
  it('reads the terms a company holds back as the terms it sends', () => {
    const group = buildFormGroup(subscriptionForm(), subscriptionValues(held));

    expect(group.valid).toBe(true);
    expect(subscriptionTerms(group.getRawValue())).toEqual({
      periodCount: 3,
      periodUnit: 'month',
      trialEndsOn: null,
      paidThrough: '2026-12-31',
      price: '600.000',
      currency: 'TND',
      graceDays: 5,
      unpaidMode: 'read_only',
      holdDays: null,
    });
  });

  it('starts a company licensing does not manage on a month, every term following the platform', () => {
    const group = buildFormGroup(subscriptionForm(), subscriptionValues(null));

    expect(subscriptionTerms(group.getRawValue())).toEqual({
      periodCount: 1,
      periodUnit: 'month',
      trialEndsOn: null,
      paidThrough: null,
      price: null,
      currency: null,
      graceDays: null,
      unpaidMode: null,
      holdDays: null,
    });
  });

  it('refuses what the API would: a period under one, days past a year, a currency that is not a code', () => {
    const group = buildFormGroup(subscriptionForm(), subscriptionValues(held));

    for (const [field, value] of [
      ['periodCount', 0],
      ['periodCount', 1201],
      ['graceDays', -1],
      ['graceDays', 366],
      ['holdDays', 366],
      ['currency', 'tnd'],
    ] as const) {
      group.controls[field]!.setValue(value);
      expect(group.controls[field]!.valid, `${field} = ${value}`).toBe(false);
      group.controls[field]!.setValue(subscriptionValues(held)[field] ?? null);
    }
    expect(group.valid).toBe(true);
  });
});

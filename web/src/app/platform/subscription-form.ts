// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue, FormDescriptor, FormValues } from '../shared/form/form-types';
import type { PlatformSubscriptionRow, SubscriptionTerms } from './platform-types';

const TEXTS = 'platform.subscription';
const UNITS: readonly SubscriptionTerms['periodUnit'][] = ['day', 'month', 'year'];
const MODES: readonly NonNullable<SubscriptionTerms['unpaidMode']>[] = ['read_only', 'locked'];
/** The API's own limits (`BillingPeriod::MAX_COUNT`, `SubscriptionTerms::MAX_GRACE_DAYS`), said next to the field. */
const MAX_PERIODS = 1200;
const MAX_DAYS = 365;

/**
 * The terms an operator agrees with a company: how long a period is and what it costs, when the trial ends and what is
 * paid, and what happens unpaid. An emptied term follows the platform's own.
 */
export function subscriptionForm(): FormDescriptor {
  return {
    id: 'platform-subscription',
    sections: [
      {
        id: 'terms',
        title: `${TEXTS}.open`,
        fields: [
          { id: 'trialEndsOn', label: `${TEXTS}.trial_ends_on`, kind: 'date' },
          { id: 'paidThrough', label: `${TEXTS}.paid_through`, kind: 'date' },
          {
            id: 'periodCount',
            label: `${TEXTS}.period_count`,
            kind: 'number',
            required: true,
            min: 1,
            max: MAX_PERIODS,
          },
          {
            id: 'periodUnit',
            label: `${TEXTS}.period_unit`,
            kind: 'select',
            required: true,
            options: UNITS.map((unit) => ({ value: unit, label: `${TEXTS}.units.${unit}` })),
          },
          { id: 'price', label: `${TEXTS}.price`, kind: 'decimal' },
          {
            id: 'currency',
            label: `${TEXTS}.currency`,
            kind: 'text',
            pattern: '[A-Z]{3}',
            maxLength: 3,
          },
          { id: 'graceDays', label: `${TEXTS}.grace_days`, kind: 'number', min: 0, max: MAX_DAYS },
          { id: 'holdDays', label: `${TEXTS}.hold_days`, kind: 'number', min: 0, max: MAX_DAYS },
          {
            id: 'unpaidMode',
            label: `${TEXTS}.unpaid_mode`,
            kind: 'select',
            options: [
              { value: '', label: `${TEXTS}.modes.default` },
              ...MODES.map((mode) => ({ value: mode, label: `${TEXTS}.modes.${mode}` })),
            ],
          },
        ],
      },
    ],
  };
}

/**
 * What the form starts from: the terms the company holds, or a month with every term following the platform where
 * licensing does not manage it. A save over a version saved since is merged against it.
 */
export function subscriptionValues(held: PlatformSubscriptionRow | null): FormValues {
  return {
    trialEndsOn: held?.trialEndsOn ?? '',
    paidThrough: held?.paidThrough ?? '',
    periodCount: held?.periodCount ?? 1,
    periodUnit: held?.periodUnit ?? 'month',
    price: held?.price ?? '',
    currency: held?.currency ?? '',
    graceDays: held?.graceDays ?? null,
    holdDays: held?.holdDays ?? null,
    unpaidMode: held?.unpaidMode ?? '',
  };
}

/** What a valid form holds as the terms it sends: an emptied term is null, which is « follow the platform ». */
export function subscriptionTerms(values: FormValues): SubscriptionTerms {
  const unpaidMode = text(values['unpaidMode']);
  return {
    periodCount: whole(values['periodCount']) ?? invalid('periodCount'),
    periodUnit: UNITS.find((unit) => unit === values['periodUnit']) ?? invalid('periodUnit'),
    trialEndsOn: text(values['trialEndsOn']),
    paidThrough: text(values['paidThrough']),
    price: text(values['price']),
    currency: text(values['currency']),
    graceDays: whole(values['graceDays']),
    unpaidMode:
      unpaidMode === null
        ? null
        : (MODES.find((mode) => mode === unpaidMode) ?? invalid('unpaidMode')),
    holdDays: whole(values['holdDays']),
  };
}

function text(value: FieldValue | undefined): string | null {
  const trimmed = typeof value === 'string' ? value.trim() : '';
  return trimmed === '' ? null : trimmed;
}

function whole(value: FieldValue | undefined): number | null {
  return typeof value === 'number' ? value : null;
}

/** The form validates before it submits, so a value outside its options is a defect here, not a person's mistake. */
function invalid(field: string): never {
  throw new Error(`The subscription form submitted an invalid ${field}.`);
}

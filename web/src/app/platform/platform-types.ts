// SPDX-License-Identifier: AGPL-3.0-or-later

/** Where a company stands in its subscription, as the platform's company list shows it. */
export interface SubscriptionStanding {
  readonly stage: 'trial' | 'paid' | 'grace' | 'unpaid';
  readonly access: 'full' | 'read_only' | 'locked';
  readonly coveredUntil: string;
  readonly daysLeft: number | null;
}

/** What an operator agreed with a company: dates are days in the company's timezone, prices decimal strings. */
export interface SubscriptionTerms {
  readonly periodCount: number;
  readonly periodUnit: 'day' | 'month' | 'year';
  readonly trialEndsOn: string | null;
  readonly paidThrough: string | null;
  readonly price: string | null;
  readonly currency: string | null;
  /** Null follows the platform's own. */
  readonly graceDays: number | null;
  readonly unpaidMode: 'read_only' | 'locked' | null;
}

/** A company's subscription as the platform reads it back: its terms and where they leave it now. */
export interface PlatformSubscriptionRow extends SubscriptionTerms {
  readonly companyId: string;
  readonly stage: SubscriptionStanding['stage'];
  readonly access: SubscriptionStanding['access'];
  readonly coveredUntil: string;
  readonly graceEndsAt: string;
  readonly daysLeft: number | null;
  readonly updatedAt: string;
}

/** A company as the platform's operators review it. */
export interface PlatformCompanyRow {
  readonly id: string;
  readonly name: string;
  readonly countryCode: string;
  readonly status: string;
  readonly createdAt: string;
  /** The owners' addresses. */
  readonly owners: readonly string[];
  /** Null when licensing does not manage the company. */
  readonly subscription: SubscriptionStanding | null;
}

/** The platform's two signup switches, as its operators set them. */
export interface PlatformSignup {
  readonly enabled: boolean;
  readonly approvalRequired: boolean;
}

export type SignupSwitch = 'signup.enabled' | 'signup.approval_required';

/** An account as the platform's operators look it up. */
export interface PlatformAccountRow {
  readonly id: string;
  readonly email: string;
  readonly displayName: string;
  /** Whether it may sign in. */
  readonly active: boolean;
  readonly platformOperator: boolean;
  readonly createdAt: string;
}

/** What an operator does about an account: every action ends its sessions except reactivating it. */
export type AccountAction = 'end-sessions' | 'deactivate' | 'reactivate';

/** What a company is opened with; the currency, language and time zone follow its country. */
export interface NewCompany {
  readonly name: string;
  readonly countryCode: string;
  readonly currency: string;
  readonly locale: string;
  readonly timezone: string;
}

/** The countries a company may be opened in: those with a fiscal preset, each with what a company there starts with. */
export const COMPANY_COUNTRIES = {
  TN: { currency: 'TND', locale: 'fr', timezone: 'Africa/Tunis' },
  FR: { currency: 'EUR', locale: 'fr', timezone: 'Europe/Paris' },
} as const;

export type CompanyCountry = keyof typeof COMPANY_COUNTRIES;

/**
 * Gone (the company or account no longer exists), an operator deactivating their own account, a company name
 * already taken, an address that already belongs to the company, refused by the API, or the API could not be
 * reached.
 */
export type PlatformError =
  'not_found' | 'own_account' | 'name_taken' | 'already_member' | 'refused' | 'network';

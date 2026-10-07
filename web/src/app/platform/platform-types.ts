// SPDX-License-Identifier: AGPL-3.0-or-later

/** Where a company stands in its subscription, as the platform's company list shows it. */
export interface SubscriptionStanding {
  readonly stage: 'trial' | 'paid' | 'grace' | 'held' | 'unpaid';
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
  /** How long a declared payment holds the company open while it waits; null follows the platform's own. */
  readonly holdDays: number | null;
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
  /** The companies the account belongs to, with its role in each; empty where the platform did not read them. */
  readonly companies: readonly PlatformAccountCompany[];
}

/** One company an account belongs to. */
export interface PlatformAccountCompany {
  readonly id: string;
  readonly name: string;
  /** `owner`, `member`, or a role of the company's own. */
  readonly role: string;
}

export type SortDirection = 'asc' | 'desc';

/** What the API is asked for one page of the companies list. */
export interface PlatformCompanySearch {
  readonly page: number;
  readonly itemsPerPage: number;
  readonly q: string;
  /** Any of these, each filter's values OR'd and the two AND'd (row 197). */
  readonly statuses: readonly CompanyStatus[];
  readonly countryCodes: readonly string[];
  readonly order: {
    readonly key: 'name' | 'countryCode' | 'status' | 'createdAt';
    readonly direction: SortDirection;
  } | null;
}

export const COMPANY_STATUSES = ['pending', 'active', 'suspended'] as const;
export type CompanyStatus = (typeof COMPANY_STATUSES)[number];

/** What the API is asked for one page of the accounts list. */
export interface PlatformAccountSearch {
  readonly page: number;
  readonly itemsPerPage: number;
  readonly q: string;
  readonly active: boolean | null;
  readonly platformOperator: boolean | null;
  readonly order: {
    readonly key: 'email' | 'displayName' | 'createdAt' | 'active' | 'platformOperator';
    readonly direction: SortDirection;
  } | null;
}

/** The tabs of the platform page, in the order they are shown. */
export const PLATFORM_TABS = ['overview', 'companies', 'accounts', 'payments', 'demand'] as const;
export type PlatformTab = (typeof PLATFORM_TABS)[number];

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

/** A planned module and how many companies asked to be told when it arrives (« Me prévenir », row 150). */
/** What the worker gave up on after its retries, which waits in the failed transport for the operator to retry it. */
export interface FailedMessagesRead {
  readonly total: number;
  /** By the message's class name, the most first. */
  readonly kinds: readonly { readonly kind: string; readonly count: number }[];
}

export interface ModuleDemandRow {
  readonly key: string;
  /** The translation key of the module's name. */
  readonly labelKey: string;
  readonly planned: 'v1' | 'later';
  readonly companies: number;
}

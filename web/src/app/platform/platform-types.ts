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
  /** What terms set over it send back; one saved since refuses them as `record_changed`. */
  readonly version: number;
}

/** An owner invitation sent to a company, as its row says it. */
export interface PlatformOwnerInvitation {
  readonly email: string;
  readonly expiresAt: string;
  readonly expired: boolean;
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
  /** The owner invitations nobody used: open, or run out lately, so a lapsed one is sent again. */
  readonly ownerInvitations: readonly PlatformOwnerInvitation[];
  /** Null when licensing does not manage the company. */
  readonly subscription: SubscriptionStanding | null;
}

/** The platform's two signup switches, as its operators set them. */
export interface PlatformSignup {
  readonly enabled: boolean;
  readonly approvalRequired: boolean;
}

/** A switch the operators turn, the last word being theirs: it is set without a version. */
export type PlatformSwitch =
  'signup.enabled' | 'signup.approval_required' | 'demo.enabled' | 'presentation.show-coming';

/**
 * The demo as the operators set it: whether visitors may try it (on unless switched off), and how many sandboxes may
 * exist at once, which bounds what visitors cost the machine; null where the API declares no such count.
 */
export interface PlatformDemo {
  readonly enabled: boolean;
  readonly most: PlatformNumber | null;
}

/**
 * How many rows one imported file may hold, which the operators set within what one request was measured to bear;
 * null where the API declares no such count.
 */
export interface PlatformImports {
  readonly rows: PlatformNumber | null;
}

/** A way companies reach the platform's operators, which the help panel offers (`SupportSettings` in the API). */
export type SupportChannel = 'phone' | 'whatsapp' | 'email' | 'status_url';

/** Each support channel as the operators set it, empty where they did not. */
export type PlatformSupport = Readonly<Record<SupportChannel, string>>;

/** What the API checks a support channel against, sent with it so the screen says a wrong value before saving. */
export interface PlatformSupportRule {
  readonly pattern: string | null;
  readonly patternReason: string | null;
  readonly maxLength: number | null;
}

export type PlatformSupportRules = Readonly<Record<SupportChannel, PlatformSupportRule>>;

/** The platform's own settings as its overview reads them, with each key's row version, 0 where none is stored. */
export interface PlatformSettings {
  readonly signup: PlatformSignup;
  /** Whether the installation shows what is coming, marked « Bientôt », until a company or a person chooses. */
  readonly showComing: boolean;
  readonly support: PlatformSupport;
  readonly supportRules: PlatformSupportRules;
  readonly delays: readonly PlatformNumber[];
  readonly demo: PlatformDemo;
  readonly imports: PlatformImports;
  readonly versions: Readonly<Record<string, number>>;
}

/**
 * A whole number the platform sets, drawn from its declaration (label, bounds), so a new one has its field without a
 * line written here: every such setting but the demo's count and the import's rows is a number of days, shown as the delays. `value` is what
 * is in effect, the default where nothing was set.
 */
export interface PlatformNumber {
  readonly key: string;
  readonly labelKey: string;
  readonly value: number;
  readonly min: number | null;
  readonly max: number | null;
}

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

/**
 * What a company is opened with; the currency and time zone follow its country. Its language is left to the API,
 * which takes the first one its country's fiscal preset prints in.
 */
export interface NewCompany {
  readonly name: string;
  readonly countryCode: string;
  readonly currency: string;
  readonly timezone: string;
}

/** The countries a company may be opened in: those with a fiscal preset, each with what a company there starts with. */
export const COMPANY_COUNTRIES = {
  TN: { currency: 'TND', timezone: 'Africa/Tunis' },
  FR: { currency: 'EUR', timezone: 'Europe/Paris' },
} as const;

export type CompanyCountry = keyof typeof COMPANY_COUNTRIES;

/**
 * Gone (the company or account no longer exists), an operator deactivating their own account, a company name
 * already taken, an address that already belongs to the company, refused by the API, or the API could not be
 * reached.
 */
export type PlatformError =
  | 'not_found'
  | 'own_account'
  | 'name_taken'
  | 'already_member'
  | 'step_up_required'
  | 'record_changed'
  /** Refused on fields the answer names, each said under its own field. */
  | 'invalid'
  | 'refused'
  | 'network';

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

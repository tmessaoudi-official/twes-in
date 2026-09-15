// SPDX-License-Identifier: AGPL-3.0-or-later

/** A company as the platform's operators review it. */
export interface PlatformCompanyRow {
  readonly id: string;
  readonly name: string;
  readonly countryCode: string;
  readonly status: string;
  readonly createdAt: string;
  /** The owners' addresses. */
  readonly owners: readonly string[];
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

/**
 * Gone (the company or account no longer exists), an operator deactivating their own account, refused by the API,
 * or the API could not be reached.
 */
export type PlatformError = 'not_found' | 'own_account' | 'refused' | 'network';

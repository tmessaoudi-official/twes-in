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

/** Gone (the company no longer exists), refused by the API, or the API could not be reached. */
export type PlatformError = 'not_found' | 'refused' | 'network';

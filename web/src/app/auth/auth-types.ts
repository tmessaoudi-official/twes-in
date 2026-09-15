// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The auth feature seen from its components: who is signed in, where they work, what they may do. Built by the
 * API adapter (auth-api.ts) from the generated OpenAPI types, which nothing else imports.
 */
export type AuthStatus = 'unknown' | 'anonymous' | 'authenticated';

export type LoginError =
  | 'invalid_credentials'
  | 'account_locked'
  | 'account_disabled'
  | 'too_many_attempts'
  | 'authentication_required'
  | 'csrf_token_missing'
  | 'csrf_token_invalid'
  // The second factor's own refusals: no password step to finish, a wrong code, an account that must
  // enrol before it may do anything, or an enrolment started over an authenticator already in force (G1c).
  | 'mfa_not_pending'
  | 'invalid_code'
  | 'mfa_enrolment_required'
  | 'mfa_already_enrolled'
  | 'network';

export interface Credentials {
  email: string;
  password: string;
}

export interface SignedInUser {
  id: string;
  email: string;
  displayName: string;
  locale: string;
  isPlatformOperator: boolean;
}

export interface WorkingCompany {
  id: string;
  name: string;
  countryCode: string;
  currency: string;
  locale: string;
  timezone: string;
  status: string;
  /** the user role name in this company */
  role: string;
}

export interface SignedInState {
  user: SignedInUser;
  company: WorkingCompany | null;
  /** permission strings held in the working company; ["*"] for an owner */
  permissions: string[];
  /** keys of the modules the working company has on */
  modules: string[];
}

export type LoginOutcome = { ok: true; state: SignedInState } | { ok: false; error: LoginError };

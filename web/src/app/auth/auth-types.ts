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
  // Passkeys (G1c): one that does not verify, the last factor a company requires, and one already gone.
  | 'invalid_passkey'
  | 'mfa_last_factor'
  | 'passkey_not_found'
  // The browser produced no passkey: cancelled, timed out, or no authenticator to answer. Never sent by the API.
  | 'passkey_cancelled'
  // The browser refused because this device already holds a passkey for the account. Never sent by the API.
  | 'passkey_already_registered'
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

/** What the company's subscription lets its members do, whatever their role allows (docs/SPEC.md § 7, 2026-09-17). */
export type CompanyAccess = 'full' | 'read_only' | 'locked';

/** What stays open whatever the subscription says: the way out of an unpaid company. */
export const ALWAYS_PERMITTED: readonly string[] = ['subscription.read', 'subscription.pay'];

/** Where the working company stands in its subscription, so the application can warn before anything closes. */
export interface CompanySubscription {
  readonly stage: 'trial' | 'paid' | 'grace' | 'held' | 'unpaid';
  readonly coveredUntil: string;
  readonly graceEndsAt: string;
  /** Whole days before the stage changes; null once unpaid. */
  readonly daysLeft: number | null;
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
  access: CompanyAccess;
  /** Null when licensing does not manage the company. */
  subscription: CompanySubscription | null;
}

/** Whether the account has a second factor in force, and whether a company it belongs to requires one. */
export interface MfaStatus {
  enrolled: boolean;
  required: boolean;
  /** an authenticator app is in force */
  totp: boolean;
  /** how many passkeys the account has */
  passkeys: number;
}

export interface SignedInState {
  user: SignedInUser;
  company: WorkingCompany | null;
  /** permission strings held in the working company; ["*"] for an owner */
  permissions: string[];
  /** keys of the modules the working company has on */
  modules: string[];
  mfa: MfaStatus;
  /** the modules not built yet, from the API's catalogue: the menus show them « Bientôt » (docs/SPEC.md § 7, 2026-09-26) */
  plannedModules?: readonly PlannedModule[];
}

/** A module of the complete product not built yet, and the version it is expected in. */
export interface PlannedModule {
  readonly key: string;
  readonly planned: 'v1' | 'later';
}

/** A pending authenticator: the secret to type in by hand, and the otpauth:// URI its QR code carries. */
export interface TotpEnrolment {
  secret: string;
  provisioningUri: string;
}

/** A login signs in, still owes a second factor (no session exists yet), or is refused. */
export type LoginOutcome =
  | { status: 'signed_in'; state: SignedInState }
  | { status: 'second_factor' }
  | { status: 'refused'; error: LoginError };

export type EnrolmentOutcome =
  { ok: true; enrolment: TotpEnrolment } | { ok: false; error: LoginError };

export type ConfirmationOutcome =
  { ok: true; recoveryCodes: string[] } | { ok: false; error: LoginError };

/** The options of a passkey ceremony as the API sends them: WebAuthn's own JSON, which the browser parses. */
export interface PasskeyOptions {
  challenge: string;
  [key: string]: unknown;
}

/** What the browser's `PublicKeyCredential.toJSON()` produced, posted back to the API unread. */
export type PasskeyCredential = Record<string, unknown>;

export interface PasskeySummary {
  id: string;
  name: string;
  createdAt: string;
  lastUsedAt: string | null;
}

export type PasskeysOutcome =
  { ok: true; passkeys: PasskeySummary[] } | { ok: false; error: LoginError };

/** A registered passkey, with the recovery codes it issued when it was the account's first factor. */
export type PasskeyAdded =
  { ok: true; passkey: PasskeySummary; recoveryCodes: string[] } | { ok: false; error: LoginError };

export type PasskeyRemoved = { ok: true } | { ok: false; error: LoginError };

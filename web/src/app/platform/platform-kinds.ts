// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * What /platform shows of a company, which the API says to operators on the platform's channel by kind alone
 * (`app.realtime.platform_kinds` in api/config/services.yaml, which `PlatformKindsTest` holds this list to): a support
 * grant, a company signed up to wait for approval, its standing, an owner invited or joined, its subscription and
 * the payments it declares, and an account's lock or whether it may sign in.
 */
export const PLATFORM_KINDS: readonly string[] = [
  'company',
  'signup',
  'invitation',
  'membership',
  'support_grant',
  'subscription',
  'payment_declaration',
  'account',
];

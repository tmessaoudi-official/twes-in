// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The kinds of change that move what the session says (`MeFactory::CHANGED_BY` in the API, which `SessionKindsTest`
 * holds this list to): a company's status, closing, logo or required second factor, a membership or role, a module
 * switched, a subscription's standing and the payments declared towards it, a support grant. The shell reads the session
 * again on each, so a decision taken by another person shows at once.
 */
export const SESSION_KINDS: readonly string[] = [
  'company',
  'membership',
  'module',
  'payment_declaration',
  'role',
  'subscription',
  'support_grant',
];

/**
 * What a list the API filters by the reader's role and modules (« À surveiller », the reports, the first steps) reads
 * again on: the kinds of its own subjects, and every change of the session, since a member raised or lowered, or a
 * module switched, adds or takes away whole subjects.
 */
export function withSessionKinds(kinds: readonly string[]): readonly string[] {
  return [...new Set([...kinds, ...SESSION_KINDS])];
}

// SPDX-License-Identifier: AGPL-3.0-or-later

/** The roles every company shares, which the release translates. */
const BUILT_IN = new Set(['owner', 'admin', 'member', 'clerk', 'accountant']);

/**
 * What a membership's role reads as on the platform's accounts list: a built-in role's translation key, or the name a
 * company gave its own, which ngx-translate hands back as it is.
 */
export const membershipRoleLabel = (role: string): string =>
  BUILT_IN.has(role) ? `roles.${role}` : role;

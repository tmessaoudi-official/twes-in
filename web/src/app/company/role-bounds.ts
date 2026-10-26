// SPDX-License-Identifier: AGPL-3.0-or-later

import type { RoleRow } from './roles-types';

/**
 * Who grants and removes which role, as the API's `RoleBounds` decides it: owner above admin above the other built-in
 * roles. The screen asks the same question first so it offers only what the API would accept (hunt N-1): an admin
 * was offered every action on an owner's row, proved who they were, and was then refused.
 */
const OWNER_RANK = 3;
const ADMIN_RANK = 2;

function rank(roleName: string): number {
  switch (roleName) {
    case 'owner':
      return OWNER_RANK;
    case 'admin':
      return ADMIN_RANK;
    case 'member':
    case 'clerk':
    case 'accountant':
      return 1;
    default:
      return 0;
  }
}

/** The person acting: the role they hold in the company and that role's own permissions, unnarrowed by the subscription. */
export interface Actor {
  readonly role: string;
  readonly permissions: readonly string[];
}

/**
 * Whether the actor may give this role. A role the company made for itself has no place in the order, so it is bounded
 * by what it holds: nobody gives a role carrying a permission their own does not grant.
 */
export function mayGrant(actor: Actor, role: RoleRow): boolean {
  const actorRank = rank(actor.role);
  if (actorRank === OWNER_RANK) return true;
  if (role.builtIn) return actorRank === ADMIN_RANK && rank(role.name) <= ADMIN_RANK;
  return role.permissions.every(
    (permission) => actor.permissions.includes('*') || actor.permissions.includes(permission),
  );
}

/** Whether the actor may take this role from someone: removing them, or changing it for another. */
export function mayRemove(actor: Actor, roleName: string): boolean {
  const actorRank = rank(actor.role);
  return actorRank === OWNER_RANK || (actorRank === ADMIN_RANK && rank(roleName) < ADMIN_RANK);
}

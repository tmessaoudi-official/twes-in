// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Who takes which role from whom, as the API's `RoleBounds` decides it: owner above admin above the other built-in
 * roles. The screen asks the same question first so it offers only what the API would accept (hunt N-1): an admin
 * was offered every action on an owner's row, proved who they were, and was then refused. Who may GIVE a role the API
 * answers itself, each role's `grantable`, since a company's choice bounds it too.
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

/** The person acting: the role they hold in the company. */
export interface Actor {
  readonly role: string;
}

/** Whether the actor may take this role from someone: removing them, or changing it for another. */
export function mayRemove(actor: Actor, roleName: string): boolean {
  const actorRank = rank(actor.role);
  return actorRank === OWNER_RANK || (actorRank === ADMIN_RANK && rank(roleName) < ADMIN_RANK);
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { membershipRoleLabel } from './membership-role';

describe('membershipRoleLabel', () => {
  it('translates the four built-in roles, the clerk included, and leaves a company’s own role as it named it', () => {
    expect(['owner', 'admin', 'member', 'clerk', 'Barista'].map(membershipRoleLabel)).toEqual([
      'roles.owner',
      'roles.admin',
      'roles.member',
      'roles.clerk',
      'Barista',
    ]);
  });
});

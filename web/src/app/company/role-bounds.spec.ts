// SPDX-License-Identifier: AGPL-3.0-or-later

import { mayGrant, mayRemove } from './role-bounds';
import type { RoleRow } from './roles-types';

const role = (name: string, builtIn: boolean, permissions: readonly string[] = []): RoleRow => ({
  id: `r-${name}`,
  name,
  builtIn,
  wildcard: false,
  permissions,
  memberCount: 0,
  version: 1,
});

const owner = { role: 'owner', permissions: ['*'] };
const admin = { role: 'admin', permissions: ['user.read', 'user.write', 'customer.read'] };
const keeper = { role: 'keeper', permissions: ['user.write', 'customer.read'] };

describe('role bounds, as the API decides them', () => {
  it('lets an owner give and take every role', () => {
    for (const name of ['owner', 'admin', 'member', 'clerk', 'accountant']) {
      expect(mayGrant(owner, role(name, true)), name).toBe(true);
      expect(mayRemove(owner, name), name).toBe(true);
    }
    expect(mayGrant(owner, role('manager', false, ['company.settings']))).toBe(true);
  });

  it('lets an admin give up to admin and take only below it', () => {
    expect(mayGrant(admin, role('owner', true))).toBe(false);
    expect(mayGrant(admin, role('admin', true))).toBe(true);
    expect(mayGrant(admin, role('clerk', true))).toBe(true);
    expect(mayRemove(admin, 'owner')).toBe(false);
    expect(mayRemove(admin, 'admin')).toBe(false);
    expect(mayRemove(admin, 'accountant')).toBe(true);
    // A company's own role sits below the order: an admin takes it back.
    expect(mayRemove(admin, 'barista')).toBe(true);
  });

  it('gives a company’s own role only to whoever holds everything it carries', () => {
    expect(mayGrant(admin, role('desk', false, ['customer.read']))).toBe(true);
    expect(mayGrant(admin, role('manager', false, ['company.settings']))).toBe(false);
  });

  it('lets a company’s own role give only its like, and take nothing', () => {
    expect(mayGrant(keeper, role('member', true))).toBe(false);
    expect(mayGrant(keeper, role('desk', false, ['customer.read']))).toBe(true);
    expect(mayRemove(keeper, 'member')).toBe(false);
    expect(mayRemove(keeper, 'desk')).toBe(false);
  });
});

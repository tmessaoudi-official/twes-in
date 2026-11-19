// SPDX-License-Identifier: AGPL-3.0-or-later

import { mayRemove } from './role-bounds';

const owner = { role: 'owner' };
const admin = { role: 'admin' };
const keeper = { role: 'keeper' };

describe('role bounds, as the API decides them', () => {
  it('lets an owner take every role', () => {
    for (const name of ['owner', 'admin', 'member', 'clerk', 'accountant']) {
      expect(mayRemove(owner, name), name).toBe(true);
    }
  });

  it('lets an admin take only below admin', () => {
    expect(mayRemove(admin, 'owner')).toBe(false);
    expect(mayRemove(admin, 'admin')).toBe(false);
    expect(mayRemove(admin, 'accountant')).toBe(true);
    // A company's own role sits below the order: an admin takes it back.
    expect(mayRemove(admin, 'barista')).toBe(true);
  });

  it('lets a company’s own role take nothing', () => {
    expect(mayRemove(keeper, 'member')).toBe(false);
    expect(mayRemove(keeper, 'desk')).toBe(false);
  });
});

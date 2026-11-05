// SPDX-License-Identifier: AGPL-3.0-or-later

import { RoleKeyPipe, roleKey } from './role-key';

describe('roleKey', () => {
  it.each(['owner', 'admin', 'member', 'clerk', 'accountant', 'support'])(
    'translates the shipped role %s',
    (name) => {
      expect(roleKey(name)).toBe(`roles.${name}`);
    },
  );

  it.each(['barista', 'Chef de rayon', 'vente.caisse'])(
    'hands a role a company named, %s, through as typed',
    (name) => {
      expect(roleKey(name)).toBe(name);
      expect(new RoleKeyPipe().transform(name)).toBe(name);
    },
  );
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { RolesApi, RolesRefused } from './roles-api';
import { RolesFacade } from './roles-facade';
import type { RoleRow } from './roles-types';

const seller: RoleRow = {
  id: 'r1',
  name: 'Vendeur',
  builtIn: false,
  wildcard: false,
  permissions: ['customer.read'],
  memberCount: 2,
};

describe('RolesFacade', () => {
  const api = {
    list: vi.fn(),
    permissionGroups: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    remove: vi.fn(),
  };
  const auth = { refresh: vi.fn(async () => undefined), load: vi.fn(async () => null) };
  let facade: RolesFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    auth.refresh.mockClear();
    auth.load.mockClear();
    api.list.mockResolvedValue([seller]);
    api.permissionGroups.mockResolvedValue([]);
    TestBed.configureTestingModule({
      providers: [
        { provide: RolesApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
      ],
    });
    facade = TestBed.inject(RolesFacade);
  });

  it('reads the signed-in state again after a role changed, because that can be your own rights', async () => {
    // Nothing else tells the shell: a live change never comes back to the tab that made it, so without this the
    // menu and every permission-gated control stay as they were until the browser is refreshed (2026-09-20).
    api.revise.mockResolvedValue(undefined);

    expect(await facade.revise('c1', 'r1', 'Vendeur', ['customer.read', 'customer.write'])).toBe(
      true,
    );

    expect(auth.refresh).toHaveBeenCalledTimes(1);
    // `refresh`, not `load`: the write succeeded, so an unreachable API must not sign the person out.
    expect(auth.load).not.toHaveBeenCalled();
  });

  it('reads it again after a role is created or removed too, not only revised', async () => {
    api.create.mockResolvedValue(undefined);
    api.remove.mockResolvedValue(undefined);

    await facade.create('c1', 'Caissier', ['till.use']);
    await facade.remove('c1', 'r1');

    expect(auth.refresh).toHaveBeenCalledTimes(2);
  });

  it('does not claim the session changed when the API refused the write', async () => {
    api.revise.mockRejectedValue(new RolesRefused('in_use'));

    expect(await facade.revise('c1', 'r1', 'Vendeur', [])).toBe(false);
    expect(auth.refresh).not.toHaveBeenCalled();
  });
});

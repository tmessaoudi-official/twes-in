// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { StepUp } from '../shared/step-up/step-up';
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
  version: 1,
};

// The proof dialog is answered by the test; `retrying` itself is StepUp's own.
const stepUp = Object.assign(Object.create(StepUp.prototype) as StepUp, {
  request: vi.fn<(intro?: string) => Promise<boolean>>(),
});

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
        { provide: StepUp, useValue: stepUp },
      ],
    });
    facade = TestBed.inject(RolesFacade);
  });

  it('reads the signed-in state again after a role changed, because that can be your own rights', async () => {
    // Nothing else tells the shell: a live change never comes back to the tab that made it, so without this the
    // menu and every permission-gated control stay as they were until the browser is refreshed (2026-09-20).
    api.revise.mockResolvedValue(undefined);

    expect(await facade.revise('c1', 'r1', 'Vendeur', ['customer.read', 'customer.write'], 1)).toBe(
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
    await facade.remove('c1', 'r1', 4);
    expect(api.remove).toHaveBeenCalledWith('c1', 'r1', 4);

    expect(auth.refresh).toHaveBeenCalledTimes(2);
  });

  it('does not claim the session changed when the API refused the write', async () => {
    api.revise.mockRejectedValue(new RolesRefused('in_use'));

    expect(await facade.revise('c1', 'r1', 'Vendeur', [], 1)).toBe(false);
    expect(auth.refresh).not.toHaveBeenCalled();
  });

  // docs/SPEC.md § 7, the step-up list: a role changed waits for the person to prove who they are again.
  it('asks for the proof the API wants, then changes the role', async () => {
    stepUp.request.mockReset().mockResolvedValue(true);
    api.revise
      .mockRejectedValueOnce(new RolesRefused('step_up_required'))
      .mockResolvedValueOnce(undefined);

    expect(await facade.revise('c1', 'r1', 'Vendeur', ['customer.read'], 1)).toBe(true);
    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_sensitive');
    expect(api.revise).toHaveBeenCalledTimes(2);
    expect(api.revise).toHaveBeenLastCalledWith('c1', 'r1', 'Vendeur', ['customer.read'], 1);
  });

  it('says nothing changed when the person does not give the proof', async () => {
    stepUp.request.mockReset().mockResolvedValue(false);
    api.remove.mockRejectedValue(new RolesRefused('step_up_required'));

    expect(await facade.remove('c1', 'r1', 1)).toBe(false);
    expect(facade.error()).toBe('step_up_required');
  });
});

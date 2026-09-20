// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { ModulesApi, ModulesRefused } from './modules-api';
import { ModulesFacade } from './modules-facade';
import type { ModuleRow } from './modules-types';

const customers: ModuleRow = {
  key: 'customers',
  labelKey: 'modules.customers',
  dependencies: [],
  permissions: ['customer.read'],
  enabled: true,
};

describe('ModulesFacade', () => {
  const api = { list: vi.fn(), switch: vi.fn() };
  const auth = { load: vi.fn(), refresh: vi.fn() };
  let facade: ModulesFacade;

  beforeEach(() => {
    api.list.mockReset();
    api.switch.mockReset();
    auth.load.mockReset().mockResolvedValue(null);
    auth.refresh.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      providers: [
        { provide: ModulesApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
    facade = TestBed.inject(ModulesFacade);
  });

  it("reads the company's modules", async () => {
    api.list.mockResolvedValue([customers]);

    await facade.load('c1');

    expect(api.list).toHaveBeenCalledWith('c1');
    expect(facade.modules()).toEqual([customers]);
    expect(facade.error()).toBeNull();
    expect(facade.busy()).toBe(false);
  });

  it('reads the modules and the signed-in state again after a switch, so the navigation follows', async () => {
    api.switch.mockResolvedValue({ ...customers, enabled: false });
    api.list.mockResolvedValue([{ ...customers, enabled: false }]);

    expect(await facade.switch('c1', 'customers', false)).toBe(true);

    expect(api.switch).toHaveBeenCalledWith('c1', 'customers', false);
    expect(facade.modules()[0]?.enabled).toBe(false);
    expect(auth.refresh).toHaveBeenCalled();
    // And `refresh`, not `load`: load signs the person out when the API is unreachable, which after a switch that
    // already SUCCEEDED means a blip costs them their session (developer sweep, 2026-09-20).
    expect(auth.load).not.toHaveBeenCalled();
  });

  it('keeps the modules and says why when a switch is refused', async () => {
    api.list.mockResolvedValue([customers]);
    await facade.load('c1');
    api.switch.mockRejectedValue(new ModulesRefused('still_needed'));

    expect(await facade.switch('c1', 'customers', false)).toBe(false);

    expect(facade.error()).toBe('still_needed');
    expect(facade.modules()).toEqual([customers]);
    expect(auth.refresh).not.toHaveBeenCalled();
    facade.clearError();
    expect(facade.error()).toBeNull();
  });

  it('says the server could not be reached when the modules cannot be read', async () => {
    api.list.mockRejectedValue(new Error('offline'));

    await facade.load('c1');

    expect(facade.error()).toBe('network');
  });
});

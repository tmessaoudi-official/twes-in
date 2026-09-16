// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { CompanyApi, CompanyRefused } from './company-api';
import { CompanyFacade } from './company-facade';
import type { CompanyOption } from './company-types';

const acme: CompanyOption = { id: 'c1', name: 'Acme', status: 'active', role: 'owner' };
const globex: CompanyOption = { id: 'c2', name: 'Globex', status: 'active', role: 'member' };

describe('CompanyFacade', () => {
  const api = {
    companies: vi.fn(),
    switchTo: vi.fn(),
    members: vi.fn(),
    addMember: vi.fn(),
    removeMember: vi.fn(),
  };
  const auth = { me: vi.fn(), load: vi.fn() };
  let facade: CompanyFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    auth.me.mockReset();
    auth.load.mockReset();
    auth.me.mockReturnValue({ company: { id: 'c1', name: 'Acme', role: 'owner' } });
    TestBed.configureTestingModule({
      providers: [
        { provide: CompanyApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
    facade = TestBed.inject(CompanyFacade);
  });

  it('offers every company the API returned', async () => {
    api.companies.mockResolvedValue([acme, globex]);

    await facade.load();

    expect(facade.companies()).toEqual([acme, globex]);
    expect(facade.error()).toBeNull();
  });

  it('offers no switcher when there is only one company', async () => {
    api.companies.mockResolvedValue([acme]);

    await facade.load();

    expect(facade.canSwitch()).toBe(false);
  });

  it('offers the switcher as soon as there are two', async () => {
    api.companies.mockResolvedValue([acme, globex]);

    await facade.load();

    expect(facade.canSwitch()).toBe(true);
  });

  it('re-reads the signed-in state after switching, because the session is the truth', async () => {
    api.switchTo.mockResolvedValue(globex);
    auth.load.mockResolvedValue(null);

    const moved = await facade.switchTo('c2');

    expect(moved).toBe(true);
    expect(api.switchTo).toHaveBeenCalledWith('c2');
    expect(auth.load).toHaveBeenCalledOnce();
  });

  it('does not call the API when asked to switch to the company already being worked in', async () => {
    const moved = await facade.switchTo('c1');

    expect(moved).toBe(true);
    expect(api.switchTo).not.toHaveBeenCalled();
  });

  it('reports a refusal and leaves the signed-in state alone', async () => {
    api.switchTo.mockRejectedValue(new CompanyRefused('not_found'));

    const moved = await facade.switchTo('c2');

    expect(moved).toBe(false);
    expect(facade.error()).toBe('not_found');
    expect(auth.load).not.toHaveBeenCalled();
  });

  it('is no longer switching once a refusal is handled', async () => {
    api.switchTo.mockRejectedValue(new CompanyRefused('network'));

    await facade.switchTo('c2');

    expect(facade.switching()).toBe(false);
  });

  it('empties the list when it cannot be read', async () => {
    api.companies.mockRejectedValue(new CompanyRefused('network'));

    await facade.load();

    expect(facade.companies()).toEqual([]);
    expect(facade.error()).toBe('network');
  });
});

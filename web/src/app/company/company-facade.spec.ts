// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { failureToasts, provideQuietFeedback } from '../shared/testing/feedback';
import { CompanyApi, CompanyRefused } from './company-api';
import { CompanyFacade } from './company-facade';
import type { CompanyOption } from './company-types';

const acme: CompanyOption = {
  id: 'c1',
  name: 'Acme',
  status: 'active',
  role: 'owner',
  pinned: false,
  logoVersion: null,
  supportAccessUntil: null,
};
const globex: CompanyOption = {
  id: 'c2',
  name: 'Globex',
  status: 'active',
  role: 'member',
  pinned: false,
  logoVersion: null,
  supportAccessUntil: null,
};

describe('CompanyFacade', () => {
  const api = {
    companies: vi.fn(),
    switchTo: vi.fn(),
    leave: vi.fn(),
    pinAtSignIn: vi.fn(),
    members: vi.fn(),
    addMember: vi.fn(),
    removeMember: vi.fn(),
  };
  const auth = { me: vi.fn(), load: vi.fn(), refresh: vi.fn() };
  let facade: CompanyFacade;

  const unsaved = { confirmLeave: vi.fn(() => of(true)) };

  beforeEach(() => {
    unsaved.confirmLeave.mockReturnValue(of(true));
    Object.values(api).forEach((fn) => fn.mockReset());
    auth.me.mockReset();
    auth.load.mockReset();
    auth.refresh.mockReset();
    auth.me.mockReturnValue({ company: { id: 'c1', name: 'Acme', role: 'owner' } });
    TestBed.configureTestingModule({
      providers: [
        { provide: CompanyApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: UnsavedChanges, useValue: unsaved },
        provideQuietFeedback(),
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

  it('asks before switching away from unsaved work, and stays put on a no', async () => {
    // A switch rebuilds every open screen for the company chosen, so a half-filled form goes with it.
    unsaved.confirmLeave.mockReturnValue(of(false));
    const facade = TestBed.inject(CompanyFacade);

    expect(await facade.switchTo('c2')).toBe(false);
    expect(api.switchTo).not.toHaveBeenCalled();
    expect(auth.load).not.toHaveBeenCalled();
  });

  it('leaves the company and reads the session again, asking first about unsaved work', async () => {
    api.leave.mockResolvedValue(undefined);
    auth.load.mockResolvedValue(undefined);

    expect(await facade.leave()).toBe(true);
    expect(api.leave).toHaveBeenCalled();
    expect(auth.load).toHaveBeenCalled();

    unsaved.confirmLeave.mockReturnValue(of(false));
    api.leave.mockClear();
    expect(await facade.leave()).toBe(false);
    expect(api.leave).not.toHaveBeenCalled();
  });

  it('says a leave that failed, and stays', async () => {
    api.leave.mockRejectedValue(new Error('down'));

    expect(await facade.leave()).toBe(false);
    expect(failureToasts()).toEqual(['company.switch_failed']);
    expect(facade.switching()).toBe(false);
  });

  it('offers no switcher when there is only one company', async () => {
    api.companies.mockResolvedValue([acme]);

    await facade.load();

    expect(facade.canSwitch()).toBe(false);
  });

  it('offers the one company there is when none is open, as an operator reading one under support access', async () => {
    // docs/SPEC.md § 7: with no company open, « Choisir une entreprise » is the way into the only one offered.
    auth.me.mockReturnValue({ company: null });
    api.companies.mockResolvedValue([
      { ...acme, role: 'support', supportAccessUntil: '2026-10-27T10:00:00+01:00' },
    ]);

    await facade.load();

    expect(facade.canSwitch()).toBe(true);
  });

  it('offers no switcher when no company is offered at all', async () => {
    auth.me.mockReturnValue({ company: null });
    api.companies.mockResolvedValue([]);

    await facade.load();

    expect(facade.canSwitch()).toBe(false);
  });

  describe('when a support grant runs out', () => {
    // Nothing is recorded when a grant's time is up, so nothing is heard: the list is read again at that moment, and an
    // ended grant is no longer offered (hunt OP-2).
    const until = '2026-10-26T11:00:00+01:00';
    const atlas: CompanyOption = {
      ...globex,
      id: 'c3',
      name: 'Atlas',
      role: 'support',
      supportAccessUntil: until,
    };

    beforeEach(() => vi.useFakeTimers({ now: new Date('2026-10-26T10:00:00+01:00') }));
    afterEach(() => vi.useRealTimers());

    it('reads the list again once it has ended, and the session too when it was the company open', async () => {
      auth.me.mockReturnValue({ company: { id: 'c3', name: 'Atlas', role: 'support' } });
      api.companies.mockResolvedValue([acme, atlas]);
      await facade.load();
      api.companies.mockResolvedValue([acme]);

      await vi.advanceTimersByTimeAsync(59 * 60 * 1000);
      expect(api.companies).toHaveBeenCalledTimes(1);
      await vi.advanceTimersByTimeAsync(2 * 60 * 1000);

      expect(api.companies).toHaveBeenCalledTimes(2);
      expect(facade.companies()).toEqual([acme]);
      expect(auth.refresh).toHaveBeenCalledTimes(1);
    });

    it('reads nothing once the person has signed out', async () => {
      api.companies.mockResolvedValue([atlas]);
      await facade.load();
      auth.me.mockReturnValue(null);

      await vi.advanceTimersByTimeAsync(2 * 60 * 60 * 1000);

      expect(api.companies).toHaveBeenCalledTimes(1);
    });
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

  // A switch that fails used to close the menu on the old company with nothing said (U4).
  it('says a company that could not open, and reads the list again so a company gone from it goes', async () => {
    api.companies.mockResolvedValue([acme]);
    api.switchTo.mockRejectedValue(new CompanyRefused('not_found'));

    await facade.switchTo('c2');

    expect(failureToasts()).toEqual(['company.switch_failed']);
    expect(api.companies).toHaveBeenCalled();
    expect(facade.companies()).toEqual([acme]);
  });

  it('says nothing when the person chose to stay with what they typed', async () => {
    unsaved.confirmLeave.mockReturnValue(of(false));

    expect(await facade.switchTo('c2')).toBe(false);

    expect(failureToasts()).toEqual([]);
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

  it('pins the company a sign-in opens, then reads the list again to show it', async () => {
    api.pinAtSignIn.mockResolvedValue(undefined);
    api.companies.mockResolvedValue([{ ...acme, pinned: true }, globex]);

    expect(await facade.pinAtSignIn('c1')).toBe(true);

    expect(api.pinAtSignIn).toHaveBeenCalledWith('c1');
    expect(facade.companies()[0]?.pinned).toBe(true);
  });

  it('says a refused pin, and leaves the list as it was', async () => {
    api.companies.mockResolvedValue([acme, globex]);
    await facade.load();
    api.pinAtSignIn.mockRejectedValue(new CompanyRefused('forbidden'));

    expect(await facade.pinAtSignIn('c2')).toBe(false);

    expect(facade.error()).toBe('forbidden');
    expect(facade.companies()).toEqual([acme, globex]);
  });
});

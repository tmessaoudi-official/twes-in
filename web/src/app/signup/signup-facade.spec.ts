// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { SignupApi, SignupRefused } from './signup-api';
import { SignupFacade } from './signup-facade';
import type { SignupDetails } from './signup-types';

const details: SignupDetails = {
  displayName: 'Nadia',
  password: 'a-long-enough-password',
  companyName: 'Nouvelle Société',
  countryCode: 'TN',
  timezone: 'Africa/Tunis',
};

describe('SignupFacade', () => {
  const api = { availability: vi.fn(), request: vi.fn(), link: vi.fn(), complete: vi.fn() };
  let facade: SignupFacade;

  beforeEach(() => {
    for (const call of Object.values(api)) {
      call.mockReset();
    }
    TestBed.configureTestingModule({ providers: [{ provide: SignupApi, useValue: api }] });
    facade = TestBed.inject(SignupFacade);
  });

  it('knows whether signup is open, and takes an unreachable API for closed', async () => {
    api.availability.mockResolvedValue({ enabled: true, countries: ['TN'] });
    await facade.loadAvailability();
    expect(facade.availability()).toEqual({ enabled: true, countries: ['TN'] });

    api.availability.mockRejectedValue(new SignupRefused('network'));
    await facade.loadAvailability();
    expect(facade.availability()).toEqual({ enabled: false, countries: [] });
  });

  it('says a link was asked for without saying whether the address has an account', async () => {
    api.request.mockResolvedValue(undefined);

    expect(await facade.request('new@example.test', 'fr')).toBe(true);

    expect(facade.requested()).toBe(true);
    expect(facade.error()).toBeNull();
    expect(api.request).toHaveBeenCalledWith('new@example.test', 'fr');
  });

  it('reports a refused ask and stays ready for another', async () => {
    api.request.mockRejectedValue(new SignupRefused('too_many'));

    expect(await facade.request('new@example.test', 'fr')).toBe(false);

    expect(facade.requested()).toBe(false);
    expect(facade.error()).toBe('too_many');
    expect(facade.busy()).toBe(false);
  });

  it('shows the address a usable link was sent to, and nothing for one that is not', async () => {
    api.link.mockResolvedValue('new@example.test');
    await facade.loadLink('a-token');
    expect(facade.linkEmail()).toBe('new@example.test');

    api.link.mockRejectedValue(new SignupRefused('not_usable'));
    await facade.loadLink('a-token');
    expect(facade.linkEmail()).toBeNull();
    expect(facade.error()).toBe('not_usable');
  });

  it('finishes and remembers whether the company waits for approval', async () => {
    api.complete.mockResolvedValue({ companyName: 'Nouvelle Société', companyStatus: 'pending' });

    expect(await facade.complete('a-token', details)).toEqual({
      companyName: 'Nouvelle Société',
      companyStatus: 'pending',
    });

    expect(facade.completed()?.companyStatus).toBe('pending');
    expect(api.complete).toHaveBeenCalledWith('a-token', details);
  });

  it('does not claim an account was made when finishing is refused', async () => {
    api.complete.mockRejectedValue(new SignupRefused('refused'));

    expect(await facade.complete('a-token', details)).toBeNull();

    expect(facade.completed()).toBeNull();
    expect(facade.error()).toBe('refused');
  });
});

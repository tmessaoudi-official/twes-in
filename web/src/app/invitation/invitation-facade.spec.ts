// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { InvitationApi, InvitationRefused } from './invitation-api';
import { InvitationFacade } from './invitation-facade';
import type { InvitationOffer } from './invitation-types';

const offer: InvitationOffer = {
  email: 'stranger@example.test',
  companyName: 'Acme',
  roleName: 'member',
  expiresAt: '2026-09-16T10:00:00+00:00',
  hasAccount: false,
};

describe('InvitationFacade', () => {
  const api = { offer: vi.fn(), accept: vi.fn() };
  let facade: InvitationFacade;

  beforeEach(() => {
    api.offer.mockReset();
    api.accept.mockReset();
    TestBed.configureTestingModule({ providers: [{ provide: InvitationApi, useValue: api }] });
    facade = TestBed.inject(InvitationFacade);
  });

  it('shows what a usable link offers', async () => {
    api.offer.mockResolvedValue(offer);

    await facade.load('a-token');

    expect(facade.offer()).toEqual(offer);
    expect(facade.error()).toBeNull();
  });

  it('offers nothing at all for a link that cannot be used', async () => {
    api.offer.mockRejectedValue(new InvitationRefused('not_usable'));

    await facade.load('a-token');

    expect(facade.offer()).toBeNull();
    expect(facade.error()).toBe('not_usable');
  });

  it('accepts and records that it worked', async () => {
    api.accept.mockResolvedValue('Acme');

    const accepted = await facade.accept('a-token', 'New Person', 'a-long-enough-password');

    expect(accepted).toBe(true);
    expect(facade.accepted()).toBe(true);
    expect(api.accept).toHaveBeenCalledWith('a-token', 'New Person', 'a-long-enough-password');
  });

  it('accepts for an address that has an account without any name or password', async () => {
    api.accept.mockResolvedValue('Acme');

    const accepted = await facade.accept('a-token');

    expect(accepted).toBe(true);
    expect(api.accept).toHaveBeenCalledWith('a-token', null, null);
  });

  it('reports a breached password without claiming the account was made', async () => {
    api.accept.mockRejectedValue(new InvitationRefused('password_refused'));

    const accepted = await facade.accept('a-token', 'New Person', 'password');

    expect(accepted).toBe(false);
    expect(facade.accepted()).toBe(false);
    expect(facade.error()).toBe('password_refused');
  });

  it('is not busy once a refusal has been handled', async () => {
    api.accept.mockRejectedValue(new InvitationRefused('network'));

    await facade.accept('a-token', 'New Person', 'a-long-enough-password');

    expect(facade.busy()).toBe(false);
  });
});

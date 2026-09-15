// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { CompanyApi, CompanyRefused } from './company-api';
import type { MemberRow } from './company-types';
import { MembersFacade } from './members-facade';

const owner: MemberRow = {
  userId: 'u1',
  email: 'owner@example.test',
  displayName: 'Owner',
  role: 'owner',
  joinedAt: '2026-09-09T10:00:00+00:00',
  status: 'joined',
};

describe('MembersFacade', () => {
  const api = {
    companies: vi.fn(),
    switchTo: vi.fn(),
    members: vi.fn(),
    addMember: vi.fn(),
    removeMember: vi.fn(),
  };
  let facade: MembersFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    TestBed.configureTestingModule({ providers: [{ provide: CompanyApi, useValue: api }] });
    facade = TestBed.inject(MembersFacade);
  });

  it('lists the members of the company it was given', async () => {
    api.members.mockResolvedValue([owner]);

    await facade.load('c1');

    expect(api.members).toHaveBeenCalledWith('c1');
    expect(facade.members()).toEqual([owner]);
  });

  it('re-reads the list after adding, so the table shows what the server has', async () => {
    api.addMember.mockResolvedValue(owner);
    api.members.mockResolvedValue([owner]);

    const added = await facade.add('c1', 'joiner@example.test', 'member');

    expect(added).toEqual(owner);
    expect(api.addMember).toHaveBeenCalledWith('c1', 'joiner@example.test', 'member');
    expect(facade.members()).toEqual([owner]);
  });

  it('reports an address with no account rather than pretending it worked', async () => {
    api.addMember.mockRejectedValue(new CompanyRefused('unknown_user'));

    const added = await facade.add('c1', 'stranger@example.test', 'member');

    expect(added).toBeNull();
    expect(facade.error()).toBe('unknown_user');
  });

  it('does not re-read the list when adding failed', async () => {
    api.addMember.mockRejectedValue(new CompanyRefused('already_member'));

    await facade.add('c1', 'joiner@example.test', 'member');

    expect(api.members).not.toHaveBeenCalled();
  });

  it('reports that the address was invited', async () => {
    api.addMember.mockResolvedValue({ ...owner, status: 'invited' });
    api.members.mockResolvedValue([owner]);

    const added = await facade.add('c1', 'stranger@example.test', 'member');

    expect(added?.status).toBe('invited');
  });

  it('reports the last owner refusal', async () => {
    api.removeMember.mockRejectedValue(new CompanyRefused('last_owner'));

    const removed = await facade.remove('c1', 'u1');

    expect(removed).toBe(false);
    expect(facade.error()).toBe('last_owner');
  });

  it('is not busy once a call has settled', async () => {
    api.removeMember.mockRejectedValue(new CompanyRefused('network'));

    await facade.remove('c1', 'u1');

    expect(facade.busy()).toBe(false);
  });
});

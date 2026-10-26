// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { QuietReads } from '../shared/feedback/quiet-reads';
import { StepUp } from '../shared/step-up/step-up';
import { CompanyApi, CompanyRefused } from './company-api';
import type { MemberRow } from './company-types';
import { MembersFacade } from './members-facade';
import { refusedByTheDemo } from '../shared/testing/said-answers';

const owner: MemberRow = {
  userId: 'u1',
  email: 'owner@example.test',
  displayName: 'Owner',
  role: 'owner',
  joinedAt: '2026-09-09T10:00:00+00:00',
  status: 'joined',
  invitationId: null,
  expiresAt: null,
};

// The proof dialog is answered by the test; `retrying` itself is StepUp's own.
const stepUp = Object.assign(Object.create(StepUp.prototype) as StepUp, {
  request: vi.fn<(intro?: string) => Promise<boolean>>(),
});

describe('MembersFacade', () => {
  const api = {
    companies: vi.fn(),
    switchTo: vi.fn(),
    members: vi.fn(),
    addMember: vi.fn(),
    removeMember: vi.fn(),
    changeMemberRole: vi.fn(),
  };
  const auth = { refresh: vi.fn(async () => undefined), load: vi.fn(async () => null) };
  let facade: MembersFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    auth.refresh.mockClear();
    auth.load.mockClear();
    TestBed.configureTestingModule({
      providers: [
        { provide: CompanyApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
        { provide: StepUp, useValue: stepUp },
      ],
    });
    facade = TestBed.inject(MembersFacade);
  });

  it('marks no reload busy that nobody asked for, and always marks a write', async () => {
    const quiet = TestBed.inject(QuietReads);
    const seen: boolean[] = [];
    api.members.mockImplementation(() => {
      seen.push(facade.busy());
      return Promise.resolve([owner]);
    });
    api.removeMember.mockImplementation(() => {
      seen.push(facade.busy());
      return Promise.resolve(undefined);
    });

    await quiet.quietly(() => facade.load('c1'));
    await quiet.quietly(() => facade.remove('c1', 'u1'));

    // The reload, then the write and the list it reads again inside it.
    expect(seen).toEqual([false, true, true]);
    expect(facade.busy()).toBe(false);
  });

  it('reads the signed-in state again after a membership changed, which can be your own', async () => {
    // An admin who removes or re-roles themselves is changing what they may do; nothing else tells the shell,
    // because a live change never comes back to the tab that made it (developer sweep, 2026-09-20).
    api.members.mockResolvedValue([owner]);
    api.removeMember.mockResolvedValue(undefined);

    await facade.remove('c1', 'u1');

    expect(auth.refresh).toHaveBeenCalledTimes(1);
    expect(auth.load).not.toHaveBeenCalled();
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

  it('changes a member’s role, then reads the list and the person’s own rights again', async () => {
    api.changeMemberRole.mockResolvedValue({ ...owner, role: 'admin' });
    api.members.mockResolvedValue([{ ...owner, role: 'admin' }]);

    expect(await facade.changeRole('c1', 'u1', 'admin')).toBe(true);

    expect(api.changeMemberRole).toHaveBeenCalledWith('c1', 'u1', 'admin');
    expect(facade.members()).toEqual([{ ...owner, role: 'admin' }]);
    expect(auth.refresh).toHaveBeenCalled();
  });

  it('says the last owner is kept when lowering them was refused', async () => {
    api.changeMemberRole.mockRejectedValue(new CompanyRefused('last_owner'));

    expect(await facade.changeRole('c1', 'u1', 'admin')).toBe(false);
    expect(facade.error()).toBe('last_owner');
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

  // docs/SPEC.md § 7, the step-up list: adding or removing somebody waits for the person to prove who they are again.
  it('asks for the proof the API wants, then adds the person', async () => {
    stepUp.request.mockReset().mockResolvedValue(true);
    api.addMember
      .mockRejectedValueOnce(new CompanyRefused('step_up_required'))
      .mockResolvedValueOnce(owner);
    api.members.mockResolvedValue([owner]);

    expect(await facade.add('c1', 'joiner@example.test', 'member')).toEqual(owner);
    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_sensitive');
    expect(api.addMember).toHaveBeenCalledTimes(2);
  });

  it('says nothing changed when the person does not give the proof', async () => {
    stepUp.request.mockReset().mockResolvedValue(false);
    api.removeMember.mockRejectedValue(new CompanyRefused('step_up_required'));

    expect(await facade.remove('c1', 'u1')).toBe(false);
    expect(facade.error()).toBe('step_up_required');
    expect(api.removeMember).toHaveBeenCalledTimes(1);
  });

  it("adds nothing of its own to the demo's refusal of an invitation, which the toast has said", async () => {
    api.addMember.mockRejectedValue(refusedByTheDemo(new CompanyRefused('forbidden')));

    expect(await facade.add('c1', 'someone@example.test', 'staff')).toBeNull();
    expect(facade.error()).toBeNull();
  });
});

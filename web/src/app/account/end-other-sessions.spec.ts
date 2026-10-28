// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import { of } from 'rxjs';
import { Feedback } from '../shared/feedback/feedback';
import { TextsParts } from '../shared/i18n/texts-part';
import { ConnectedDevicesApi } from './connected-devices-api';
import { EndOtherSessions } from './end-other-sessions';

describe('EndOtherSessions', () => {
  const api = { endOthers: vi.fn() };
  const dialog = { open: vi.fn() };
  const feedback = { effect: vi.fn(), failure: vi.fn() };
  const router = { navigate: vi.fn() };
  const texts = { open: vi.fn() };

  beforeEach(() => {
    [api.endOthers, dialog.open, feedback.effect, feedback.failure, router.navigate].forEach((fn) =>
      fn.mockReset(),
    );
    texts.open.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      providers: [
        { provide: ConnectedDevicesApi, useValue: api },
        { provide: MatDialog, useValue: dialog },
        { provide: Feedback, useValue: feedback },
        { provide: Router, useValue: router },
        { provide: TextsParts, useValue: texts },
      ],
    });
  });

  const answering = (answer: boolean | undefined) =>
    dialog.open.mockReturnValue({ afterClosed: () => of(answer) });

  // Someone else may know the password: ending their sessions throws them out once, a new password keeps them out.
  it('asks first, saying a new password keeps an intruder out, then offers the way to change it', async () => {
    answering(true);
    api.endOthers.mockResolvedValue(2);

    expect(await TestBed.inject(EndOtherSessions).run()).toBe(2);

    expect(texts.open).toHaveBeenCalledWith('account');
    expect(dialog.open.mock.calls[0][1].data).toEqual(
      expect.objectContaining({
        kind: 'corrigeable',
        title: 'account.devices.end_others_title',
        message: 'account.devices.end_others_message',
      }),
    );
    const [key, params, kind, action] = feedback.effect.mock.calls[0];
    expect([key, params, kind]).toEqual([
      'account.devices.others_ended',
      { count: 2 },
      'corrigeable',
    ]);
    expect(action.key).toBe('account.devices.change_password');
    action.run();
    expect(router.navigate).toHaveBeenCalledWith(['/account'], {
      queryParams: { tab: 'security' },
    });
  });

  it('ends nothing when the person keeps them, and says a failure', async () => {
    answering(undefined);
    expect(await TestBed.inject(EndOtherSessions).run()).toBeNull();
    expect(api.endOthers).not.toHaveBeenCalled();

    answering(true);
    api.endOthers.mockRejectedValue(new Error('offline'));
    expect(await TestBed.inject(EndOtherSessions).run()).toBeNull();
    expect(feedback.failure).toHaveBeenCalledWith('account.devices.failed');
    expect(feedback.effect).not.toHaveBeenCalled();
  });
});

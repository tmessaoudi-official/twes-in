// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { PasswordResetApi, PasswordResetRefused } from './password-reset-api';
import { PasswordResetFacade } from './password-reset-facade';

describe('PasswordResetFacade', () => {
  const api = { forgot: vi.fn(), reset: vi.fn() };
  let facade: PasswordResetFacade;

  beforeEach(() => {
    api.forgot.mockReset().mockResolvedValue(undefined);
    api.reset.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({ providers: [{ provide: PasswordResetApi, useValue: api }] });
    facade = TestBed.inject(PasswordResetFacade);
  });

  it('remembers that a link was asked for, and nothing else about the address', async () => {
    await facade.request('someone@example.test', 'fr');

    expect(api.forgot).toHaveBeenCalledWith('someone@example.test', 'fr');
    expect(facade.requested()).toBe(true);
    expect(facade.error()).toBeNull();
    expect(facade.busy()).toBe(false);
  });

  it('keeps the page where it is and says why when a request is refused', async () => {
    api.forgot.mockRejectedValue(new PasswordResetRefused('too_many_attempts'));

    await facade.request('someone@example.test', 'fr');

    expect(facade.requested()).toBe(false);
    expect(facade.error()).toBe('too_many_attempts');
  });

  it('says whether the new password was accepted, and why not', async () => {
    expect(await facade.reset('t', 'a-long-enough-password')).toBe(true);

    api.reset.mockRejectedValue(new PasswordResetRefused('link_not_usable'));
    expect(await facade.reset('t', 'a-long-enough-password')).toBe(false);
    expect(facade.error()).toBe('link_not_usable');
  });

  it('reads a failure it does not know as a plain refusal', async () => {
    api.reset.mockRejectedValue(new Error('boom'));

    await facade.reset('t', 'x');

    expect(facade.error()).toBe('refused');
  });
});

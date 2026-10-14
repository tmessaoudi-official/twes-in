// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { StepUp } from '../shared/step-up/step-up';
import { CompanySecurityApi, CompanySecurityRefused } from './company-security-api';
import { CompanySecurityFacade } from './company-security-facade';

// The proof dialog is answered by the test; `retrying` itself is StepUp's own.
const stepUp = Object.assign(Object.create(StepUp.prototype) as StepUp, {
  request: vi.fn<(intro?: string) => Promise<boolean>>(),
});

describe('CompanySecurityFacade', () => {
  const api = { read: vi.fn(), requireSecondFactor: vi.fn() };
  const auth = { load: vi.fn(), refresh: vi.fn() };
  let facade: CompanySecurityFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    auth.load.mockReset();
    auth.refresh.mockReset();
    TestBed.configureTestingModule({
      providers: [
        { provide: CompanySecurityApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: StepUp, useValue: stepUp },
      ],
    });
    facade = TestBed.inject(CompanySecurityFacade);
  });

  it('loads what the company requires', async () => {
    api.read.mockResolvedValue({ mfaRequired: false, writable: true });

    await facade.load('c1');

    expect(api.read).toHaveBeenCalledWith('c1');
    expect(facade.security()).toEqual({ mfaRequired: false, writable: true });
    expect(facade.error()).toBeNull();
  });

  it('saves a change, then reads the signed-in state again, which decides whether this account must enrol', async () => {
    api.requireSecondFactor.mockResolvedValue({ mfaRequired: true, writable: true });

    expect(await facade.requireSecondFactor('c1', true)).toBe(true);

    expect(api.requireSecondFactor).toHaveBeenCalledWith('c1', true);
    expect(facade.security()).toEqual({ mfaRequired: true, writable: true });
    expect(auth.refresh).toHaveBeenCalledTimes(1);
  });

  it('keeps a refusal as the error and answers false, without reloading anything', async () => {
    api.read.mockResolvedValue({ mfaRequired: false, writable: true });
    await facade.load('c1');
    api.requireSecondFactor.mockRejectedValue(new CompanySecurityRefused('not_found'));

    expect(await facade.requireSecondFactor('c1', true)).toBe(false);

    expect(facade.error()).toBe('not_found');
    expect(facade.security()).toEqual({ mfaRequired: false, writable: true });
    expect(auth.refresh).not.toHaveBeenCalled();
  });

  it('asks for the proof the API wants before the requirement is turned off, then turns it off', async () => {
    stepUp.request.mockReset().mockResolvedValue(true);
    api.requireSecondFactor
      .mockRejectedValueOnce(new CompanySecurityRefused('step_up_required'))
      .mockResolvedValueOnce({ mfaRequired: false, writable: true });

    expect(await facade.requireSecondFactor('c1', false)).toBe(true);

    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_mfa_off');
    expect(api.requireSecondFactor).toHaveBeenCalledTimes(2);
    expect(facade.security()).toEqual({ mfaRequired: false, writable: true });
    expect(facade.error()).toBeNull();
  });

  it('keeps the requirement and says why when the person does not give the proof', async () => {
    api.read.mockResolvedValue({ mfaRequired: true, writable: true });
    await facade.load('c1');
    stepUp.request.mockReset().mockResolvedValue(false);
    api.requireSecondFactor.mockRejectedValue(new CompanySecurityRefused('step_up_required'));

    expect(await facade.requireSecondFactor('c1', false)).toBe(false);

    expect(api.requireSecondFactor).toHaveBeenCalledTimes(1);
    expect(facade.error()).toBe('step_up_required');
    expect(facade.security()).toEqual({ mfaRequired: true, writable: true });
    expect(auth.refresh).not.toHaveBeenCalled();
  });
});

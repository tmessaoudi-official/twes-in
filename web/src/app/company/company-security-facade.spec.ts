// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { CompanySecurityApi, CompanySecurityRefused } from './company-security-api';
import { CompanySecurityFacade } from './company-security-facade';

describe('CompanySecurityFacade', () => {
  const api = { read: vi.fn(), requireSecondFactor: vi.fn() };
  const auth = { load: vi.fn() };
  let facade: CompanySecurityFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    auth.load.mockReset();
    TestBed.configureTestingModule({
      providers: [
        { provide: CompanySecurityApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
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
    expect(auth.load).toHaveBeenCalledTimes(1);
  });

  it('keeps a refusal as the error and answers false, without reloading anything', async () => {
    api.read.mockResolvedValue({ mfaRequired: false, writable: true });
    await facade.load('c1');
    api.requireSecondFactor.mockRejectedValue(new CompanySecurityRefused('not_found'));

    expect(await facade.requireSecondFactor('c1', true)).toBe(false);

    expect(facade.error()).toBe('not_found');
    expect(facade.security()).toEqual({ mfaRequired: false, writable: true });
    expect(auth.load).not.toHaveBeenCalled();
  });
});

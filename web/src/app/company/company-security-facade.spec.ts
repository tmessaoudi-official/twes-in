// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { StepUp } from '../shared/step-up/step-up';
import { CompanySecurityApi, CompanySecurityRefused } from './company-security-api';
import { CompanySecurityFacade } from './company-security-facade';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type { SettingRow } from '../shared/settings/settings-types';

const entryRow = (value: boolean, version?: number, writable = true): SettingRow =>
  ({
    key: 'identity.passkey_entry',
    chain: 'presentation',
    value,
    versions: version === undefined ? {} : { company: version },
    writableLevels: writable ? ['company', 'role'] : [],
  }) as unknown as SettingRow;

// The proof dialog is answered by the test; `retrying` itself is StepUp's own.
const stepUp = Object.assign(Object.create(StepUp.prototype) as StepUp, {
  request: vi.fn<(intro?: string) => Promise<boolean>>(),
});

describe('CompanySecurityFacade', () => {
  const api = { read: vi.fn(), requireSecondFactor: vi.fn() };
  const auth = { load: vi.fn(), refresh: vi.fn() };
  const settings = { chain: vi.fn(), changeAll: vi.fn() };
  let facade: CompanySecurityFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    auth.load.mockReset();
    auth.refresh.mockReset();
    settings.chain.mockReset().mockResolvedValue([entryRow(true)]);
    settings.changeAll.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      providers: [
        { provide: CompanySecurityApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: StepUp, useValue: stepUp },
        { provide: SettingsApi, useValue: settings },
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

  it('reads whether the company lets its members enter with a passkey, and who may change it', async () => {
    api.read.mockResolvedValue({ mfaRequired: false, writable: true });
    settings.chain.mockResolvedValue([entryRow(false, 3, false)]);

    await facade.load('c1');

    expect(settings.chain).toHaveBeenCalledWith('c1', 'presentation');
    expect(facade.passkeyEntry()).toEqual({ on: false, version: 3, writable: false });
  });

  it('saves the passkey entry at the company level with the version read, then reads it again', async () => {
    api.read.mockResolvedValue({ mfaRequired: false, writable: true });
    settings.chain
      .mockResolvedValueOnce([entryRow(true, 2)])
      .mockResolvedValueOnce([entryRow(false, 3)]);
    await facade.load('c1');

    expect(await facade.takePasskeys('c1', false)).toBe(true);

    expect(settings.changeAll).toHaveBeenCalledExactlyOnceWith('c1', 'company', [
      { key: 'identity.passkey_entry', value: false, version: 2 },
    ]);
    expect(facade.passkeyEntry()).toEqual({ on: false, version: 3, writable: true });
  });

  it('says a passkey entry another person saved meanwhile, and keeps what it read', async () => {
    api.read.mockResolvedValue({ mfaRequired: false, writable: true });
    await facade.load('c1');
    settings.changeAll.mockRejectedValue(new SettingsRefused('record_changed'));

    expect(await facade.takePasskeys('c1', false)).toBe(false);

    expect(facade.error()).toBe('record_changed');
    expect(facade.passkeyEntry()).toEqual({ on: true, version: 0, writable: true });
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

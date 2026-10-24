// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import type { MfaStatus } from './auth-types';
import { lockoutRisk, MfaLockout } from './mfa-lockout';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const mfa = (status: Partial<MfaStatus>): MfaStatus => ({
  enrolled: true,
  required: false,
  totp: true,
  passkeys: 0,
  recoveryCodes: 10,
  ...status,
});

@Component({
  imports: [MfaLockout],
  template: `<app-mfa-lockout
    [mfa]="status()"
    [passkeysSupported]="supported()"
    [passkeysElsewhere]="elsewhere()"
    (addPasskey)="added = added + 1"
    (newCodes)="renewed = renewed + 1"
  />`,
})
class Host {
  readonly status = signal(mfa({}));
  readonly supported = signal(true);
  readonly elsewhere = signal<string | null>(null);
  added = 0;
  renewed = 0;
}

describe('lockoutRisk', () => {
  it.each([
    ['an authenticator and no passkey', mfa({}), true],
    ['one passkey and no code', mfa({ totp: false, passkeys: 1, recoveryCodes: 0 }), true],
    // One factor however many codes back it, as an authenticator alone: the ruling pushes a second factor then.
    ['one passkey and its codes', mfa({ totp: false, passkeys: 1, recoveryCodes: 10 }), true],
    ['an authenticator and a passkey', mfa({ passkeys: 1 }), false],
    ['two passkeys and no code', mfa({ totp: false, passkeys: 2, recoveryCodes: 0 }), false],
  ])('says whether %s hangs on one device', (_name, status, single) => {
    expect(lockoutRisk(status)?.singleDevice ?? false).toBe(single);
  });

  it('warns once three codes or fewer are left, and not before', () => {
    expect(lockoutRisk(mfa({ passkeys: 1, recoveryCodes: 4 }))).toBeNull();
    expect(lockoutRisk(mfa({ passkeys: 1, recoveryCodes: 3 }))).toEqual({
      singleDevice: false,
      codesLeft: 3,
      required: false,
    });
  });

  it('says nothing to an account with no factor yet', () => {
    expect(lockoutRisk(mfa({ enrolled: false, totp: false, recoveryCodes: 0 }))).toBeNull();
  });
});

describe('MfaLockout', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    });
  });

  async function render(status: MfaStatus, supported = true, elsewhere: string | null = null) {
    const fixture = TestBed.createComponent(Host);
    fixture.componentInstance.status.set(status);
    fixture.componentInstance.supported.set(supported);
    fixture.componentInstance.elsewhere.set(elsewhere);
    fixture.detectChanges();
    await fixture.whenStable();
    const q = (id: string): HTMLElement | null =>
      fixture.nativeElement.querySelector(`[data-testid="${id}"]`);
    return { fixture, q };
  }

  it('says in words that one device holds the account, with both ways to make it safer', async () => {
    const { fixture, q } = await render(mfa({ recoveryCodes: 8 }));

    expect(q('mfa-lockout-said')?.textContent).toContain('mfa_lockout.single_device');
    expect(q('mfa-lockout-required')).toBeNull();
    q('mfa-lockout-add-passkey')?.click();
    q('mfa-lockout-new-codes')?.click();
    expect(fixture.componentInstance.added).toBe(1);
    expect(fixture.componentInstance.renewed).toBe(1);
  });

  it.each([
    [0, 'mfa_lockout.codes_none'],
    [2, 'mfa_lockout.codes_low'],
  ])('with %i codes left beside a passkey, asks for new codes', async (left, said) => {
    const { q } = await render(mfa({ passkeys: 1, recoveryCodes: left }));

    expect(q('mfa-lockout-said')?.textContent).toContain(said);
    expect(q('mfa-lockout-add-passkey')).toBeNull();
    expect(q('mfa-lockout-new-codes')).not.toBeNull();
  });

  it('names the installation host for an account its company requires the check of', async () => {
    const { q } = await render(mfa({ required: true }));

    expect(q('mfa-lockout-required')?.textContent).toContain('mfa_lockout.required');
  });

  it('offers no passkey, and no codes needing one, where this browser cannot use one', async () => {
    const { q } = await render(mfa({ totp: false, passkeys: 1, recoveryCodes: 0 }), false);

    expect(q('mfa-lockout-said')?.textContent).toContain('mfa_lockout.single_device');
    expect(q('mfa-lockout-add-passkey')).toBeNull();
    expect(q('mfa-lockout-new-codes')).toBeNull();
  });

  it('says where a passkey is added when this address cannot use one, and offers none here', async () => {
    const { q } = await render(mfa({ recoveryCodes: 8 }), false, 'https://localhost');

    expect(q('mfa-lockout-said')?.textContent).toContain('mfa_lockout.single_device_elsewhere');
    expect(q('mfa-lockout-add-passkey')).toBeNull();
    expect(q('mfa-lockout-new-codes')).not.toBeNull();
  });

  it('shows nothing to an account that holds several ways in', async () => {
    const { q } = await render(mfa({ passkeys: 2, recoveryCodes: 10 }));

    expect(q('mfa-lockout')).toBeNull();
  });
});

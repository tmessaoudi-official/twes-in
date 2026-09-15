// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from './auth-facade';
import type { MfaStatus, PasskeySummary, SignedInState } from './auth-types';
import { PasskeyClient } from './passkey-client';
import { TwoFactorPage } from './two-factor-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      auth: {
        two_factor: { title: 'Vérification en deux étapes' },
        errors: {
          invalid_code: 'Code incorrect',
          passkey_cancelled: 'Aucune clé utilisée',
          mfa_last_factor: 'Dernier facteur',
        },
      },
    });
  }
}

const SECRET = 'JBSWY3DPEHPK3PXP';
const URI = `otpauth://totp/twes-in:owner%40example.test?secret=${SECRET}&issuer=twes-in`;

function signedIn(mfa: MfaStatus): SignedInState {
  return {
    user: {
      id: '1',
      email: 'owner@example.test',
      displayName: 'Owner',
      locale: 'fr',
      isPlatformOperator: false,
    },
    company: null,
    permissions: [],
    modules: [],
    mfa,
  };
}

describe('TwoFactorPage', () => {
  let facade: {
    me: ReturnType<typeof signal<SignedInState | null>>;
    beginTotpEnrolment: ReturnType<typeof vi.fn>;
    confirmTotpEnrolment: ReturnType<typeof vi.fn>;
    regenerateRecoveryCodes: ReturnType<typeof vi.fn>;
    regenerateRecoveryCodesWithPasskey: ReturnType<typeof vi.fn>;
    listPasskeys: ReturnType<typeof vi.fn>;
    addPasskey: ReturnType<typeof vi.fn>;
    removePasskey: ReturnType<typeof vi.fn>;
  };

  async function render(mfa: MfaStatus, passkeys: PasskeySummary[] = []) {
    facade = {
      me: signal<SignedInState | null>(signedIn(mfa)),
      beginTotpEnrolment: vi.fn().mockResolvedValue({
        ok: true,
        enrolment: { secret: SECRET, provisioningUri: URI },
      }),
      confirmTotpEnrolment: vi.fn(),
      regenerateRecoveryCodes: vi.fn(),
      regenerateRecoveryCodesWithPasskey: vi.fn(),
      listPasskeys: vi.fn().mockResolvedValue({ ok: true, passkeys }),
      addPasskey: vi.fn(),
      removePasskey: vi.fn(),
    };
    TestBed.configureTestingModule({
      imports: [TwoFactorPage],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: facade },
        { provide: PasskeyClient, useValue: { supported: () => true } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    });
    const fixture = TestBed.createComponent(TwoFactorPage);
    await settle(fixture);
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    return { fixture, el, query };
  }

  async function settle(fixture: { whenStable(): Promise<unknown> }) {
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await fixture.whenStable();
  }

  async function submitCode(rendered: Awaited<ReturnType<typeof render>>, code: string) {
    const input = rendered.query<HTMLInputElement>('two-factor-code')!;
    input.value = code;
    input.dispatchEvent(new Event('input'));
    rendered.query<HTMLFormElement>('two-factor-form')?.dispatchEvent(new Event('submit'));
    await settle(rendered.fixture);
  }

  it('starts an enrolment on arrival and shows the QR code and the secret to type in by hand', async () => {
    const { query } = await render({ enrolled: false, required: true, totp: false, passkeys: 0 });

    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
    expect(query('two-factor-qr')?.querySelector('svg rect[data-module]')).not.toBeNull();
    expect(query('two-factor-secret')?.textContent?.replace(/\s/g, '')).toBe(SECRET);
  });

  it('confirms with a code, shows the recovery codes once, then continues home', async () => {
    const rendered = await render({ enrolled: false, required: true, totp: false, passkeys: 0 });
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    facade.confirmTotpEnrolment.mockResolvedValue({
      ok: true,
      recoveryCodes: ['aaaa-bbbb', 'cccc-dddd'],
    });

    await submitCode(rendered, '123456');

    expect(facade.confirmTotpEnrolment).toHaveBeenCalledWith('123456');
    expect(rendered.query('two-factor-form')).toBeNull();
    const codes = [...(rendered.query('two-factor-recovery-codes')?.querySelectorAll('li') ?? [])];
    expect(codes.map((item) => item.textContent?.trim())).toEqual(['aaaa-bbbb', 'cccc-dddd']);

    rendered.query<HTMLButtonElement>('two-factor-continue')?.click();
    await settle(rendered.fixture);
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('shows a refused code, clears it and keeps the same secret on screen', async () => {
    const rendered = await render({ enrolled: false, required: false, totp: false, passkeys: 0 });
    facade.confirmTotpEnrolment.mockResolvedValue({ ok: false, error: 'invalid_code' });

    await submitCode(rendered, '000000');

    expect(rendered.query('two-factor-error')?.textContent).toContain('Code incorrect');
    expect(rendered.query<HTMLInputElement>('two-factor-code')?.value).toBe('');
    expect(rendered.query('two-factor-secret')?.textContent?.replace(/\s/g, '')).toBe(SECRET);
    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
  });

  it('says an authenticator is already on, and starts nothing', async () => {
    const { query } = await render({ enrolled: true, required: false, totp: true, passkeys: 0 });

    expect(query('two-factor-enabled')).not.toBeNull();
    expect(query('two-factor-form')).toBeNull();
    expect(facade.beginTotpEnrolment).not.toHaveBeenCalled();
  });

  async function regenerate(rendered: Awaited<ReturnType<typeof render>>, code: string) {
    const input = rendered.query<HTMLInputElement>('two-factor-regenerate-code')!;
    input.value = code;
    input.dispatchEvent(new Event('input'));
    rendered
      .query<HTMLFormElement>('two-factor-regenerate-form')
      ?.dispatchEvent(new Event('submit'));
    await settle(rendered.fixture);
  }

  it('gives an account with an authenticator a new set of recovery codes against a current code', async () => {
    const rendered = await render({ enrolled: true, required: false, totp: true, passkeys: 0 });
    facade.regenerateRecoveryCodes.mockResolvedValue({
      ok: true,
      recoveryCodes: ['eeeee-fffff', 'ggggg-hhhhh'],
    });

    await regenerate(rendered, '123456');

    expect(facade.regenerateRecoveryCodes).toHaveBeenCalledWith('123456');
    const codes = [...(rendered.query('two-factor-recovery-codes')?.querySelectorAll('li') ?? [])];
    expect(codes.map((item) => item.textContent?.trim())).toEqual(['eeeee-fffff', 'ggggg-hhhhh']);
    expect(rendered.query('two-factor-regenerate-form')).toBeNull();
  });

  it('says why a regeneration was refused, clears the code and shows no codes', async () => {
    const rendered = await render({ enrolled: true, required: false, totp: true, passkeys: 0 });
    facade.regenerateRecoveryCodes.mockResolvedValue({ ok: false, error: 'invalid_code' });

    await regenerate(rendered, '000000');

    expect(rendered.query('two-factor-error')?.textContent).toContain('Code incorrect');
    expect(rendered.query<HTMLInputElement>('two-factor-regenerate-code')?.value).toBe('');
    expect(rendered.query('two-factor-recovery-codes')).toBeNull();
  });

  const laptop: PasskeySummary = {
    id: 'p1',
    name: 'Work laptop',
    createdAt: '2026-09-15T10:00:00+00:00',
    lastUsedAt: null,
  };

  async function addPasskey(rendered: Awaited<ReturnType<typeof render>>, name: string) {
    const input = rendered.query<HTMLInputElement>('two-factor-passkey-name')!;
    input.value = name;
    input.dispatchEvent(new Event('input'));
    rendered.query<HTMLFormElement>('two-factor-passkey-form')?.dispatchEvent(new Event('submit'));
    await settle(rendered.fixture);
  }

  function listed(rendered: Awaited<ReturnType<typeof render>>): string[] {
    return [...rendered.el.querySelectorAll('[data-testid="two-factor-passkey-name-label"]')].map(
      (item) => item.textContent?.trim() ?? '',
    );
  }

  it('adds a first passkey and shows the recovery codes it issued', async () => {
    const rendered = await render({ enrolled: false, required: true, totp: false, passkeys: 0 });
    facade.addPasskey.mockResolvedValue({
      ok: true,
      passkey: laptop,
      recoveryCodes: ['aaaaa-bbbbb'],
    });

    await addPasskey(rendered, 'Work laptop');

    expect(facade.addPasskey).toHaveBeenCalledWith('Work laptop');
    const codes = [...(rendered.query('two-factor-recovery-codes')?.querySelectorAll('li') ?? [])];
    expect(codes.map((item) => item.textContent?.trim())).toEqual(['aaaaa-bbbbb']);
  });

  it('adds another passkey to the list without showing any codes', async () => {
    const rendered = await render({ enrolled: true, required: false, totp: true, passkeys: 0 });
    facade.addPasskey.mockResolvedValue({ ok: true, passkey: laptop, recoveryCodes: [] });

    await addPasskey(rendered, 'Work laptop');

    expect(listed(rendered)).toEqual(['Work laptop']);
    expect(rendered.query('two-factor-recovery-codes')).toBeNull();
    expect(rendered.query<HTMLInputElement>('two-factor-passkey-name')?.value).toBe('');
  });

  it('says a cancelled passkey was not added and keeps the name typed', async () => {
    const rendered = await render({ enrolled: false, required: false, totp: false, passkeys: 0 });
    facade.addPasskey.mockResolvedValue({ ok: false, error: 'passkey_cancelled' });

    await addPasskey(rendered, 'Work laptop');

    expect(rendered.query('two-factor-passkey-error')?.textContent).toContain(
      'Aucune clé utilisée',
    );
    expect(rendered.query<HTMLInputElement>('two-factor-passkey-name')?.value).toBe('Work laptop');
    expect(rendered.query('two-factor-recovery-codes')).toBeNull();
  });

  it('shows a passkey-only account its passkeys, and offers an authenticator app without starting one', async () => {
    const rendered = await render({ enrolled: true, required: false, totp: false, passkeys: 1 }, [
      laptop,
    ]);

    expect(rendered.query('two-factor-enabled')).not.toBeNull();
    expect(listed(rendered)).toEqual(['Work laptop']);
    expect(rendered.query('two-factor-regenerate-form')).toBeNull();
    expect(rendered.query('two-factor-qr')).toBeNull();
    expect(facade.beginTotpEnrolment).not.toHaveBeenCalled();

    rendered.query<HTMLButtonElement>('two-factor-app-start')?.click();
    await settle(rendered.fixture);
    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
    expect(rendered.query('two-factor-qr')).not.toBeNull();
  });

  it('gives an account with a passkey a new set of recovery codes against the passkey', async () => {
    const rendered = await render({ enrolled: true, required: false, totp: false, passkeys: 1 }, [
      laptop,
    ]);
    facade.regenerateRecoveryCodesWithPasskey.mockResolvedValueOnce({
      ok: false,
      error: 'passkey_cancelled',
    });

    rendered.query<HTMLButtonElement>('two-factor-passkey-recovery')?.click();
    await settle(rendered.fixture);
    expect(rendered.query('two-factor-passkey-error')?.textContent).toContain(
      'Aucune clé utilisée',
    );
    expect(rendered.query('two-factor-recovery-codes')).toBeNull();

    facade.regenerateRecoveryCodesWithPasskey.mockResolvedValue({
      ok: true,
      recoveryCodes: ['eeeee-fffff'],
    });
    rendered.query<HTMLButtonElement>('two-factor-passkey-recovery')?.click();
    await settle(rendered.fixture);
    const codes = [...(rendered.query('two-factor-recovery-codes')?.querySelectorAll('li') ?? [])];
    expect(codes.map((item) => item.textContent?.trim())).toEqual(['eeeee-fffff']);
  });

  it('offers no passkey replacement of the codes to an account without a passkey', async () => {
    const { query } = await render({ enrolled: true, required: false, totp: true, passkeys: 0 });

    expect(query('two-factor-passkey-recovery')).toBeNull();
  });

  it('starts the authenticator set-up again once the last factor is removed', async () => {
    const rendered = await render({ enrolled: true, required: false, totp: false, passkeys: 1 }, [
      laptop,
    ]);
    facade.removePasskey.mockImplementation(async () => {
      facade.me.set(signedIn({ enrolled: false, required: false, totp: false, passkeys: 0 }));
      return { ok: true };
    });

    rendered.query<HTMLButtonElement>('two-factor-passkey-remove')?.click();
    await settle(rendered.fixture);

    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
    expect(rendered.query('two-factor-qr')).not.toBeNull();
    expect(rendered.query('two-factor-logout')).not.toBeNull();
  });

  it('removes a passkey from the list, and says why the last one stays', async () => {
    const rendered = await render({ enrolled: true, required: true, totp: false, passkeys: 1 }, [
      laptop,
    ]);
    facade.removePasskey.mockResolvedValueOnce({ ok: false, error: 'mfa_last_factor' });

    rendered.query<HTMLButtonElement>('two-factor-passkey-remove')?.click();
    await settle(rendered.fixture);

    expect(facade.removePasskey).toHaveBeenCalledWith('p1');
    expect(rendered.query('two-factor-passkey-error')?.textContent).toContain('Dernier facteur');
    expect(listed(rendered)).toEqual(['Work laptop']);

    facade.removePasskey.mockResolvedValue({ ok: true });
    rendered.query<HTMLButtonElement>('two-factor-passkey-remove')?.click();
    await settle(rendered.fixture);

    expect(listed(rendered)).toEqual([]);
    expect(rendered.query('two-factor-passkey-error')).toBeNull();
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from './auth-facade';
import { Session } from '../shared/session/session';
import type { MfaStatus, PasskeySummary, SignedInState } from './auth-types';
import { PasskeyClient } from './passkey-client';
import { StepUp } from '../shared/step-up/step-up';
import { TwoFactorPage } from './two-factor-page';
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';
import { Feedback } from '../shared/feedback/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      auth: {
        two_factor: {
          title: 'Vérification en deux étapes',
          app_remove_required: 'Exigée : seulement remplacée',
        },
        errors: {
          invalid_code: 'Code incorrect',
          passkey_cancelled: 'Aucune clé utilisée',
          mfa_last_factor: 'Dernier facteur',
          mfa_authenticator_required: 'Exigée par une entreprise',
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
      demo: false,
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
    removeAuthenticator: ReturnType<typeof vi.fn>;
    beginTotpReplacement: ReturnType<typeof vi.fn>;
    confirmTotpReplacement: ReturnType<typeof vi.fn>;
    switchPasskeySignIn: ReturnType<typeof vi.fn>;
  };
  let stepUp: { request: ReturnType<typeof vi.fn> };
  /** What the removal's confirmation answers: yes, unless a case keeps the factor. */
  let dialog: { open: ReturnType<typeof vi.fn> };
  /** This browser at this address: passkeys work here unless a case opens the page elsewhere. */
  let passkeyClient: { supported: () => boolean; elsewhere: () => string | null };
  beforeEach(() => {
    passkeyClient = { supported: () => true, elsewhere: () => null };
  });

  async function render(
    mfa: MfaStatus,
    passkeys: PasskeySummary[] = [],
    shapeBegin: (
      begin: ReturnType<typeof vi.fn>,
      proof: ReturnType<typeof vi.fn>,
    ) => unknown = () => undefined,
  ) {
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
      removeAuthenticator: vi.fn(),
      beginTotpReplacement: vi.fn().mockResolvedValue({
        ok: true,
        enrolment: {
          secret: 'KRSXG5CTMVRXEZLU',
          provisioningUri: 'otpauth://totp/twes-in:new?secret=KRSXG5CTMVRXEZLU',
        },
      }),
      confirmTotpReplacement: vi.fn(),
      switchPasskeySignIn: vi.fn(),
    };
    stepUp = { request: vi.fn().mockResolvedValue(true) };
    dialog = { open: vi.fn(() => ({ afterClosed: () => of(true) })) };
    shapeBegin(facade.beginTotpEnrolment, stepUp.request);
    TestBed.configureTestingModule({
      imports: [TwoFactorPage],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: facade },
        { provide: Session, useExisting: AuthFacade },
        { provide: PasskeyClient, useValue: passkeyClient },
        { provide: StepUp, useValue: stepUp },
        { provide: MatDialog, useValue: dialog },
        provideStillAppearance(),
        provideQuietFeedback(),
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
    const { query } = await render({
      enrolled: false,
      required: true,
      totp: false,
      passkeys: 0,
      recoveryCodes: 0,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });

    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
    expect(query('two-factor-qr')?.querySelector('svg rect[data-module]')).not.toBeNull();
    expect(query('two-factor-secret')?.textContent?.replace(/\s/g, '')).toBe(SECRET);
  });

  it('confirms with a code, shows the recovery codes once, then continues home', async () => {
    const rendered = await render({
      enrolled: false,
      required: true,
      totp: false,
      passkeys: 0,
      recoveryCodes: 0,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });
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
    const rendered = await render({
      enrolled: false,
      required: false,
      totp: false,
      passkeys: 0,
      recoveryCodes: 0,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });
    facade.confirmTotpEnrolment.mockResolvedValue({ ok: false, error: 'invalid_code' });

    await submitCode(rendered, '000000');

    expect(rendered.query('two-factor-error')?.textContent).toContain('Code incorrect');
    expect(rendered.query<HTMLInputElement>('two-factor-code')?.value).toBe('');
    expect(rendered.query('two-factor-secret')?.textContent?.replace(/\s/g, '')).toBe(SECRET);
    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
  });

  it('adds nothing of its own to a refusal the facade says was said already', async () => {
    const { query } = await render(
      {
        enrolled: false,
        required: false,
        totp: false,
        passkeys: 0,
        recoveryCodes: 0,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [],
      (begin) => begin.mockResolvedValue({ ok: false, error: null }),
    );

    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
    expect(query('two-factor-error')).toBeNull();
  });

  it('says an authenticator is already on, and starts nothing', async () => {
    const { query } = await render({
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 0,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });

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
    const rendered = await render({
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 0,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });
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
    const rendered = await render({
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 0,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });
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
    const rendered = await render({
      enrolled: false,
      required: true,
      totp: false,
      passkeys: 0,
      recoveryCodes: 0,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });
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
    const rendered = await render({
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 0,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });
    facade.addPasskey.mockResolvedValue({ ok: true, passkey: laptop, recoveryCodes: [] });

    await addPasskey(rendered, 'Work laptop');

    expect(listed(rendered)).toEqual(['Work laptop']);
    expect(rendered.query('two-factor-recovery-codes')).toBeNull();
    expect(rendered.query<HTMLInputElement>('two-factor-passkey-name')?.value).toBe('');
  });

  it('says a cancelled passkey was not added and keeps the name typed', async () => {
    const rendered = await render({
      enrolled: false,
      required: false,
      totp: false,
      passkeys: 0,
      recoveryCodes: 0,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });
    facade.addPasskey.mockResolvedValue({ ok: false, error: 'passkey_cancelled' });

    await addPasskey(rendered, 'Work laptop');

    expect(rendered.query('two-factor-passkey-error')?.textContent).toContain(
      'Aucune clé utilisée',
    );
    expect(rendered.query<HTMLInputElement>('two-factor-passkey-name')?.value).toBe('Work laptop');
    expect(rendered.query('two-factor-recovery-codes')).toBeNull();
  });

  it('shows a passkey-only account its passkeys, and offers an authenticator app without starting one', async () => {
    const rendered = await render(
      {
        enrolled: true,
        required: false,
        totp: false,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [laptop],
    );

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
    const rendered = await render(
      {
        enrolled: true,
        required: false,
        totp: false,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [laptop],
    );
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
    const { query } = await render({
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 0,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });

    expect(query('two-factor-passkey-recovery')).toBeNull();
  });

  it('starts the authenticator set-up again once the last factor is removed', async () => {
    const rendered = await render(
      {
        enrolled: true,
        required: false,
        totp: false,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [laptop],
    );
    facade.removePasskey.mockImplementation(async () => {
      facade.me.set(
        signedIn({
          enrolled: false,
          required: false,
          totp: false,
          passkeys: 0,
          recoveryCodes: 0,
          passkeySignIn: false,
          passkeySignInAllowed: false,
        }),
      );
      return { ok: true };
    });

    rendered.query<HTMLButtonElement>('two-factor-passkey-remove')?.click();
    await settle(rendered.fixture);

    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
    expect(rendered.query('two-factor-qr')).not.toBeNull();
    expect(rendered.query('two-factor-later')).not.toBeNull();
  });

  // A page with no way out: an account nothing requires to use the check came here by choice and may leave unchanged.
  it('lets an account nothing requires to use it leave without setting anything up', async () => {
    const rendered = await render({
      enrolled: false,
      required: false,
      totp: false,
      passkeys: 0,
      recoveryCodes: 0,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    // Outside the shell, signing out stays offered beside it: there is no account menu here.
    expect(rendered.query('two-factor-logout')).not.toBeNull();
    rendered.query<HTMLButtonElement>('two-factor-later')?.click();
    await settle(rendered.fixture);

    expect(navigate).toHaveBeenCalledWith('/account?tab=security');
  });

  it('leaves an account a company requires to use it only the way out of signing out', async () => {
    const rendered = await render({
      enrolled: false,
      required: true,
      totp: false,
      passkeys: 0,
      recoveryCodes: 0,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });

    expect(rendered.query('two-factor-later')).toBeNull();
    expect(rendered.query('two-factor-logout')).not.toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-22 09:12: every factor is manageable, the authenticator included.
  describe('moving the authenticator to a new app', () => {
    const lone: MfaStatus = {
      enrolled: true,
      required: true,
      totp: true,
      passkeys: 0,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    };

    async function replaceWith(rendered: Awaited<ReturnType<typeof render>>, code: string) {
      const input = rendered.query<HTMLInputElement>('two-factor-replace-code')!;
      input.value = code;
      input.dispatchEvent(new Event('input'));
      rendered
        .query<HTMLFormElement>('two-factor-replace-form')
        ?.dispatchEvent(new Event('submit'));
      await settle(rendered.fixture);
    }

    it('moves an account whose only factor it is to a new app, never removing the one in force', async () => {
      const rendered = await render(lone);
      facade.confirmTotpReplacement.mockResolvedValue({
        ok: true,
        recoveryCodes: ['iiiii-jjjjj', 'kkkkk-lllll'],
      });

      rendered.query<HTMLButtonElement>('two-factor-app-replace')?.click();
      await settle(rendered.fixture);
      expect(facade.beginTotpReplacement).toHaveBeenCalledTimes(1);
      expect(rendered.query('two-factor-replace-qr')).not.toBeNull();
      expect(rendered.query('two-factor-replace-secret')?.textContent?.trim()).toBe(
        'KRSX G5CT MVRX EZLU',
      );
      await replaceWith(rendered, ' 654321 ');

      expect(facade.confirmTotpReplacement).toHaveBeenCalledWith('654321');
      const codes = [
        ...(rendered.query('two-factor-recovery-codes')?.querySelectorAll('li') ?? []),
      ];
      expect(codes.map((item) => item.textContent?.trim())).toEqual(['iiiii-jjjjj', 'kkkkk-lllll']);
      expect(facade.removeAuthenticator).not.toHaveBeenCalled();
    });

    it('says a refused code and keeps the new secret on screen to try again', async () => {
      const rendered = await render(lone);
      facade.confirmTotpReplacement.mockResolvedValue({ ok: false, error: 'invalid_code' });
      rendered.query<HTMLButtonElement>('two-factor-app-replace')?.click();
      await settle(rendered.fixture);

      await replaceWith(rendered, '000000');

      expect(rendered.query('two-factor-replace-error')?.textContent).toContain('Code incorrect');
      expect(rendered.query('two-factor-replace-qr')).not.toBeNull();
      expect(rendered.query<HTMLInputElement>('two-factor-replace-code')?.value).toBe('');
      expect(rendered.query('two-factor-recovery-codes')).toBeNull();
    });

    it('asks who is at the screen first, and starts nothing when the proof is declined', async () => {
      const rendered = await render(lone);
      facade.beginTotpReplacement.mockResolvedValue({ ok: false, error: 'step_up_required' });
      stepUp.request.mockResolvedValue(false);

      rendered.query<HTMLButtonElement>('two-factor-app-replace')?.click();
      await settle(rendered.fixture);

      expect(stepUp.request).toHaveBeenCalledTimes(1);
      expect(rendered.query('two-factor-replace-qr')).toBeNull();
      expect(rendered.query('two-factor-replace-error')).toBeNull();
    });

    it('goes back to the app in force when the new one is not set up after all', async () => {
      const rendered = await render(lone);
      rendered.query<HTMLButtonElement>('two-factor-app-replace')?.click();
      await settle(rendered.fixture);

      rendered.query<HTMLButtonElement>('two-factor-replace-cancel')?.click();
      await settle(rendered.fixture);

      expect(rendered.query('two-factor-replace-qr')).toBeNull();
      expect(rendered.query('two-factor-app-replace')).not.toBeNull();
      expect(facade.confirmTotpReplacement).not.toHaveBeenCalled();
    });
  });

  it('removes the authenticator, and offers to set one up again', async () => {
    const rendered = await render(
      {
        enrolled: true,
        required: false,
        totp: true,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [laptop],
    );
    // Required meanwhile, from another tab or by an owner: the API's refusal is said under the button.
    facade.removeAuthenticator.mockResolvedValueOnce({
      ok: false,
      error: 'mfa_authenticator_required',
    });
    rendered.query<HTMLButtonElement>('two-factor-app-remove')?.click();
    await settle(rendered.fixture);
    expect(rendered.query('two-factor-app-remove-error')?.textContent).toContain(
      'Exigée par une entreprise',
    );
    expect(rendered.query('two-factor-error')).toBeNull();

    facade.removeAuthenticator.mockImplementation(async () => {
      facade.me.set(
        signedIn({
          enrolled: true,
          required: false,
          totp: false,
          passkeys: 1,
          recoveryCodes: 10,
          passkeySignIn: false,
          passkeySignInAllowed: false,
        }),
      );
      return { ok: true };
    });
    rendered.query<HTMLButtonElement>('two-factor-app-remove')?.click();
    await settle(rendered.fixture);

    expect(facade.removeAuthenticator).toHaveBeenCalledTimes(2);
    expect(rendered.query('two-factor-app-remove')).toBeNull();
    expect(rendered.query('two-factor-app-remove-error')).toBeNull();
    expect(rendered.query('two-factor-app-start')).not.toBeNull();
    expect(facade.beginTotpEnrolment).not.toHaveBeenCalled();
  });

  // While a second factor is required the authenticator is replaced, never removed, a passkey beside it or not.
  it('offers only to replace an authenticator a second factor requires, and says why', async () => {
    const rendered = await render(
      {
        enrolled: true,
        required: true,
        totp: true,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [laptop],
    );

    expect(rendered.query('two-factor-app-remove')).toBeNull();
    expect(rendered.query('two-factor-app-remove-required')?.textContent).toContain(
      'Exigée : seulement remplacée',
    );
    expect(rendered.query('two-factor-app-replace')).not.toBeNull();

    // Read live: the requirement lifted, removing is offered; required again, it is withdrawn.
    facade.me.set(
      signedIn({
        enrolled: true,
        required: false,
        totp: true,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      }),
    );
    await settle(rendered.fixture);
    expect(rendered.query('two-factor-app-remove')).not.toBeNull();
    expect(rendered.query('two-factor-app-remove-required')).toBeNull();

    facade.me.set(
      signedIn({
        enrolled: true,
        required: true,
        totp: true,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      }),
    );
    await settle(rendered.fixture);
    expect(rendered.query('two-factor-app-remove')).toBeNull();
    expect(facade.removeAuthenticator).not.toHaveBeenCalled();
  });

  it('starts the set-up again once the authenticator, the last factor, is removed', async () => {
    const rendered = await render({
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 0,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });
    facade.removeAuthenticator.mockImplementation(async () => {
      facade.me.set(
        signedIn({
          enrolled: false,
          required: false,
          totp: false,
          passkeys: 0,
          recoveryCodes: 0,
          passkeySignIn: false,
          passkeySignInAllowed: false,
        }),
      );
      return { ok: true };
    });

    rendered.query<HTMLButtonElement>('two-factor-app-remove')?.click();
    await settle(rendered.fixture);

    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
    expect(rendered.query('two-factor-qr')).not.toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-26 23:04: what cannot be undone says so first, and keeping it changes nothing.
  it('asks before removing either factor, names the passkey, and keeps both when kept', async () => {
    const rendered = await render(
      {
        enrolled: true,
        required: false,
        totp: true,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [laptop],
    );
    dialog.open.mockReturnValue({ afterClosed: () => of(false) });

    rendered.query<HTMLButtonElement>('two-factor-app-remove')?.click();
    await settle(rendered.fixture);
    rendered.query<HTMLButtonElement>('two-factor-passkey-remove')?.click();
    await settle(rendered.fixture);

    expect(dialog.open.mock.calls.map(([, config]) => config.data)).toEqual([
      expect.objectContaining({ kind: 'definitif', title: 'auth.two_factor.app_remove_title' }),
      expect.objectContaining({
        kind: 'definitif',
        title: 'auth.two_factor.passkey_remove_title',
        messageParams: { name: 'Work laptop' },
      }),
    ]);
    expect(facade.removeAuthenticator).not.toHaveBeenCalled();
    expect(facade.removePasskey).not.toHaveBeenCalled();
    expect(stepUp.request).not.toHaveBeenCalled();
    expect(rendered.query('two-factor-app-remove')).not.toBeNull();
    expect(listed(rendered)).toEqual(['Work laptop']);
  });

  it('removes a passkey from the list, and says why the last one stays', async () => {
    const rendered = await render(
      {
        enrolled: true,
        required: true,
        totp: false,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [laptop],
    );
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
  it('asks who is at the screen before starting an authenticator, then starts it', async () => {
    const { query } = await render(
      {
        enrolled: false,
        required: true,
        totp: false,
        passkeys: 0,
        recoveryCodes: 0,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [],
      (begin) =>
        begin
          .mockResolvedValueOnce({ ok: false, error: 'step_up_required' })
          .mockResolvedValue({ ok: true, enrolment: { secret: SECRET, provisioningUri: URI } }),
    );

    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_factors');
    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(2);
    expect(query('two-factor-secret')?.textContent?.replace(/\s/g, '')).toBe(SECRET);
  });

  it('adds a passkey once the proof is given, and changes nothing when it is declined', async () => {
    const rendered = await render(
      {
        enrolled: true,
        required: false,
        totp: true,
        passkeys: 1,
        recoveryCodes: 10,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [laptop],
    );
    facade.addPasskey
      .mockResolvedValueOnce({ ok: false, error: 'step_up_required' })
      .mockResolvedValue({
        ok: true,
        passkey: { ...laptop, id: 'p2', name: 'Phone' },
        recoveryCodes: [],
      });

    await addPasskey(rendered, 'Phone');

    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_factors');
    expect(facade.addPasskey).toHaveBeenCalledTimes(2);
    expect(listed(rendered)).toEqual(['Work laptop', 'Phone']);

    stepUp.request.mockResolvedValueOnce(false);
    facade.removePasskey.mockResolvedValue({ ok: false, error: 'step_up_required' });
    rendered.query<HTMLButtonElement>('two-factor-passkey-remove')?.click();
    await settle(rendered.fixture);

    expect(facade.removePasskey).toHaveBeenCalledTimes(1);
    expect(listed(rendered)).toEqual(['Work laptop', 'Phone']);
    expect(rendered.query('two-factor-passkey-error')).toBeNull();
  });
  it('offers to start again, saying nothing went wrong, when the proof is declined', async () => {
    const { query } = await render(
      {
        enrolled: false,
        required: true,
        totp: false,
        passkeys: 0,
        recoveryCodes: 0,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [],
      (begin, proof) => {
        begin.mockResolvedValue({ ok: false, error: 'step_up_required' });
        proof.mockResolvedValue(false);
      },
    );

    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
    expect(query('two-factor-error')).toBeNull();
    expect(query('two-factor-app-start')).not.toBeNull();
  });

  // One lost phone away from a locked account: said in words where the factors are managed, with what to do there.
  it('warns an account its authenticator alone holds, and leads to adding a passkey or new codes', async () => {
    const rendered = await render({
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 0,
      recoveryCodes: 2,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });

    expect(rendered.query('mfa-lockout-said')?.textContent).toContain('mfa_lockout.single_device');
    rendered.query<HTMLButtonElement>('mfa-lockout-add-passkey')?.click();
    await settle(rendered.fixture);
    expect(document.activeElement).toBe(rendered.query('two-factor-passkey-name'));

    rendered.query<HTMLButtonElement>('mfa-lockout-new-codes')?.click();
    await settle(rendered.fixture);
    expect(document.activeElement).toBe(rendered.query('two-factor-regenerate-code'));
  });

  it('asks the passkey for new codes at once when the account has no authenticator', async () => {
    const rendered = await render(
      {
        enrolled: true,
        required: true,
        totp: false,
        passkeys: 1,
        recoveryCodes: 0,
        passkeySignIn: false,
        passkeySignInAllowed: false,
      },
      [{ id: 'p1', name: 'Work laptop', createdAt: '2026-10-01T09:00:00+00:00', lastUsedAt: null }],
    );
    facade.regenerateRecoveryCodesWithPasskey.mockResolvedValue({ ok: true, recoveryCodes: ['x'] });

    expect(rendered.query('mfa-lockout-required')?.textContent).toContain('mfa_lockout.required');
    rendered.query<HTMLButtonElement>('mfa-lockout-new-codes')?.click();
    await settle(rendered.fixture);

    expect(facade.regenerateRecoveryCodesWithPasskey).toHaveBeenCalledTimes(1);
    expect(rendered.query('two-factor-recovery-codes')).not.toBeNull();
  });

  it('offers no passkey at an address that cannot use one, and says where they work', async () => {
    passkeyClient = { supported: () => false, elsewhere: () => 'https://localhost' };
    const { query } = await render({
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 0,
      recoveryCodes: 8,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });

    expect(query('two-factor-passkeys-elsewhere')?.textContent).toContain(
      'auth.two_factor.passkeys_elsewhere',
    );
    expect(query('two-factor-passkey-form')).toBeNull();
    expect(query('two-factor-passkeys-unsupported')).toBeNull();
    expect(query('mfa-lockout-said')?.textContent).toContain('mfa_lockout.single_device_elsewhere');
    expect(query('mfa-lockout-add-passkey')).toBeNull();
  });

  it('says nothing to an account that holds an authenticator, a passkey and its codes', async () => {
    const { query } = await render({
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 1,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: false,
    });

    expect(query('mfa-lockout')).toBeNull();
  });

  describe('the sign-in with a passkey alone', () => {
    const ready: MfaStatus = {
      enrolled: true,
      required: false,
      totp: true,
      passkeys: 1,
      recoveryCodes: 10,
      passkeySignIn: false,
      passkeySignInAllowed: true,
    };

    const said = () => (TestBed.inject(Feedback) as RecordedFeedback).said;

    /** What the facade does on a switch that went through: the session holds the new state. */
    function switches(): void {
      facade.switchPasskeySignIn.mockImplementation((enabled: boolean) => {
        const state = facade.me();
        if (state !== null) {
          facade.me.set({ ...state, mfa: { ...state.mfa, passkeySignIn: enabled } });
        }
        return Promise.resolve({ ok: true });
      });
    }

    it('switches it on once who is at the screen is proven, says so, and offers to switch it off', async () => {
      const rendered = await render(ready, [laptop]);
      switches();
      facade.switchPasskeySignIn.mockResolvedValueOnce({ ok: false, error: 'step_up_required' });

      expect(rendered.query('two-factor-passkey-sign-in-state')?.textContent).toContain(
        'auth.two_factor.passkey_sign_in.off',
      );
      rendered.query<HTMLButtonElement>('two-factor-passkey-sign-in-on')?.click();
      await settle(rendered.fixture);

      expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_factors');
      expect(facade.switchPasskeySignIn).toHaveBeenCalledTimes(2);
      expect(facade.switchPasskeySignIn).toHaveBeenLastCalledWith(true);
      expect(rendered.query('two-factor-passkey-sign-in-state')?.textContent).toContain(
        'auth.two_factor.passkey_sign_in.on',
      );
      expect(rendered.query('two-factor-passkey-sign-in-on')).toBeNull();
      expect(said()).toEqual([{ kind: 'success', key: 'auth.two_factor.passkey_sign_in.on_done' }]);

      rendered.query<HTMLButtonElement>('two-factor-passkey-sign-in-off')?.click();
      await settle(rendered.fixture);

      expect(facade.switchPasskeySignIn).toHaveBeenLastCalledWith(false);
      expect(rendered.query('two-factor-passkey-sign-in-on')).not.toBeNull();
      expect(said().map((toast) => toast.key)).toEqual([
        'auth.two_factor.passkey_sign_in.on_done',
        'auth.two_factor.passkey_sign_in.off_done',
      ]);
    });

    it('changes nothing and says nothing when the proof is declined', async () => {
      const rendered = await render(ready, [laptop]);
      stepUp.request.mockResolvedValue(false);
      facade.switchPasskeySignIn.mockResolvedValue({ ok: false, error: 'step_up_required' });

      rendered.query<HTMLButtonElement>('two-factor-passkey-sign-in-on')?.click();
      await settle(rendered.fixture);

      expect(facade.switchPasskeySignIn).toHaveBeenCalledTimes(1);
      expect(rendered.query('two-factor-passkey-sign-in-error')).toBeNull();
      expect(said()).toEqual([]);
    });

    it('says why the API refused to switch it on', async () => {
      const rendered = await render(ready, [laptop]);
      facade.switchPasskeySignIn.mockResolvedValue({
        ok: false,
        error: 'passkey_sign_in_needs_passkey',
      });

      rendered.query<HTMLButtonElement>('two-factor-passkey-sign-in-on')?.click();
      await settle(rendered.fixture);

      expect(rendered.query('two-factor-passkey-sign-in-error')?.textContent).toContain(
        'auth.two_factor.passkey_sign_in.refused.passkey_sign_in_needs_passkey',
      );
      expect(said()).toEqual([]);
    });

    it.each([
      ['no authenticator', { totp: false }],
      ['no passkey', { passkeys: 0 }],
    ])('offers no switch to an account with %s, and says what it takes', async (_, missing) => {
      const { query } = await render({ ...ready, ...missing }, []);

      expect(query('two-factor-passkey-sign-in-on')).toBeNull();
      expect(query('two-factor-passkey-sign-in-needs')?.textContent).toContain(
        'auth.two_factor.passkey_sign_in.needs',
      );
    });

    it('shows nothing where the installation does not allow it and it is off', async () => {
      const { query } = await render({ ...ready, passkeySignInAllowed: false }, [laptop]);

      expect(query('two-factor-passkey-sign-in')).toBeNull();
    });

    it('lets an account switch it off where it no longer works, and says why it does not', async () => {
      const { query } = await render(
        { ...ready, passkeySignIn: true, passkeySignInAllowed: false },
        [laptop],
      );

      expect(query('two-factor-passkey-sign-in-off')).not.toBeNull();
      expect(query('two-factor-passkey-sign-in-idle')?.textContent).toContain(
        'auth.two_factor.passkey_sign_in.idle',
      );
    });

    it('says one in force works as it should, with nothing more to do', async () => {
      const { query } = await render({ ...ready, passkeySignIn: true }, [laptop]);

      expect(query('two-factor-passkey-sign-in-idle')).toBeNull();
    });
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, signal } from '@angular/core';
import { AuthApi, AuthRefused } from './auth-api';
import type {
  AuthStatus,
  ConfirmationOutcome,
  Credentials,
  EnrolmentOutcome,
  LoginError,
  LoginOutcome,
  PasskeyAdded,
  PasskeyRemoved,
  PasskeysOutcome,
  SignedInState,
} from './auth-types';
import { PasskeyClient } from './passkey-client';

/**
 * The signed-in state, as signals. The session itself is a cookie the browser keeps; this facade only knows
 * what the API last said. Components depend on it and never on the API adapter.
 */
@Injectable({ providedIn: 'root' })
export class AuthFacade {
  private readonly api = inject(AuthApi);
  private readonly passkeyClient = inject(PasskeyClient);
  private readonly stateSignal = signal<SignedInState | null>(null);
  private readonly statusSignal = signal<AuthStatus>('unknown');

  readonly me = this.stateSignal.asReadonly();
  readonly status = this.statusSignal.asReadonly();
  readonly isAuthenticated = computed(() => this.statusSignal() === 'authenticated');
  /** A company the account belongs to requires a second factor and the account has none: nothing else works yet. */
  readonly needsEnrolment = computed(() => {
    const mfa = this.stateSignal()?.mfa;
    return mfa !== undefined && mfa.required && !mfa.enrolled;
  });

  /** Asks the API who the session belongs to. Any failure means "nobody": the guard sends the user to sign in. */
  async load(): Promise<SignedInState | null> {
    try {
      const state = await this.api.me();
      this.signedIn(state);
      return state;
    } catch {
      this.signedOut();
      return null;
    }
  }

  async login(credentials: Credentials): Promise<LoginOutcome> {
    try {
      const answer = await this.api.login(credentials);
      if (answer === 'second_factor') {
        // The password was right, but no session exists until the code is given.
        this.signedOut();
        return { status: 'second_factor' };
      }
      this.signedIn(answer);
      return { status: 'signed_in', state: answer };
    } catch (error) {
      this.signedOut();
      return { status: 'refused', error: codeOf(error) };
    }
  }

  async verifySecondFactor(code: string): Promise<LoginOutcome> {
    try {
      const state = await this.api.verifySecondFactor(code);
      this.signedIn(state);
      return { status: 'signed_in', state };
    } catch (error) {
      return { status: 'refused', error: codeOf(error) };
    }
  }

  async beginTotpEnrolment(): Promise<EnrolmentOutcome> {
    try {
      return { ok: true, enrolment: await this.api.beginTotpEnrolment() };
    } catch (error) {
      return { ok: false, error: codeOf(error) };
    }
  }

  async confirmTotpEnrolment(code: string): Promise<ConfirmationOutcome> {
    try {
      const recoveryCodes = await this.api.confirmTotpEnrolment(code);
      // The factor is in force from now on; the state says so without another round trip.
      this.stateSignal.update(
        (state) => state && { ...state, mfa: { ...state.mfa, enrolled: true, totp: true } },
      );
      return { ok: true, recoveryCodes };
    } catch (error) {
      return { ok: false, error: codeOf(error) };
    }
  }

  async regenerateRecoveryCodes(code: string): Promise<ConfirmationOutcome> {
    try {
      return { ok: true, recoveryCodes: await this.api.regenerateRecoveryCodes(code) };
    } catch (error) {
      return { ok: false, error: codeOf(error) };
    }
  }

  /** Asks the browser for one of the account's passkeys against the API's options, and replaces the codes with it. */
  async regenerateRecoveryCodesWithPasskey(): Promise<ConfirmationOutcome> {
    try {
      const options = await this.api.recoveryCodesPasskeyOptions();
      const credential = await fromBrowser(() => this.passkeyClient.get(options));
      return {
        ok: true,
        recoveryCodes: await this.api.regenerateRecoveryCodesWithPasskey(credential),
      };
    } catch (error) {
      return { ok: false, error: codeOf(error) };
    }
  }

  async listPasskeys(): Promise<PasskeysOutcome> {
    try {
      return { ok: true, passkeys: await this.api.listPasskeys() };
    } catch (error) {
      return { ok: false, error: codeOf(error) };
    }
  }

  /** Asks the browser for a new passkey against the API's options, then registers it as a factor. */
  async addPasskey(name: string): Promise<PasskeyAdded> {
    try {
      const options = await this.api.passkeyRegistrationOptions();
      const credential = await fromBrowser(() => this.passkeyClient.create(options));
      const added = await this.api.registerPasskey(name, credential);
      this.stateSignal.update(
        (state) =>
          state && {
            ...state,
            mfa: { ...state.mfa, enrolled: true, passkeys: state.mfa.passkeys + 1 },
          },
      );
      return { ok: true, ...added };
    } catch (error) {
      return { ok: false, error: codeOf(error) };
    }
  }

  async removePasskey(id: string): Promise<PasskeyRemoved> {
    try {
      await this.api.removePasskey(id);
      this.stateSignal.update((state) => {
        if (state === null) {
          return state;
        }
        const passkeys = Math.max(0, state.mfa.passkeys - 1);
        return {
          ...state,
          mfa: { ...state.mfa, passkeys, enrolled: state.mfa.totp || passkeys > 0 },
        };
      });
      return { ok: true };
    } catch (error) {
      return { ok: false, error: codeOf(error) };
    }
  }

  /** The second half of a login with a passkey instead of a code. */
  async signInWithPasskey(): Promise<LoginOutcome> {
    try {
      const options = await this.api.passkeyLoginOptions();
      const credential = await fromBrowser(() => this.passkeyClient.get(options));
      const state = await this.api.finishPasskeyLogin(credential);
      this.signedIn(state);
      return { status: 'signed_in', state };
    } catch (error) {
      return { status: 'refused', error: codeOf(error) };
    }
  }

  async logout(): Promise<void> {
    try {
      await this.api.logout();
    } catch {
      // Whatever the API answered, the client forgets the session; a stale cookie fails the next request anyway.
    }
    this.signedOut();
  }

  hasPermission(permission: string): boolean {
    const permissions = this.stateSignal()?.permissions ?? [];
    return permissions.includes('*') || permissions.includes(permission);
  }

  /** Whether the working company has the module on: its navigation and pages are offered only then. */
  hasModule(module: string): boolean {
    return this.stateSignal()?.modules.includes(module) ?? false;
  }

  private signedIn(state: SignedInState): void {
    this.stateSignal.set(state);
    this.statusSignal.set('authenticated');
  }

  private signedOut(): void {
    this.stateSignal.set(null);
    this.statusSignal.set('anonymous');
  }
}

/**
 * The browser's refusals come down to two things a person can act on: this device already holds a passkey for the
 * account (WebAuthn's excludeCredentials, reported as InvalidStateError), or no passkey was used at all.
 */
async function fromBrowser<T>(ceremony: () => Promise<T>): Promise<T> {
  try {
    return await ceremony();
  } catch (error) {
    throw new AuthRefused(
      error instanceof DOMException && error.name === 'InvalidStateError'
        ? 'passkey_already_registered'
        : 'passkey_cancelled',
    );
  }
}

function codeOf(error: unknown): LoginError {
  return error instanceof AuthRefused ? error.code : 'network';
}

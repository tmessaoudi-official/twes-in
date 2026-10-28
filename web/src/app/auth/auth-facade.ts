// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, Injector, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { RequestActivity } from '../shared/feedback/request-activity';
import { Session } from '../shared/session/session';
import { SessionData } from '../shared/session/session-data';
import { CustomerScreenHold } from '../shared/customer-view/customer-screen-hold';
import { type StepUpOutcome, StepUpProof } from '../shared/step-up/step-up-proof';
import { AuthApi, AuthRefused } from './auth-api';
import { ALWAYS_PERMITTED } from './auth-types';
import type {
  AuthenticatorRemoved,
  AuthStatus,
  CompanyAccess,
  ConfirmationOutcome,
  Credentials,
  DemoAvailability,
  EnrolmentOutcome,
  LoginError,
  LoginOutcome,
  PasskeyAdded,
  PasskeyRemoved,
  PasskeysOutcome,
  PasswordChangeOutcome,
  SignedInState,
} from './auth-types';
import { PasskeyClient } from './passkey-client';
import { refusedFor, saidAlready } from '../shared/errors/said-already';

/**
 * The signed-in state, as signals. The session itself is a cookie the browser keeps; this facade only knows
 * what the API last said. Components depend on it and never on the API adapter.
 */
@Injectable({ providedIn: 'root' })
export class AuthFacade implements Session, StepUpProof, CustomerScreenHold {
  private readonly api = inject(AuthApi);
  private readonly passkeyClient = inject(PasskeyClient);
  private readonly unsaved = inject(UnsavedChanges);
  /** Told every session this holds, so what the facades kept for the last one is forgotten with it. */
  private readonly session = inject(SessionData);
  /** Read only when a session ends: the request activity needs the toasts, which the session itself never does. */
  private readonly injector = inject(Injector);
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
  /**
   * The working company is shut, pending an operator's approval, suspended by one or locked by its subscription: the API
   * refuses its members everything in it, so the application shows why instead. An operator is not held back, since
   * approving is their job. A company its owner closed down is not shut: it is kept, and read in the shell.
   */
  readonly companyClosed = computed(() => {
    const state = this.stateSignal();
    const company = state?.company;
    const closed =
      !!company &&
      ((company.status !== 'active' && company.status !== 'closed') || 'locked' === company.access);

    return closed && !state.user.isPlatformOperator;
  });

  /** The working company was closed down by its owner: read-only to its members until its records' retention ends. */
  readonly companyClosedDown = computed(() => this.stateSignal()?.company?.status === 'closed');

  /** Where the working company stands in its subscription, null when licensing does not manage it. */
  readonly subscription = computed(() => this.stateSignal()?.company?.subscription ?? null);

  /** What the working company's subscription lets its members do; full without one. */
  readonly access = computed(() => this.stateSignal()?.company?.access ?? 'full');

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

  /**
   * Reads the session again after another person changed what it holds (its role, the company's modules, the company
   * itself: docs/SPEC.md § 7, 2026-09-17). Unlike load(), a failure keeps the session: a moment without the API is not
   * a sign-out, and a session that really ended is noticed by the activity interceptor.
   */
  async refresh(): Promise<void> {
    try {
      this.signedIn(await this.api.me());
    } catch {
      // Kept as it was.
    }
  }

  /**
   * Asked when the API says a session of the person ended somewhere: one ended here goes to the sign-in page at once,
   * as an expired one does, rather than at the next click; one still standing carries on, its state read again.
   */
  async checkSession(): Promise<void> {
    try {
      this.signedIn(await this.api.me());
    } catch (error) {
      if (error instanceof AuthRefused && error.code === 'authentication_required')
        this.injector.get(RequestActivity).sessionEnded();
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

  /** « Essayer la démo »: signed in, with no account, in a sandbox of the country picked. */
  async enterDemo(country: string): Promise<LoginOutcome> {
    try {
      const state = await this.api.enterDemo(country);
      this.signedIn(state);
      return { status: 'signed_in', state };
    } catch (error) {
      return { status: 'refused', error: codeOf(error) };
    }
  }

  /** Whether the sign-in page offers the demo; closed when the API cannot say. */
  async demoAvailability(): Promise<DemoAvailability> {
    try {
      return await this.api.demoAvailability();
    } catch {
      return { enabled: false, countries: [] };
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
      return { ok: false, error: shownOf(error) };
    }
  }

  async confirmTotpEnrolment(code: string): Promise<ConfirmationOutcome> {
    try {
      const recoveryCodes = await this.api.confirmTotpEnrolment(code);
      // The factor is in force from now on; the state says so without another round trip.
      this.stateSignal.update(
        (state) =>
          state && {
            ...state,
            mfa: { ...state.mfa, enrolled: true, totp: true, recoveryCodes: recoveryCodes.length },
          },
      );
      return { ok: true, recoveryCodes };
    } catch (error) {
      return { ok: false, error: shownOf(error) };
    }
  }

  /** Moving the authenticator to a new app: its secret, waiting while the app in force keeps signing in. */
  async beginTotpReplacement(): Promise<EnrolmentOutcome> {
    try {
      return { ok: true, enrolment: await this.api.beginTotpReplacement() };
    } catch (error) {
      return { ok: false, error: shownOf(error) };
    }
  }

  /** The new app in force, and its new recovery codes counted at once, as a new set is. */
  async confirmTotpReplacement(code: string): Promise<ConfirmationOutcome> {
    try {
      return { ok: true, recoveryCodes: this.counted(await this.api.confirmTotpReplacement(code)) };
    } catch (error) {
      return { ok: false, error: shownOf(error) };
    }
  }

  async regenerateRecoveryCodes(code: string): Promise<ConfirmationOutcome> {
    try {
      return {
        ok: true,
        recoveryCodes: this.counted(await this.api.regenerateRecoveryCodes(code)),
      };
    } catch (error) {
      return { ok: false, error: shownOf(error) };
    }
  }

  /** Asks the browser for one of the account's passkeys against the API's options, and replaces the codes with it. */
  async regenerateRecoveryCodesWithPasskey(): Promise<ConfirmationOutcome> {
    try {
      const options = await this.api.recoveryCodesPasskeyOptions();
      const credential = await fromBrowser(() => this.passkeyClient.get(options));
      return {
        ok: true,
        recoveryCodes: this.counted(await this.api.regenerateRecoveryCodesWithPasskey(credential)),
      };
    } catch (error) {
      return { ok: false, error: shownOf(error) };
    }
  }

  /** A new set replaces every code left: the lock-out warning counts it at once, without another round trip. */
  private counted(codes: string[]): string[] {
    this.stateSignal.update(
      (state) => state && { ...state, mfa: { ...state.mfa, recoveryCodes: codes.length } },
    );
    return codes;
  }

  /** Whether a passkey ceremony can be answered here: by this browser, at this page's address. */
  passkeySupported(): boolean {
    return this.passkeyClient.supported();
  }

  /**
   * A new password for the signed-in account. It ends every session, this one too, so a change made here forgets the
   * session at once and the person signs in again with the new password.
   */
  async changePassword(
    currentPassword: string,
    newPassword: string,
  ): Promise<PasswordChangeOutcome | null> {
    try {
      await this.api.changePassword(currentPassword, newPassword);
      this.signedOut();
      return 'changed';
    } catch (error) {
      if (saidAlready(error)) return null;
      const code = codeOf(error);
      switch (code) {
        case 'current_password':
        case 'too_short':
        case 'unchanged':
        case 'breached':
        case 'network':
          return code;
        case 'too_many_attempts':
          return 'too_many';
        default:
          return 'refused';
      }
    }
  }

  async withPassword(password: string): Promise<StepUpOutcome> {
    try {
      await this.api.stepUpWithPassword(password);
      return 'confirmed';
    } catch (error) {
      return this.stepUpRefused(error);
    }
  }

  async withPasskey(): Promise<StepUpOutcome> {
    try {
      const options = await this.api.stepUpPasskeyOptions();
      const credential = await fromBrowser(() => this.passkeyClient.get(options));
      await this.api.stepUpWithPasskey(credential);
      return 'confirmed';
    } catch (error) {
      return this.stepUpRefused(error);
    }
  }

  async hold(companyId: string): Promise<boolean> {
    try {
      await this.api.lockCustomerScreen(companyId);
    } catch {
      return false;
    }
    await this.refresh();
    return true;
  }

  async release(): Promise<boolean> {
    try {
      await this.api.leaveCustomerScreen();
    } catch {
      return false;
    }
    await this.refresh();
    return true;
  }

  reread(): Promise<void> {
    return this.refresh();
  }

  async listPasskeys(): Promise<PasskeysOutcome> {
    try {
      return { ok: true, passkeys: await this.api.listPasskeys() };
    } catch (error) {
      return { ok: false, error: shownOf(error) };
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
            mfa: {
              ...state.mfa,
              enrolled: true,
              passkeys: state.mfa.passkeys + 1,
              // The account's first factor issues its codes; a later passkey leaves them as they were.
              recoveryCodes:
                added.recoveryCodes.length > 0
                  ? added.recoveryCodes.length
                  : state.mfa.recoveryCodes,
            },
          },
      );
      return { ok: true, ...added };
    } catch (error) {
      return { ok: false, error: shownOf(error) };
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
      return { ok: false, error: shownOf(error) };
    }
  }

  async removeAuthenticator(): Promise<AuthenticatorRemoved> {
    try {
      await this.api.removeAuthenticator();
      this.stateSignal.update((state) =>
        state === null
          ? state
          : {
              ...state,
              mfa: { ...state.mfa, totp: false, enrolled: state.mfa.passkeys > 0 },
            },
      );
      return { ok: true };
    } catch (error) {
      return { ok: false, error: shownOf(error) };
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

  /**
   * Returns whether the session ended. Signing out takes every screen down before any route is left, so the leave
   * question is asked here, for every way out, before what was typed is lost.
   */
  async logout(): Promise<boolean> {
    if (!(await firstValueFrom(this.unsaved.confirmLeaveBeforeNavigating()))) return false;
    try {
      await this.api.logout();
    } catch {
      // Whatever the API answered, the client forgets the session; a stale cookie fails the next request anyway.
    }
    this.signedOut();
    return true;
  }

  /** The API no longer knows the session (it expired, or was ended elsewhere): forget it here too, without asking. */
  sessionEnded(): void {
    this.signedOut();
  }

  /** Whether the signed-in account runs the platform: operator scope sits outside every company's permissions. */
  isPlatformOperator(): boolean {
    return this.stateSignal()?.user.isPlatformOperator ?? false;
  }

  /**
   * What the person may do here: their role's grants, narrowed by the working company's subscription. The narrowing
   * judges the permission asked for, never the grant, so an owner's "*" passes nothing an unpaid subscription
   * refuses — the same rule the API enforces, applied here so screens offer only what would be accepted.
   */
  hasPermission(permission: string): boolean {
    return (
      this.grantedByRole(permission) &&
      permittedBy(this.stateSignal()?.company?.access ?? 'full', permission)
    );
  }

  /**
   * Whether the person's role grants the permission, whatever the subscription allows: a page refused for the
   * subscription says so, rather than blaming the role.
   */
  grantedByRole(permission: string): boolean {
    const permissions = this.stateSignal()?.permissions ?? [];
    return permissions.includes('*') || permissions.includes(permission);
  }

  /** Whether the working company has the module on: its navigation and pages are offered only then. */
  hasModule(module: string): boolean {
    return this.stateSignal()?.modules.includes(module) ?? false;
  }

  private signedIn(state: SignedInState): void {
    this.session.heard(state);
    this.stateSignal.set(state);
    this.statusSignal.set('authenticated');
  }

  /** A refused proof; one that ended the session leaves the app signed out with it. */
  private stepUpRefused(error: unknown): StepUpOutcome {
    const outcome = stepUpOutcome(error);
    if (outcome === 'signed_out') this.signedOut();
    return outcome;
  }

  private signedOut(): void {
    this.session.heard(null);
    this.stateSignal.set(null);
    this.statusSignal.set('anonymous');
  }
}

/**
 * The browser's refusals come down to three things a person can act on: this device already holds a passkey for the
 * account (WebAuthn's excludeCredentials, reported as InvalidStateError), this page's address cannot use the
 * installation's passkeys (SecurityError: they are bound to another host), or no passkey was used at all.
 */
async function fromBrowser<T>(ceremony: () => Promise<T>): Promise<T> {
  try {
    return await ceremony();
  } catch (error) {
    throw refusedFor(error, new AuthRefused(browserRefusal(error)));
  }
}

function browserRefusal(error: unknown): LoginError {
  if (!(error instanceof DOMException)) return 'passkey_cancelled';
  if (error.name === 'InvalidStateError') return 'passkey_already_registered';
  return error.name === 'SecurityError' ? 'passkey_not_here' : 'passkey_cancelled';
}

/** What the dialog says about a proof that did not hold; a passkey the account lacks and one the browser did not give read alike. */
function stepUpOutcome(error: unknown): StepUpOutcome {
  switch (codeOf(error)) {
    case 'too_many_attempts':
      return 'too_many';
    case 'step_up_exhausted':
      return 'signed_out';
    case 'network':
      return 'network';
    case 'invalid_passkey':
    case 'passkey_cancelled':
    case 'passkey_not_here':
      return 'no_passkey';
    default:
      return 'refused';
  }
}

function codeOf(error: unknown): LoginError {
  return error instanceof AuthRefused ? error.code : 'network';
}

/** What a refused step says on its screen: nothing when an interceptor said it already (`saidAlready`). */
function shownOf(error: unknown): LoginError | null {
  return saidAlready(error) ? null : codeOf(error);
}

/** The rule App\Licensing\Domain\Access enforces, mirrored so a screen offers only what the API would accept. */
function permittedBy(access: CompanyAccess, permission: string): boolean {
  if ('full' === access) return true;
  if (ALWAYS_PERMITTED.includes(permission)) return true;

  return 'read_only' === access && permission.endsWith('.read');
}

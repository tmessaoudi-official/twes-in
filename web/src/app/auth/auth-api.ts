// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom, Observable } from 'rxjs';
import type {
  AuthError,
  LoginRequest,
  Me,
  MeSubscription,
  MfaCode,
  MfaEnrolment,
  MfaPending,
  MfaRecoveryCodes,
  Passkey,
  PasskeyAssertion,
  PasskeyList,
  PasskeyRegistered,
  PasskeyRegistration,
  PublicKeyCredentialOptionsJson,
  StepUpPassword,
} from '../api/types.gen';
import type {
  CompanyAccess,
  CompanySubscription,
  Credentials,
  LoginError,
  PasskeyCredential,
  PasskeyOptions,
  PasskeySummary,
  SignedInState,
  TotpEnrolment,
} from './auth-types';

const ACCESSES: readonly CompanyAccess[] = ['full', 'read_only', 'locked'];
const STAGES: readonly CompanySubscription['stage'][] = [
  'trial',
  'paid',
  'grace',
  'held',
  'unpaid',
];

/** Thrown by the adapter when the API refuses; carries the stable error code the API answered with. */
export class AuthRefused extends Error {
  constructor(readonly code: LoginError) {
    super(code);
  }
}

/** The HTTP edge of the auth feature: the only code that knows the endpoints and the generated types. */
@Injectable({ providedIn: 'root' })
export class AuthApi {
  private readonly http = inject(HttpClient);

  /** Who the session belongs to; rejects with AuthRefused("authentication_required") when nobody. */
  async me(): Promise<SignedInState> {
    return toState(await send(this.http.get<Me>('/api/auth/me')));
  }

  /** Signs in, or answers "second_factor" when the password was right and a code is still owed. */
  async login(credentials: Credentials): Promise<SignedInState | 'second_factor'> {
    const body: LoginRequest = { email: credentials.email, password: credentials.password };
    const answer = await send(this.http.post<Me | MfaPending>('/api/auth/login', body));
    return 'mfaRequired' in answer ? 'second_factor' : toState(answer);
  }

  /** The second half of a login: a six-digit code, or a recovery code. */
  async verifySecondFactor(code: string): Promise<SignedInState> {
    const body: MfaCode = { code };
    return toState(await send(this.http.post<Me>('/api/auth/mfa/verify', body)));
  }

  async beginTotpEnrolment(): Promise<TotpEnrolment> {
    const answer = await send(this.http.post<MfaEnrolment>('/api/auth/mfa/enrolment', {}));
    return { secret: answer.secret, provisioningUri: answer.provisioningUri };
  }

  /** Puts the pending authenticator in force; the recovery codes come back this once. */
  async confirmTotpEnrolment(code: string): Promise<string[]> {
    const body: MfaCode = { code };
    const answer = await send(
      this.http.post<MfaRecoveryCodes>('/api/auth/mfa/enrolment/confirm', body),
    );
    return [...answer.recoveryCodes];
  }

  /** A new set of recovery codes, proven by a current authenticator code; every earlier code stops working. */
  async regenerateRecoveryCodes(code: string): Promise<string[]> {
    const body: MfaCode = { code };
    const answer = await send(
      this.http.post<MfaRecoveryCodes>('/api/auth/mfa/recovery-codes', body),
    );
    return [...answer.recoveryCodes];
  }

  /** Request options naming the account's passkeys, to replace the recovery codes against one. */
  async recoveryCodesPasskeyOptions(): Promise<PasskeyOptions> {
    return {
      ...(await send(
        this.http.post<PublicKeyCredentialOptionsJson>(
          '/api/auth/mfa/recovery-codes/passkey/options',
          {},
        ),
      )),
    };
  }

  /** A new set of recovery codes, proven by one of the account's passkeys; every earlier code stops working. */
  async regenerateRecoveryCodesWithPasskey(credential: PasskeyCredential): Promise<string[]> {
    const body: PasskeyAssertion = { credential };
    const answer = await send(
      this.http.post<MfaRecoveryCodes>('/api/auth/mfa/recovery-codes/passkey', body),
    );
    return [...answer.recoveryCodes];
  }

  /** Replaces the password; the API then ends every session, this one included. Rejects with the reason it was refused. */
  async changePassword(currentPassword: string, newPassword: string): Promise<void> {
    await send(this.http.put<void>('/api/auth/password', { currentPassword, newPassword }));
  }

  /** Confirms who is at the screen with the account's password; rejects when it is not the account's. */
  async stepUpWithPassword(password: string): Promise<void> {
    const body: StepUpPassword = { password };
    await send(this.http.post<void>('/api/auth/step-up', body));
  }

  /** Request options naming the account's passkeys, to confirm who is at the screen against one. */
  async stepUpPasskeyOptions(): Promise<PasskeyOptions> {
    return {
      ...(await send(
        this.http.post<PublicKeyCredentialOptionsJson>('/api/auth/step-up/passkey/options', {}),
      )),
    };
  }

  /** Confirms who is at the screen with one of the account's passkeys; rejects when it does not verify. */
  async stepUpWithPasskey(credential: PasskeyCredential): Promise<void> {
    const body: PasskeyAssertion = { credential };
    await send(this.http.post<void>('/api/auth/step-up/passkey', body));
  }

  /** Creation options for a new passkey; the API keeps them to verify the answer against, once. */
  async passkeyRegistrationOptions(): Promise<PasskeyOptions> {
    return {
      ...(await send(
        this.http.post<PublicKeyCredentialOptionsJson>('/api/auth/mfa/passkeys/options', {}),
      )),
    };
  }

  /** Registers what the browser created; recovery codes come back only when it is the account's first factor. */
  async registerPasskey(
    name: string,
    credential: PasskeyCredential,
  ): Promise<{ passkey: PasskeySummary; recoveryCodes: string[] }> {
    const body: PasskeyRegistration = { name, credential };
    const answer = await send(this.http.post<PasskeyRegistered>('/api/auth/mfa/passkeys', body));
    return { passkey: toPasskey(answer.passkey), recoveryCodes: [...answer.recoveryCodes] };
  }

  async listPasskeys(): Promise<PasskeySummary[]> {
    const answer = await send(this.http.get<PasskeyList>('/api/auth/mfa/passkeys'));
    return answer.passkeys.map(toPasskey);
  }

  async removePasskey(id: string): Promise<void> {
    await send(this.http.delete(`/api/auth/mfa/passkeys/${encodeURIComponent(id)}`));
  }

  /** Request options for the account whose password was just accepted. */
  async passkeyLoginOptions(): Promise<PasskeyOptions> {
    return {
      ...(await send(
        this.http.post<PublicKeyCredentialOptionsJson>('/api/auth/mfa/passkey-login/options', {}),
      )),
    };
  }

  /** The second half of a login, paid with a passkey instead of a code. */
  async finishPasskeyLogin(credential: PasskeyCredential): Promise<SignedInState> {
    const body: PasskeyAssertion = { credential };
    return toState(await send(this.http.post<Me>('/api/auth/mfa/passkey-login', body)));
  }

  /** Holds the sign-in on this company's customer screen: until it is left, the API answers only the screen. */
  async lockCustomerScreen(companyId: string): Promise<void> {
    await send(this.http.post<void>(`/api/companies/${companyId}/customer-screen/lock`, null));
  }

  /** Lets go of the customer screen; rejects with step_up_required without a fresh proof of who is at it. */
  async leaveCustomerScreen(): Promise<void> {
    await send(this.http.delete<void>('/api/auth/customer-screen'));
  }

  async logout(): Promise<void> {
    await firstValueFrom(this.http.post('/api/auth/logout', null));
  }
}

async function send<T>(request: Observable<T>): Promise<T> {
  try {
    return await firstValueFrom(request);
  } catch (error) {
    throw new AuthRefused(codeOf(error));
  }
}

function toState(me: Me): SignedInState {
  return {
    user: {
      id: me.user.id,
      email: me.user.email,
      displayName: me.user.displayName,
      locale: me.user.locale,
      isPlatformOperator: me.user.isPlatformOperator,
    },
    company:
      me.company === null
        ? null
        : {
            id: me.company.id,
            name: me.company.name,
            countryCode: me.company.countryCode,
            currency: me.company.currency,
            locale: me.company.locale,
            timezone: me.company.timezone,
            status: me.company.status,
            role: me.company.role,
            access: toAccess(me.company.access),
            subscription: toSubscription(me.company.subscription),
          },
    permissions: [...me.permissions],
    modules: [...me.modules],
    plannedModules: me.plannedModules.map((module) => ({
      key: module.key,
      planned: module.planned,
    })),
    customerScreenCompanyId: me.customerScreenCompanyId,
    mfa: {
      enrolled: me.mfa.enrolled,
      required: me.mfa.required,
      totp: me.mfa.totp,
      passkeys: me.mfa.passkeys,
    },
  };
}

function toPasskey(passkey: Passkey): PasskeySummary {
  return {
    id: passkey.id,
    name: passkey.name,
    createdAt: passkey.createdAt,
    lastUsedAt: passkey.lastUsedAt,
  };
}

function codeOf(error: unknown): LoginError {
  if (error instanceof HttpErrorResponse && error.status > 0) {
    const body = error.error as Partial<AuthError> | null;
    return body?.error ?? 'invalid_credentials';
  }
  return 'network';
}

/** An access the API does not name is read as full: the API enforces it whatever the SPA believes. */
function toAccess(access: string | undefined): CompanyAccess {
  return ACCESSES.find((known) => known === access) ?? 'full';
}

function toSubscription(raw: MeSubscription | null | undefined): CompanySubscription | null {
  if (raw === null || raw === undefined) return null;
  return {
    stage: STAGES.find((known) => known === raw.stage) ?? 'unpaid',
    coveredUntil: raw.coveredUntil,
    graceEndsAt: raw.graceEndsAt,
    daysLeft: raw.daysLeft,
  };
}

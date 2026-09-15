// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom, Observable } from 'rxjs';
import type {
  AuthError,
  LoginRequest,
  Me,
  MfaCode,
  MfaEnrolment,
  MfaPending,
  MfaRecoveryCodes,
} from '../api/types.gen';
import type { Credentials, LoginError, SignedInState, TotpEnrolment } from './auth-types';

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
          },
    permissions: [...me.permissions],
    modules: [...me.modules],
    mfa: { enrolled: me.mfa.enrolled, required: me.mfa.required },
  };
}

function codeOf(error: unknown): LoginError {
  if (error instanceof HttpErrorResponse && error.status > 0) {
    const body = error.error as Partial<AuthError> | null;
    return body?.error ?? 'invalid_credentials';
  }
  return 'network';
}

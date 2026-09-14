// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { AuthError, LoginRequest, Me } from '../api/types.gen';
import type { Credentials, LoginError, SignedInState } from './auth-types';

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
    try {
      return toState(await firstValueFrom(this.http.get<Me>('/api/auth/me')));
    } catch (error) {
      throw new AuthRefused(codeOf(error));
    }
  }

  async login(credentials: Credentials): Promise<SignedInState> {
    const body: LoginRequest = { email: credentials.email, password: credentials.password };
    try {
      return toState(await firstValueFrom(this.http.post<Me>('/api/auth/login', body)));
    } catch (error) {
      throw new AuthRefused(codeOf(error));
    }
  }

  async logout(): Promise<void> {
    await firstValueFrom(this.http.post('/api/auth/logout', null));
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
  };
}

function codeOf(error: unknown): LoginError {
  if (error instanceof HttpErrorResponse && error.status > 0) {
    const body = error.error as Partial<AuthError> | null;
    return body?.error ?? 'invalid_credentials';
  }
  return 'network';
}

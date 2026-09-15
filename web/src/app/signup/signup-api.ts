// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  SignupSignupAvailability,
  SignupSignupCompleted,
  SignupSignupLink,
} from '../api/types.gen';
import type {
  SignupAvailability,
  SignupCompleted,
  SignupDetails,
  SignupError,
} from './signup-types';

export class SignupRefused extends Error {
  constructor(readonly code: SignupError) {
    super(code);
  }
}

/** The HTTP edge of signup. Every call is made without a session, by design. */
@Injectable({ providedIn: 'root' })
export class SignupApi {
  private readonly http = inject(HttpClient);

  async availability(): Promise<SignupAvailability> {
    return this.guard(async () => {
      const body = await firstValueFrom(this.http.get<SignupSignupAvailability>('/api/signup'));
      return { enabled: body.enabled ?? false, countries: body.countries ?? [] };
    });
  }

  /** The answer is the same whether or not the address already has an account. */
  async request(email: string, locale: string): Promise<void> {
    await this.guard(() => firstValueFrom(this.http.post('/api/signup', { email, locale })));
  }

  async link(token: string): Promise<string> {
    return this.guard(async () => {
      const body = await firstValueFrom(
        this.http.get<SignupSignupLink>(`/api/signup/${encodeURIComponent(token)}`),
      );
      return body.email ?? '';
    });
  }

  /** Makes the account and the company; the person still has to sign in afterwards. */
  async complete(token: string, details: SignupDetails): Promise<SignupCompleted> {
    return this.guard(async () => {
      const body = await firstValueFrom(
        this.http.post<SignupSignupCompleted>(
          `/api/signup/${encodeURIComponent(token)}/complete`,
          details,
        ),
      );
      return { companyName: body.companyName, companyStatus: body.companyStatus ?? 'pending' };
    });
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new SignupRefused(codeOf(error));
    }
  }
}

function codeOf(error: unknown): SignupError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_usable';
    case 429:
      return 'too_many';
    default:
      return 'refused';
  }
}

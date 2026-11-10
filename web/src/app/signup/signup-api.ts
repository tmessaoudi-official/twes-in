// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable, Injector } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  SignupSignupAvailability,
  SignupSignupCompletedValidationSignupComplete as SignupSignupCompleted,
  SignupSignupLink,
} from '../api/types.gen';
import type {
  SignupAvailability,
  SignupCompleted,
  SignupDetails,
  SignupError,
} from './signup-types';
import { refusedFor } from '../shared/errors/said-already';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { formRefusalsIn } from '../shared/form/form-refusals-api';
import { isUnreachable } from '../shared/errors/unknown-refusal-api';

/** Why a call was refused, and the fields a finish was refused on, for the form to say under each. */
export class SignupRefused extends Error {
  constructor(
    readonly code: SignupError,
    readonly refusals: readonly FieldRefusal[] = [],
  ) {
    super(code);
  }
}

/** The HTTP edge of signup. Every call is made without a session, by design. */
@Injectable({ providedIn: 'root' })
export class SignupApi {
  private readonly http = inject(HttpClient);
  private readonly injector = inject(Injector);

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
      throw refusedFor(
        error,
        new SignupRefused(codeOf(error), await formRefusalsIn(this.injector, error)),
      );
    }
  }
}

function codeOf(error: unknown): SignupError {
  if (!(error instanceof HttpErrorResponse) || isUnreachable(error)) {
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

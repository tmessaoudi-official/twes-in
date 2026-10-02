// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { type PasswordResetError, resetErrorOf } from './password-reset-types';

/** Thrown when the API refuses; carries the code the page translates. */
export class PasswordResetRefused extends Error {
  constructor(readonly code: PasswordResetError) {
    super(code);
  }
}

/** The HTTP edge of forgot password: the only code here that knows its endpoints. */
@Injectable({ providedIn: 'root' })
export class PasswordResetApi {
  private readonly http = inject(HttpClient);

  /** Asks for a mailed link. The API answers alike whether or not the address has an account. */
  async forgot(email: string, locale: string): Promise<void> {
    await this.send(this.http.post<void>('/api/auth/password/forgot', { email, locale }));
  }

  /** Chooses the new password the link allows; the link is spent by it. */
  async reset(token: string, newPassword: string): Promise<void> {
    await this.send(this.http.post<void>('/api/auth/password/reset', { token, newPassword }));
  }

  private async send(request: ReturnType<HttpClient['post']>): Promise<void> {
    try {
      await firstValueFrom(request);
    } catch (error) {
      if (error instanceof HttpErrorResponse && error.status > 0) {
        const body = error.error as { error?: string } | null;
        throw new PasswordResetRefused(resetErrorOf(body?.error));
      }
      throw new PasswordResetRefused('network');
    }
  }
}

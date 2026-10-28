// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

/** How long a new password must be, as the API holds every way of choosing one to (`NewPasswordPolicy`). */
export interface PasswordLengths {
  readonly minLength: number;
  readonly maxLength: number;
}

/** GET /api/auth/password/rule, open to anyone: the pages choosing a password are reached signed out too. */
@Injectable({ providedIn: 'root' })
export class PasswordRuleApi {
  private readonly http = inject(HttpClient);

  read(): Promise<PasswordLengths> {
    return firstValueFrom(this.http.get<PasswordLengths>('/api/auth/password/rule'));
  }
}

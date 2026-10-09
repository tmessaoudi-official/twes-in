// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { NotificationMailStop } from '../api/types.gen';

/** Why a stop link did not go through, as the page says it. */
export type StopMailError = 'link_not_usable' | 'too_many_attempts' | 'network';

export class StopMailRefused extends Error {
  constructor(readonly code: StopMailError) {
    super(code);
  }
}

/** The HTTP edge of a notification mail's stop link: the only code here that knows its endpoint. */
@Injectable({ providedIn: 'root' })
export class StopMailApi {
  private readonly http = inject(HttpClient);

  /** Turns off the one kind's mail the token names; the token is the only right, there is no session. */
  async stop(token: string): Promise<void> {
    const body: NotificationMailStop = { token };
    try {
      await firstValueFrom(this.http.post<void>('/api/notification-preferences/stop', body));
    } catch (error) {
      throw new StopMailRefused(refusalOf(error));
    }
  }
}

function refusalOf(error: unknown): StopMailError {
  if (!(error instanceof HttpErrorResponse)) return 'network';
  if (error.status === 404) return 'link_not_usable';
  if (error.status === 429) return 'too_many_attempts';
  return 'network';
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

/** One browser the account is signed in on, as the API lists it. */
export interface ConnectedDevice {
  readonly id: string;
  /** The browser's User-Agent, as it sent it. */
  readonly device: string;
  readonly address: string;
  readonly createdAt: string;
  readonly lastSeenAt: string;
  /** True for the browser asking. */
  readonly current: boolean;
}

/** The HTTP edge of « Appareils connectés »: the only code that knows the endpoints. */
@Injectable({ providedIn: 'root' })
export class ConnectedDevicesApi {
  private readonly http = inject(HttpClient);

  list(): Promise<ConnectedDevice[]> {
    return firstValueFrom(this.http.get<ConnectedDevice[]>('/api/auth/sessions'));
  }

  /** Ends one of the other sessions. */
  async end(id: string): Promise<void> {
    await firstValueFrom(this.http.delete<void>(`/api/auth/sessions/${id}`));
  }

  /** Ends every session but this one; how many it ended. */
  async endOthers(): Promise<number> {
    return (await firstValueFrom(this.http.delete<{ ended: number }>('/api/auth/sessions'))).ended;
  }
}

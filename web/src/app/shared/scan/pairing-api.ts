// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpContext, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom, type Observable } from 'rxjs';
import type {
  RealtimeToken,
  ScanPairingClaimed,
  ScanPairingEcho,
  ScanPairingOpened,
  ScanPairingRefusal,
} from '../../api/types.gen';
import { SILENT } from '../feedback/activity-interceptor';

export type PairingEcho = ScanPairingEcho;

/** Why the API turned a pairing request away, or `network` when it could not be asked. */
export type PairingRefusal = ScanPairingRefusal['error'] | 'network';

export class PairingRefused extends Error {
  constructor(readonly reason: PairingRefusal) {
    super(`pairing refused: ${reason}`);
  }
}

/** Heartbeats, echoes and a phone's scans are nothing the person waits on: the activity bar leaves them out. */
const BACKGROUND = () => new HttpContext().set(SILENT, true);

/** The header a paired phone presents its key in; it has no session. */
const KEY_HEADER = 'X-Pairing-Key';

/**
 * The phone pairing's API (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4): the computer tab's side, signed in under a
 * company, and the phone's, with its key and no session. Heartbeats and echoes run in the background: they are not
 * something the person did, so the activity bar does not show them.
 */
@Injectable({ providedIn: 'root' })
export class PairingApi {
  private readonly http = inject(HttpClient);

  open(companyId: string): Promise<ScanPairingOpened> {
    return this.call(this.http.post<ScanPairingOpened>(pairings(companyId), null));
  }

  renew(companyId: string, id: string): Promise<void> {
    return this.call(
      this.http.post<void>(`${pairings(companyId)}/${id}/heartbeat`, null, {
        context: BACKGROUND(),
      }),
    );
  }

  end(companyId: string, id: string): Promise<void> {
    return this.call(
      this.http.delete<void>(`${pairings(companyId)}/${id}`, { context: BACKGROUND() }),
    );
  }

  echo(companyId: string, id: string, echo: ScanPairingEcho): Promise<void> {
    return this.call(
      this.http.post<void>(`${pairings(companyId)}/${id}/echo`, echo, { context: BACKGROUND() }),
    );
  }

  claim(link: string): Promise<ScanPairingClaimed> {
    return this.call(this.http.post<ScanPairingClaimed>('/api/scan-pairings/claim', { link }));
  }

  scan(id: string, key: string, code: string, scan: string): Promise<void> {
    return this.call(
      this.http.post<void>(`/api/scan-pairings/${id}/scans`, { code, scan }, phone(key)),
    );
  }

  choose(id: string, key: string, echo: string, choice: string): Promise<void> {
    return this.call(
      this.http.post<void>(`/api/scan-pairings/${id}/choices`, { echo, choice }, phone(key)),
    );
  }

  async realtimeToken(id: string, key: string): Promise<string> {
    const token = await this.call(
      this.http.post<RealtimeToken>(`/api/scan-pairings/${id}/realtime-token`, null, phone(key)),
    );
    return token.token;
  }

  private async call<T>(request: Observable<T>): Promise<T> {
    try {
      return await firstValueFrom(request);
    } catch (error) {
      throw new PairingRefused(refusalOf(error));
    }
  }
}

function pairings(companyId: string): string {
  return `/api/companies/${encodeURIComponent(companyId)}/scan-pairings`;
}

function phone(key: string) {
  return { headers: new HttpHeaders({ [KEY_HEADER]: key }), context: BACKGROUND() };
}

function refusalOf(error: unknown): PairingRefusal {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) return 'network';
  const reason = (error.error as Partial<ScanPairingRefusal> | null)?.error;
  if (reason !== undefined) return reason;
  return error.status === 404 ? 'unknown' : 'invalid';
}

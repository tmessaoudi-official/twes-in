// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { refusedFor } from '../../shared/errors/said-already';
import { codeText, type UnknownRefusal } from '../../shared/errors/unknown-refusal';
import { isUnreachable, unknownRefusal } from '../../shared/errors/unknown-refusal-api';

/** One browser a member is signed in on that has entered this company, as the API lists it to the company's owner. */
export interface MemberDevice {
  readonly id: string;
  /** The browser's User-Agent, as it sent it. */
  readonly device: string;
  readonly address: string;
  readonly createdAt: string;
  readonly lastSeenAt: string;
}

/** What the API answers for a member's devices that the dialog says in its own words. */
export type MemberDevicesError =
  | 'network'
  | 'owner_only'
  | 'own_sessions'
  | 'member_not_found'
  | 'session_not_found'
  | 'step_up_required';

const NAMED: ReadonlySet<string> = new Set([
  'owner_only',
  'own_sessions',
  'member_not_found',
  'session_not_found',
  'step_up_required',
]);

/** Thrown when the API refuses; carries the code the dialog says. */
export class MemberDevicesRefused extends Error {
  constructor(readonly code: MemberDevicesError | UnknownRefusal) {
    super(codeText(code));
  }
}

/** The HTTP edge of a member's « Appareils connectés »: the only code that knows the endpoints. */
@Injectable({ providedIn: 'root' })
export class MemberDevicesApi {
  private readonly http = inject(HttpClient);

  list(companyId: string, userId: string): Promise<MemberDevice[]> {
    return refused(() => firstValueFrom(this.http.get<MemberDevice[]>(path(companyId, userId))));
  }

  /** Signs the member out on one of them. */
  async end(companyId: string, userId: string, id: string): Promise<void> {
    await refused(() =>
      firstValueFrom(
        this.http.delete<void>(`${path(companyId, userId)}/${encodeURIComponent(id)}`),
      ),
    );
  }

  /** Signs the member out on every one of them; how many. */
  async endAll(companyId: string, userId: string): Promise<number> {
    const answer = await refused(() =>
      firstValueFrom(this.http.delete<{ ended: number }>(path(companyId, userId))),
    );
    return answer.ended;
  }
}

function path(companyId: string, userId: string): string {
  return `/api/companies/${encodeURIComponent(companyId)}/members/${encodeURIComponent(userId)}/sessions`;
}

async function refused<T>(call: () => Promise<T>): Promise<T> {
  try {
    return await call();
  } catch (error) {
    throw refusedFor(error, new MemberDevicesRefused(codeOf(error)));
  }
}

function codeOf(error: unknown): MemberDevicesError | UnknownRefusal {
  if (isUnreachable(error) || !(error instanceof HttpErrorResponse)) return 'network';
  const body: unknown = error.error;
  const code =
    typeof body === 'object' && body !== null ? (body as Record<string, unknown>)['error'] : null;
  return typeof code === 'string' && NAMED.has(code)
    ? (code as MemberDevicesError)
    : unknownRefusal(error);
}

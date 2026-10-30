// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  InvitationInvitationRead,
  InvitationInvitationWriteValidationAccept as InvitationInvitationWrite,
} from '../api/types.gen';
import type { InvitationError, InvitationOffer } from './invitation-types';
import { refusedFor } from '../shared/errors/said-already';
import { refusalsIn } from '../shared/form/field-refusals';

export class InvitationRefused extends Error {
  constructor(readonly code: InvitationError) {
    super(code);
  }
}

/** The HTTP edge of the invitation feature. Both calls are made without a session, by design. */
@Injectable({ providedIn: 'root' })
export class InvitationApi {
  private readonly http = inject(HttpClient);

  async offer(token: string): Promise<InvitationOffer> {
    return this.guard(async () => {
      const body = await firstValueFrom(
        this.http.get<InvitationInvitationRead>(`/api/invitations/${encodeURIComponent(token)}`),
      );
      return {
        email: body.email ?? '',
        companyName: body.companyName ?? '',
        roleName: body.roleName ?? '',
        expiresAt: body.expiresAt ?? '',
        hasAccount: body.hasAccount ?? false,
        companyRefused: body.companyRefused ?? false,
        companySuspended: body.companySuspended ?? false,
        companyReason: body.companyReason ?? null,
      };
    });
  }

  /** Creates the account and the membership; the person still has to sign in afterwards. */
  async accept(
    token: string,
    displayName: string | null,
    password: string | null,
  ): Promise<string> {
    // An existing account sends nothing: the API never names nor re-keys an account from a mailed link.
    const details: InvitationInvitationWrite =
      displayName === null || password === null ? {} : { displayName, password };
    return this.guard(async () => {
      const body = await firstValueFrom(
        this.http.post<InvitationInvitationRead>(
          `/api/invitations/${encodeURIComponent(token)}/accept`,
          details,
        ),
      );
      return body.companyName ?? '';
    });
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw refusedFor(error, new InvitationRefused(codeOf(error)));
    }
  }
}

function codeOf(error: unknown): InvitationError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_usable';
    // A refusal names its field: only one of the password is a refused password (a missing name is not).
    case 422:
      return refusalsIn(error.error).some((refusal) => refusal.field === 'password')
        ? 'password_refused'
        : 'invalid';
    // The link's budget: tried too often from here, which waiting puts right.
    case 429:
      return 'too_many';
    default:
      return 'invalid';
  }
}

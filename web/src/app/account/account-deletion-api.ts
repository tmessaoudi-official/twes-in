// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

/** A company the account is the only owner of, which keeps the account from going. */
export interface OwnedAlone {
  readonly id: string;
  readonly name: string;
}

export type AccountDeletionError =
  'step_up_required' | 'platform_operator' | 'sole_owner' | 'network' | 'refused';

const KNOWN: readonly AccountDeletionError[] = [
  'step_up_required',
  'platform_operator',
  'sole_owner',
];

/** Why the account was kept, and for a sole owner which companies. */
export class AccountDeletionRefused extends Error {
  constructor(
    readonly code: AccountDeletionError,
    readonly companies: readonly OwnedAlone[] = [],
  ) {
    super(code);
  }
}

/** The HTTP edge of « Supprimer mon compte »: the only code that knows the endpoint. */
@Injectable({ providedIn: 'root' })
export class AccountDeletionApi {
  private readonly http = inject(HttpClient);

  /** Closes the account now; the day it is deleted for good, which signing in before takes back. */
  async delete(): Promise<string> {
    try {
      return (await firstValueFrom(this.http.delete<{ finalAt: string }>('/api/auth/account')))
        .finalAt;
    } catch (error) {
      throw refusalOf(error);
    }
  }
}

function refusalOf(error: unknown): AccountDeletionRefused {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return new AccountDeletionRefused('network');
  }
  const body: unknown = error.error;
  const code =
    typeof body === 'object' && body !== null && 'error' in body ? String(body.error) : '';
  const known = KNOWN.find((candidate) => candidate === code);
  if (known === undefined) return new AccountDeletionRefused('refused');
  const companies =
    known === 'sole_owner' && typeof body === 'object' && body !== null && 'companies' in body
      ? ownedAlone(body.companies)
      : [];

  return new AccountDeletionRefused(known, companies);
}

function ownedAlone(value: unknown): OwnedAlone[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((entry: unknown) =>
    typeof entry === 'object' &&
    entry !== null &&
    'id' in entry &&
    'name' in entry &&
    typeof entry.id === 'string' &&
    typeof entry.name === 'string'
      ? [{ id: entry.id, name: entry.name }]
      : [],
  );
}

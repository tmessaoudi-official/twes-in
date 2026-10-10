// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

/** The parts « Effacer des données » offers today, in the page's order. */
export const ERASURE_PARTS = ['stock_map', 'drafts'] as const;
export type ErasurePart = (typeof ERASURE_PARTS)[number];

/** What a part would take now, by the word the page counts it under (« floors », « quotes »). */
export interface PartCounts {
  readonly part: ErasurePart;
  readonly counts: Readonly<Record<string, number>>;
}

export interface Erasure {
  readonly id: string;
  readonly parts: readonly ErasurePart[];
  readonly counts: Readonly<Record<string, Readonly<Record<string, number>>>>;
  /** ISO moments. */
  readonly erasedAt: string;
  readonly effectiveAt: string;
  readonly state: 'pending' | 'undone' | 'final';
  /** What open screens reload once it went or came back; the API never echoes a change to the tab that made it. */
  readonly kinds: readonly string[];
}

export interface ErasurePreview {
  readonly parts: readonly PartCounts[];
  readonly pending: Erasure | null;
}

/** Why the API refused, as the page and the banner translate it. */
export type ErasureError =
  | 'network'
  | 'not_found'
  | 'owner_only'
  | 'step_up_required'
  | 'erasure_pending'
  | 'erasure_final'
  | 'erasure_conflict'
  | 'no_part'
  | 'unknown_part';

/** Thrown when the API refuses; carries the code, and for a conflict the table something new stands in. */
export class ErasureRefused extends Error {
  constructor(
    readonly code: ErasureError,
    readonly table: string | null = null,
  ) {
    super(code);
  }
}

/**
 * The HTTP edge of « Effacer des données ». Its endpoints are plain controllers, outside the OpenAPI document, so the
 * shapes are declared here.
 */
@Injectable({ providedIn: 'root' })
export class DataErasureApi {
  private readonly http = inject(HttpClient);

  /** What each part would take, counted now; only within minutes of proving who is at the screen. */
  async preview(companyId: string): Promise<ErasurePreview> {
    return this.call(() => this.http.get<ErasurePreview>(`${base(companyId)}/data-erasure`));
  }

  async erase(companyId: string, parts: readonly ErasurePart[]): Promise<Erasure> {
    return this.call(() => this.http.post<Erasure>(`${base(companyId)}/data-erasures`, { parts }));
  }

  /** The erasure that may still be undone, or null. */
  async pending(companyId: string): Promise<Erasure | null> {
    return this.call(() =>
      this.http.get<Erasure | null>(`${base(companyId)}/data-erasures/pending`),
    );
  }

  async undo(companyId: string, erasureId: string): Promise<Erasure> {
    return this.call(() =>
      this.http.post<Erasure>(
        `${base(companyId)}/data-erasures/${encodeURIComponent(erasureId)}/undo`,
        null,
      ),
    );
  }

  private async call<T>(request: () => ReturnType<HttpClient['get']>): Promise<T> {
    try {
      return (await firstValueFrom(request())) as T;
    } catch (error) {
      throw refusal(error);
    }
  }
}

const base = (companyId: string): string => `/api/companies/${encodeURIComponent(companyId)}`;

const CODES: readonly ErasureError[] = [
  'owner_only',
  'step_up_required',
  'erasure_pending',
  'erasure_final',
  'erasure_conflict',
  'no_part',
  'unknown_part',
];

function refusal(error: unknown): ErasureRefused {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return new ErasureRefused('network');
  }
  if (error.status === 404) return new ErasureRefused('not_found');
  const body: unknown = error.error;
  const code =
    typeof body === 'object' && body !== null ? (body as Record<string, unknown>)['error'] : null;
  const table =
    typeof body === 'object' && body !== null ? (body as Record<string, unknown>)['table'] : null;
  const known = CODES.find((candidate) => candidate === code);
  return new ErasureRefused(known ?? 'network', typeof table === 'string' ? table : null);
}

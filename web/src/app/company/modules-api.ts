// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { ModuleModuleInterest, ModuleModuleRead, ModuleModuleWrite } from '../api/types.gen';
import type { ModuleRow } from './modules-types';

/**
 * Why the API refused, as the screen translates it: a module switched on before what it needs, or switched off while
 * an enabled module still needs it (both a 409, told apart by the direction of the switch).
 */
export type ModulesError =
  'network' | 'not_found' | 'needs_modules' | 'still_needed' | 'already_available' | 'invalid';

/** Thrown when the API refuses; carries the code the UI translates. */
export class ModulesRefused extends Error {
  constructor(readonly code: ModulesError) {
    super(code);
  }
}

/** The HTTP edge of the module registry: the only code here that knows its endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class ModulesApi {
  private readonly http = inject(HttpClient);

  /** Every module the company can have, in key order. */
  async list(companyId: string): Promise<ModuleRow[]> {
    try {
      return (await firstValueFrom(this.http.get<ModuleModuleRead[]>(path(companyId)))).map(toRow);
    } catch (error) {
      throw new ModulesRefused(codeOf(error, 'off'));
    }
  }

  async switch(companyId: string, key: string, enabled: boolean): Promise<ModuleRow> {
    const body: ModuleModuleWrite = { enabled };
    try {
      return toRow(
        await firstValueFrom(
          this.http.put<ModuleModuleRead>(`${path(companyId)}/${encodeURIComponent(key)}`, body),
        ),
      );
    } catch (error) {
      throw new ModulesRefused(codeOf(error, enabled ? 'on' : 'off'));
    }
  }

  /** « Me prévenir » on a planned module, or its withdrawal; a module that already ships answers 409. */
  async setInterest(companyId: string, key: string, interested: boolean): Promise<ModuleRow> {
    const body: ModuleModuleInterest = { interested };
    try {
      return toRow(
        await firstValueFrom(
          this.http.put<ModuleModuleRead>(
            `${path(companyId)}/${encodeURIComponent(key)}/interest`,
            body,
          ),
        ),
      );
    } catch (error) {
      throw new ModulesRefused(codeOf(error, 'interest'));
    }
  }
}

/** What was being done, which tells a 409 apart: switching on, switching off, or asking to be told. */
type Attempt = 'on' | 'off' | 'interest';

function codeOf(error: unknown, attempt: Attempt): ModulesError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return attempt === 'interest'
        ? 'already_available'
        : attempt === 'on'
          ? 'needs_modules'
          : 'still_needed';
    default:
      return 'invalid';
  }
}

const path = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/modules`;

function toRow(raw: ModuleModuleRead): ModuleRow {
  return {
    key: raw.key,
    labelKey: raw.labelKey,
    dependencies: [...raw.dependencies],
    permissions: [...raw.permissions],
    enabled: raw.enabled === true,
    ...(raw.planned ? { planned: raw.planned, interested: raw.interested === true } : {}),
  };
}

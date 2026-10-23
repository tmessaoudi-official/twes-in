// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  PermissionGroupPermissionGroupRead,
  RoleRoleRead,
  RoleRoleWriteRecordReviseValidationRoleWriteRecordRevise as RoleRoleRevise,
  RoleRoleWriteValidationRoleWrite as RoleRoleWrite,
} from '../api/types.gen';
import { isRecordChanged } from '../shared/errors/record-changed';
import type { PermissionGroupRow, RoleHolders, RoleRow } from './roles-types';
import { refusalsIn } from '../shared/form/field-refusals';
import { refusedFor } from '../shared/errors/said-already';

/**
 * Why the API refused, as the screen translates it.
 *
 * `in_use`, `built_in` and `unknown_permission` all arrive as a 422 (`not_held` as a 403) and are told apart by the
 * violation's reason (`in_use`) or the stable token the API's `detail` begins with — never by its sentence, which is
 * reworded and one day translated.
 */
export type RolesError =
  | 'network'
  | 'not_found'
  | 'name_taken'
  | 'in_use'
  | 'built_in'
  | 'unknown_permission'
  | 'not_held'
  | 'record_changed'
  | 'step_up_required'
  | 'invalid';

/** Thrown when the API refuses; carries the code the UI translates and, for a role in use, who holds it. */
export class RolesRefused extends Error {
  constructor(
    readonly code: RolesError,
    readonly detail = '',
    readonly held: RoleHolders | null = null,
  ) {
    super(code);
  }
}

/** The HTTP edge of a company's roles: the only code here that knows its endpoints. */
@Injectable({ providedIn: 'root' })
export class RolesApi {
  private readonly http = inject(HttpClient);

  /** The built-in roles first, then the company's own by name. */
  async list(companyId: string): Promise<RoleRow[]> {
    return this.read(async () =>
      (await firstValueFrom(this.http.get<RoleRoleRead[]>(path(companyId)))).map(toRow),
    );
  }

  /** Every permission a role may be given, grouped as the matrix shows them. */
  async permissionGroups(companyId: string): Promise<PermissionGroupRow[]> {
    return this.read(async () =>
      (
        await firstValueFrom(
          this.http.get<PermissionGroupPermissionGroupRead[]>(
            `/api/companies/${encodeURIComponent(companyId)}/permission-groups`,
          ),
        )
      ).map((raw) => ({
        key: raw.key ?? '',
        labelKey: raw.labelKey ?? '',
        permissions: [...raw.permissions],
      })),
    );
  }

  async create(companyId: string, name: string, permissions: readonly string[]): Promise<RoleRow> {
    return this.read(async () =>
      toRow(
        await firstValueFrom(
          this.http.post<RoleRoleRead>(path(companyId), body(name, permissions)),
        ),
      ),
    );
  }

  async revise(
    companyId: string,
    roleId: string,
    name: string,
    permissions: readonly string[],
    version: number | null,
  ): Promise<RoleRow> {
    const revision: RoleRoleRevise = { ...body(name, permissions), version };
    return this.read(async () =>
      toRow(
        await firstValueFrom(
          this.http.put<RoleRoleRead>(`${path(companyId)}/${encodeURIComponent(roleId)}`, revision),
        ),
      ),
    );
  }

  /** Names the version the role was read at: one revised since answers `record_changed`, and stays. */
  async remove(companyId: string, roleId: string, version: number): Promise<void> {
    await this.read(async () => {
      await firstValueFrom(
        this.http.delete<void>(`${path(companyId)}/${encodeURIComponent(roleId)}`, {
          params: { version: String(version) },
        }),
      );
    });
  }

  /** One place that turns an HTTP failure into a code the screen can say something about. */
  private async read<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw refusalOf(error);
    }
  }
}

function refusalOf(error: unknown): RolesRefused {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return refusedFor(error, new RolesRefused('network'));
  }
  const detail = typeof error.error?.detail === 'string' ? error.error.detail : '';
  switch (error.status) {
    case 404:
      return refusedFor(error, new RolesRefused('not_found', detail));
    case 403:
      if (detail.startsWith('step_up_required:'))
        return refusedFor(error, new RolesRefused('step_up_required', detail));
      // Otherwise it is the one refusal that is not a bare permission miss: the editor tried to give what they do not hold.
      return refusedFor(
        error,
        new RolesRefused(
          detail.startsWith('role_permission_not_held:') ? 'not_held' : 'invalid',
          detail,
        ),
      );
    case 409:
      return refusedFor(
        error,
        new RolesRefused(isRecordChanged(error.error) ? 'record_changed' : 'name_taken', detail),
      );
    case 422:
      // Three refusals share this status and the API leads each with its own token (RoleResource's constants, the
      // shape CompanyGuard already uses). Reading the token rather than the sentence is what keeps this working
      // when the message is reworded or translated; a functional test pins each one. A role somebody holds is said by
      // its violation's reason instead, whose parameters name the holders for the screen's own sentence.
      {
        const inUse = refusalsIn(error.error).find((refusal) => refusal.reason === 'role_in_use');
        if (inUse !== undefined) {
          const { role, holders, more } = inUse.params;
          return refusedFor(
            error,
            new RolesRefused('in_use', detail, {
              role: String(role ?? ''),
              holders: String(holders ?? ''),
              more: more === 1,
            }),
          );
        }
      }
      if (detail.startsWith('role_built_in:')) {
        return refusedFor(error, new RolesRefused('built_in', detail));
      }
      return refusedFor(error, new RolesRefused('unknown_permission', detail));
    default:
      return refusedFor(error, new RolesRefused('invalid', detail));
  }
}

const body = (name: string, permissions: readonly string[]): RoleRoleWrite => ({
  name,
  permissions: [...permissions],
});

const path = (companyId: string): string => `/api/companies/${encodeURIComponent(companyId)}/roles`;

function toRow(raw: RoleRoleRead): RoleRow {
  return {
    // The generated type marks every read-only property optional; the API always answers them.
    id: raw.id ?? '',
    name: raw.name ?? '',
    builtIn: raw.builtIn === true,
    wildcard: raw.wildcard === true,
    permissions: [...raw.permissions],
    memberCount: raw.memberCount ?? 0,
    // Every read carries it; a 0 would be refused by the API rather than taken for a version.
    version: raw.version ?? 0,
  };
}

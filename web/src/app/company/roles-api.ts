// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  PermissionGroupPermissionGroupRead,
  RoleRoleRead,
  RoleRoleWrite,
} from '../api/types.gen';
import type { PermissionGroupRow, RoleRow } from './roles-types';

/**
 * Why the API refused, as the screen translates it.
 *
 * `in_use`, `built_in` and `unknown_permission` all arrive as a 422 and are told apart by the stable token the
 * API's `detail` begins with — never by its sentence, which is reworded and one day translated.
 */
export type RolesError =
  'network' | 'not_found' | 'name_taken' | 'in_use' | 'built_in' | 'unknown_permission' | 'invalid';

/** Thrown when the API refuses; carries the code the UI translates and, for a role in use, who holds it. */
export class RolesRefused extends Error {
  constructor(
    readonly code: RolesError,
    readonly detail = '',
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
  ): Promise<RoleRow> {
    return this.read(async () =>
      toRow(
        await firstValueFrom(
          this.http.put<RoleRoleRead>(
            `${path(companyId)}/${encodeURIComponent(roleId)}`,
            body(name, permissions),
          ),
        ),
      ),
    );
  }

  async remove(companyId: string, roleId: string): Promise<void> {
    await this.read(async () => {
      await firstValueFrom(
        this.http.delete<void>(`${path(companyId)}/${encodeURIComponent(roleId)}`),
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
    return new RolesRefused('network');
  }
  const detail = typeof error.error?.detail === 'string' ? error.error.detail : '';
  switch (error.status) {
    case 404:
      return new RolesRefused('not_found', detail);
    case 409:
      return new RolesRefused('name_taken', detail);
    case 422:
      // Three refusals share this status and the API leads each with its own token (RoleResource's constants, the
      // shape CompanyGuard already uses). Reading the token rather than the sentence is what keeps this working
      // when the message is reworded or translated; a functional test pins each one.
      if (detail.startsWith('role_in_use:')) {
        return new RolesRefused('in_use', detail);
      }
      if (detail.startsWith('role_built_in:')) {
        return new RolesRefused('built_in', detail);
      }
      return new RolesRefused('unknown_permission', detail);
    default:
      return new RolesRefused('invalid', detail);
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
  };
}

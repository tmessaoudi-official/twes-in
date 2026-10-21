// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { requestState } from '../shared/feedback/request-state';
import { StepUp } from '../shared/step-up/step-up';
import { RolesApi, RolesRefused } from './roles-api';
import type { PermissionGroupRow, RoleRow } from './roles-types';

/** The roles of the company being worked in, the catalogue they are ticked from, and the writes. */
@Injectable({ providedIn: 'root' })
export class RolesFacade {
  private readonly api = inject(RolesApi);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly rolesSignal = signal<readonly RoleRow[]>([]);
  private readonly groupsSignal = signal<readonly PermissionGroupRow[]>([]);
  private readonly state = requestState((error) => refusalOf(error).code);
  private readonly detailSignal = signal('');

  readonly roles = this.rolesSignal.asReadonly();
  readonly groups = this.groupsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  /**
   * What the API said beyond the code — for a role in use, who holds it. The screen shows it as the API wrote it,
   * because the names in it are data and no translation of ours would carry them.
   */
  readonly detail = this.detailSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    this.detailSignal.set('');
    await this.state.read(
      async () => {
        // The catalogue and the roles together: a matrix missing either half has nothing to draw.
        const [roles, groups] = await Promise.all([
          this.api.list(companyId),
          this.api.permissionGroups(companyId),
        ]);
        this.rolesSignal.set(roles);
        this.groupsSignal.set(groups);
      },
      { failed: (error) => this.detailSignal.set(detailOf(error)) },
    );
  }

  /** True when the API accepted it; the list is read again so a count or a name that moved is the API's answer. */
  async create(companyId: string, name: string, permissions: readonly string[]): Promise<boolean> {
    return this.write(companyId, () => this.api.create(companyId, name, permissions));
  }

  async revise(
    companyId: string,
    roleId: string,
    name: string,
    permissions: readonly string[],
    version: number | null,
  ): Promise<boolean> {
    return this.write(companyId, () =>
      this.api.revise(companyId, roleId, name, permissions, version),
    );
  }

  async remove(companyId: string, roleId: string): Promise<boolean> {
    return this.write(companyId, () => this.api.remove(companyId, roleId));
  }

  clearError(): void {
    this.state.clearError();
    this.detailSignal.set('');
  }

  private async write(companyId: string, call: () => Promise<unknown>): Promise<boolean> {
    this.detailSignal.set('');
    return this.state.write(
      async () => {
        // A role changed waits for the person to prove who they are again.
        await this.stepUp.retrying(
          call,
          (error) => error instanceof RolesRefused && error.code === 'step_up_required',
          'step_up.intro_sensitive',
        );
        this.rolesSignal.set(await this.api.list(companyId));
        // What was just written can be this person's OWN rights: the session is read again so the menu, the
        // permission-gated controls and the record bars follow at once (developer, 2026-09-20). `refresh`, never
        // `load`: a moment without the API after a successful write is not a sign-out.
        await this.auth.refresh();
      },
      { failed: (error) => this.detailSignal.set(detailOf(error)) },
    );
  }
}

function refusalOf(error: unknown): RolesRefused {
  return error instanceof RolesRefused ? error : new RolesRefused('network');
}

/** The sentence after the code: the token is what the code matched on; a person reads what follows it. */
function detailOf(error: unknown): string {
  return refusalOf(error).detail.replace(/^[a-z_]+:\s*/, '');
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { requestState } from '../shared/feedback/request-state';
import { StepUp } from '../shared/step-up/step-up';
import { RolesApi, RolesRefused } from './roles-api';
import type { PermissionGroupRow, RoleHolders, RoleRow } from './roles-types';
import { refusedFor } from '../shared/errors/said-already';
import { sessionSignal } from '../shared/session/session-data';

/** The roles of the company being worked in, the catalogue they are ticked from, and the writes. */
@Injectable({ providedIn: 'root' })
export class RolesFacade {
  private readonly api = inject(RolesApi);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly rolesSignal = sessionSignal<readonly RoleRow[]>([]);
  private readonly groupsSignal = sessionSignal<readonly PermissionGroupRow[]>([]);
  private readonly state = requestState((error) => refusalOf(error).code);
  private readonly heldSignal = sessionSignal<RoleHolders | null>(null);

  readonly roles = this.rolesSignal.asReadonly();
  readonly groups = this.groupsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  /** Who holds a role a delete was refused for, which the screen names in its own sentence; null otherwise. */
  readonly held = this.heldSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    this.heldSignal.set(null);
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
      { failed: (error) => this.heldSignal.set(refusalOf(error).held) },
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

  async remove(companyId: string, roleId: string, version: number): Promise<boolean> {
    return this.write(companyId, () => this.api.remove(companyId, roleId, version));
  }

  clearError(): void {
    this.state.clearError();
    this.heldSignal.set(null);
  }

  private async write(companyId: string, call: () => Promise<unknown>): Promise<boolean> {
    this.heldSignal.set(null);
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
      { failed: (error) => this.heldSignal.set(refusalOf(error).held) },
    );
  }
}

function refusalOf(error: unknown): RolesRefused {
  return error instanceof RolesRefused ? error : refusedFor(error, new RolesRefused('network'));
}

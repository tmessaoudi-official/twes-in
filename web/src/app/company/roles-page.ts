// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { LiveChanges } from '../shared/realtime/live-changes';
import { RolesFacade } from './roles-facade';
import type { RoleRow } from './roles-types';

/**
 * The company's roles, and a matrix of what each may do (docs/SPEC.md § 7, 2026-09-20 11:30, row 104).
 *
 * The three built-in roles are the release's: they are listed and their matrix is readable, but every box is
 * disabled and neither the name nor the delete button is offered. The owner holds everything there is — now and in
 * whatever later releases add — so its matrix is replaced by one sentence saying so rather than by every box
 * ticked, which would be a smaller and untrue promise.
 */
@Component({
  selector: 'app-roles-page',
  imports: [
    Label,
    FormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    TranslatePipe,
  ],
  templateUrl: './roles-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RolesPage implements OnInit {
  private readonly facade = inject(RolesFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly roles = this.facade.roles;
  protected readonly groups = this.facade.groups;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly detail = this.facade.detail;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));

  /** The role being looked at or edited, by id; `NEW` while a role is being made. */
  protected static readonly NEW = 'new';
  private readonly openId = signal<string | null>(null);

  protected readonly open = computed<RoleRow | null>(() => {
    const id = this.openId();
    return id === null || id === RolesPage.NEW
      ? null
      : (this.roles().find((row) => row.id === id) ?? null);
  });

  protected readonly creating = computed(() => this.openId() === RolesPage.NEW);
  protected readonly editorOpen = computed(() => this.openId() !== null);
  protected readonly editable = computed(
    () => this.mayManage() && (this.creating() || this.open()?.builtIn === false),
  );

  /**
   * The name and the ticks being edited. Plain signals written once when a role is opened, never derived from the
   * list: a draft rebuilt from a reload that returned equal data as new objects is how this project lost what
   * somebody had typed before (docs/SPEC.md § Lessons). A reload while the editor is open leaves both alone.
   */
  protected readonly draftName = signal('');

  private readonly ticksSignal = signal<ReadonlySet<string>>(new Set());
  protected readonly ticks = this.ticksSignal.asReadonly();

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['role', 'membership'],
        () => this.facade.load(companyId),
        this.destroyRef,
      );
      await this.facade.load(companyId);
    }
  }

  protected openRole(row: RoleRow): void {
    this.facade.clearError();
    this.openId.set(row.id);
    this.draftName.set(row.name);
    this.ticksSignal.set(new Set(row.permissions));
  }

  protected startNew(): void {
    this.facade.clearError();
    this.openId.set(RolesPage.NEW);
    this.draftName.set('');
    this.ticksSignal.set(new Set());
  }

  protected close(): void {
    this.openId.set(null);
    this.facade.clearError();
  }

  protected isTicked(permission: string): boolean {
    return this.ticks().has(permission);
  }

  protected tick(permission: string, on: boolean): void {
    const next = new Set(this.ticks());
    if (on) {
      next.add(permission);
    } else {
      next.delete(permission);
    }
    this.ticksSignal.set(next);
  }

  protected async save(): Promise<void> {
    const companyId = this.company()?.id;
    const name = this.draftName().trim();
    if (!companyId || name === '' || this.busy()) {
      return;
    }
    const permissions = [...this.ticks()];
    const role = this.open();
    const saved =
      role === null
        ? await this.facade.create(companyId, name, permissions)
        : await this.facade.revise(companyId, role.id, name, permissions);
    if (saved) {
      this.feedback.success('company.roles.saved');
      this.close();
    }
  }

  protected async remove(row: RoleRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) {
      return;
    }
    if (await this.facade.remove(companyId, row.id)) {
      this.feedback.success('company.roles.deleted');
      this.close();
    }
  }
}

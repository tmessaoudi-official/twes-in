// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListDescriptor } from '../shared/list/list-types';
import type { MemberRow } from './company-types';
import { MembersFacade } from './members-facade';
import { RolesFacade } from './roles-facade';
import type { RoleRow } from './roles-types';
import { Feedback } from '../shared/feedback/feedback';

/** The members list as configuration: its columns, what a person may hide, and its page sizes. */
export const MEMBERS_LIST: ListDescriptor<MemberRow> = {
  id: 'members',
  // An open invitation names nobody yet, so its address identifies the row.
  rowId: (row) => (row.status === 'invited' ? `invited:${row.email}` : row.userId),
  pageSizes: [25, 50, 100],
  columns: [
    {
      id: 'name',
      label: 'members.name',
      value: (row) => row.displayName,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'email',
      label: 'members.email',
      value: (row) => row.email,
      sortable: true,
      filterable: true,
    },
    { id: 'role', label: 'members.role', value: (row) => row.role, sortable: true },
  ],
  filters: [
    {
      id: 'role',
      label: 'members.role',
      value: (row) => row.role,
      options: [],
    },
  ],
};

/**
 * What a role is called on screen. A built-in role is the same in every company, so the release translates it; a
 * role a company made for itself carries the name that company gave it, and ngx-translate answers an unknown key
 * with the key, so the same expression renders both.
 */
export const roleLabel = (role: RoleRow): string =>
  role.builtIn ? `roles.${role.name}` : role.name;

/** The members list with its role filter offering exactly the roles this company has. */
export const membersList = (roles: readonly RoleRow[]): ListDescriptor<MemberRow> => ({
  ...MEMBERS_LIST,
  filters: [
    {
      ...MEMBERS_LIST.filters![0],
      options: roles.map((role) => ({ value: role.name, label: roleLabel(role) })),
    },
  ],
});

/** Who belongs to the company being worked in, and the two things an administrator does about it. */
@Component({
  selector: 'app-members-page',
  imports: [
    ReactiveFormsModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    MatButtonModule,
    TranslatePipe,
    DataList,
    DataListCell,
  ],
  templateUrl: './members-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MembersPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly members = inject(MembersFacade);
  private readonly rolesFacade = inject(RolesFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);

  protected readonly roles = this.rolesFacade.roles;
  protected readonly roleLabel = roleLabel;
  /**
   * Removing somebody is destructive, so it sits behind "⋮" rather than under the pointer; an invitation is
   * withdrawn from where it was sent rather than here, so the action is absent on a row nobody has joined at.
   */
  protected readonly list = computed<ListDescriptor<MemberRow>>(() => ({
    ...membersList(this.roles()),
    actions: [
      {
        id: 'remove',
        label: 'members.remove',
        icon: 'person_remove',
        destructive: true,
        run: (row) => void this.remove(row.userId),
        disabled: () => this.busy(),
        shown: (row) => this.mayManage() && row.status === 'joined',
        confirm: (row) => ({
          kind: 'corrigeable',
          title: 'members.remove_title',
          message: 'members.remove_message',
          messageParams: { name: row.displayName },
          confirmLabel: 'members.remove_confirm',
          keepLabel: 'members.keep',
        }),
      },
    ],
  }));
  protected readonly rowTestId = (row: MemberRow): string => `member-${row.email}`;
  protected readonly rows = this.members.members;
  protected readonly busy = this.members.busy;
  protected readonly error = this.members.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('user.write'));

  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
    role: new FormControl<string>('member', { nonNullable: true }),
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['membership', 'invitation', 'role'],
        () => this.members.load(companyId),
        this.destroyRef,
      );
      await Promise.all([this.members.load(companyId), this.rolesFacade.load(companyId)]);
    }
  }

  protected async add(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.form.invalid || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    const { email, role } = this.form.getRawValue();
    const row = await this.members.add(companyId, email, role);
    if (row !== null) {
      this.form.reset({ email: '', role: 'member' });
      // Every address is invited, one with an account included: nobody is a member until they accept.
      this.feedback.success('members.invited');
    }
  }

  protected async remove(userId: string): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.members.remove(companyId, userId);
    }
  }
}

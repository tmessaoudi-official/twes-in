// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import type { ListDescriptor } from '../shared/list/list-types';
import type { MemberRole, MemberRow } from './company-types';
import { MembersFacade } from './members-facade';

/** The members list as configuration: its columns, what a person may hide, and its page sizes. */
export const MEMBERS_LIST: ListDescriptor<MemberRow> = {
  id: 'members',
  rowId: (row) => row.userId,
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
};

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
    DataListRowActions,
  ],
  templateUrl: './members-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MembersPage implements OnInit {
  private readonly members = inject(MembersFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly roles: readonly MemberRole[] = ['owner', 'admin', 'member'];
  protected readonly list = MEMBERS_LIST;
  protected readonly rowTestId = (row: MemberRow): string => `member-${row.email}`;
  protected readonly rows = this.members.members;
  protected readonly busy = this.members.busy;
  protected readonly error = this.members.error;
  protected readonly outcome = signal<'joined' | 'invited' | null>(null);
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('user.write'));

  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
    role: new FormControl<MemberRole>('member', { nonNullable: true }),
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.members.load(companyId);
    }
  }

  protected async add(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.form.invalid || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    this.outcome.set(null);
    const { email, role } = this.form.getRawValue();
    const row = await this.members.add(companyId, email, role);
    if (row !== null) {
      this.form.reset({ email: '', role: 'member' });
      this.outcome.set(row.status);
    }
  }

  protected async remove(userId: string): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.members.remove(companyId, userId);
    }
  }
}

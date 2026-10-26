// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  DOCUMENT,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { DayPipe } from '../shared/i18n/format-pipes';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { Select } from '../shared/form/select';
import { unsavedChanges } from '../shared/form/dirty-count';
import { AuthFacade } from '../auth/auth-facade';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListDescriptor } from '../shared/list/list-types';
import type { MemberRow } from './company-types';
import { MembersFacade } from './members-facade';
import { CapitalizePipe } from '../shared/i18n/capitalize-pipe';
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
    // Who may do what is what the list is read for: an address gives way first.
    {
      id: 'role',
      priority: 'high',
      label: 'members.role',
      value: (row) => row.role,
      sortable: true,
    },
  ],
  filters: [
    {
      id: 'role',
      label: 'members.role',
      multiple: true,
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
      options: roles.map((role) => ({
        value: role.name,
        label: roleLabel(role),
        capitalize: role.builtIn,
      })),
    },
  ],
});

/** Who belongs to the company being worked in, and what an administrator does about it. */
@Component({
  selector: 'app-members-page',
  imports: [
    ReactiveFormsModule,
    MatFormFieldModule,
    MatInputModule,
    Select,
    MatButtonModule,
    TranslatePipe,
    DataList,
    DataListCell,
    CapitalizePipe,
    DayPipe,
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
  private readonly document = inject(DOCUMENT);

  protected readonly roles = this.rolesFacade.roles;
  protected readonly shippedRoles = computed(
    () =>
      new Set(
        this.roles()
          .filter((role) => role.builtIn)
          .map((role) => role.name),
      ),
  );
  protected readonly roleOptions = computed(() =>
    this.roles().map((role) => ({
      value: role.name,
      label: roleLabel(role),
      capitalize: role.builtIn,
    })),
  );
  /**
   * Removing somebody is destructive, so it sits behind "⋮" rather than under the pointer. An invitation nobody has
   * accepted is sent again, sent at another role or withdrawn where it is listed: a mistyped address is put right here.
   */
  protected readonly list = computed<ListDescriptor<MemberRow>>(() => ({
    ...membersList(this.roles()),
    actions: [
      {
        // A member's « Activité »: the journal narrowed to them (docs/SPEC.md § 7, 2026-09-26 23:04).
        id: 'activity',
        label: 'members.activity',
        labelParams: (row) => ({ name: row.displayName }),
        icon: 'history',
        link: () => ['/company/activity'],
        linkQuery: (row) => ({ actor: row.userId }),
        shown: (row) => this.mayReadActivity() && row.status === 'joined',
      },
      {
        id: 'remove',
        label: 'members.remove',
        icon: 'person_remove',
        destructive: true,
        run: (row) => void this.remove(row),
        disabled: () => this.busy(),
        // Removing someone is not leaving: one's own row offers no « Retirer », and the API refuses it as well.
        shown: (row) =>
          this.mayManage() && row.status === 'joined' && row.userId !== this.auth.me()?.user.id,
        confirm: (row) => ({
          kind: 'corrigeable',
          title: 'members.remove_title',
          message: 'members.remove_message',
          messageParams: { name: row.displayName },
          confirmLabel: 'members.remove_confirm',
          keepLabel: 'members.keep',
        }),
      },
      {
        id: 'resend',
        // Occasional: behind « ⋮ », so the list keeps the width it had.
        rare: true,
        label: 'members.resend',
        icon: 'forward_to_inbox',
        run: (row) => void this.resend(row),
        disabled: () => this.busy(),
        shown: (row) => this.mayManage() && row.status === 'invited',
      },
      {
        id: 'change-role',
        rare: true,
        label: 'members.change_role',
        icon: 'manage_accounts',
        run: (row) => this.changeRole(row),
        disabled: () => this.busy(),
        shown: (row) => this.mayManage() && row.status === 'invited',
      },
      {
        // Undone from its toast, which sends it again: no question first.
        id: 'withdraw',
        rare: true,
        label: 'members.withdraw',
        icon: 'cancel',
        run: (row) => void this.withdraw(row),
        disabled: () => this.busy(),
        shown: (row) => this.mayManage() && row.status === 'invited' && row.invitationId !== null,
      },
    ],
  }));
  protected readonly rowTestId = (row: MemberRow): string => `member-${row.email}`;
  protected readonly rows = this.members.members;
  protected readonly busy = this.members.busy;
  protected readonly error = this.members.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('user.write'));
  protected readonly mayReadActivity = computed(() => this.auth.hasPermission('audit.read'));

  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
    role: new FormControl<string>('member', { nonNullable: true }),
  });
  private readonly typedEmail = toSignal(this.form.controls.email.valueChanges, {
    initialValue: this.form.controls.email.value,
  });
  /** The invitation already waiting for the address typed, which sending replaces. */
  protected readonly replaces = computed(() => {
    const typed = this.typedEmail().trim().toLowerCase();
    return (
      this.rows().find((row) => row.status === 'invited' && row.email.toLowerCase() === typed) ??
      null
    );
  });
  /** An invitation typed and not sent: leaving or reloading would lose it, so the page is held while it is there. */
  protected readonly changes = unsavedChanges(signal(this.form), signal(null));

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

  /** A new link to the same address at the same role: the one sent before stops working. */
  protected async resend(row: MemberRow): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId && (await this.members.add(companyId, row.email, row.role)) !== null) {
      this.feedback.success('members.resent', { email: row.email });
    }
  }

  /** The invitation's address put in the form, its role ready to change: inviting again replaces the one sent. */
  protected changeRole(row: MemberRow): void {
    this.form.setValue({ email: row.email, role: row.role });
    this.document.getElementById('member-role-input')?.focus();
  }

  protected async withdraw(row: MemberRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || row.invitationId === null) return;
    if (await this.members.withdraw(companyId, row.invitationId)) {
      this.feedback.effect('members.withdrawn', { email: row.email }, 'corrigeable', {
        key: 'members.undo',
        run: () => void this.resend(row),
      });
    }
  }

  /** Said once done, naming who lost access as the question did; a refusal is said by the error line instead. */
  protected async remove(row: MemberRow): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId && (await this.members.remove(companyId, row.userId))) {
      this.feedback.effect('members.removed', { name: row.displayName }, 'corrigeable');
    }
  }
}

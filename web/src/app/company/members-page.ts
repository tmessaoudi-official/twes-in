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
import { DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatDialog } from '@angular/material/dialog';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { Select } from '../shared/form/select';
import { unsavedChanges } from '../shared/form/dirty-count';
import { emailRule, emailTypo } from '../shared/form/email-rule';
import { AuthFacade } from '../auth/auth-facade';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListDescriptor } from '../shared/list/list-types';
import type { MemberRow } from './company-types';
import { MembersFacade } from './members-facade';
import { CapitalizePipe } from '../shared/i18n/capitalize-pipe';
import { RolesFacade } from './roles-facade';
import type { RoleRow } from './roles-types';
import { Feedback } from '../shared/feedback/feedback';
import { MemberRoleDialog, type MemberRoleChoice } from './member-role-dialog';
import { type Actor, mayGrant, mayRemove } from './role-bounds';
import { onTypedText, typedText } from '../shared/form/typed-text';

/** The members list as configuration: its columns, what a person may hide, and its page sizes. */
export const MEMBERS_LIST: ListDescriptor<MemberRow> = {
  id: 'members',
  // An open invitation names nobody yet, so its address identifies the row.
  rowId: (row) => (row.status !== 'joined' ? `invited:${row.email}` : row.userId),
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
    MomentPipe,
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
  private readonly dialog = inject(MatDialog);
  private readonly translate = inject(TranslateService);

  protected readonly roles = this.rolesFacade.roles;
  protected readonly shippedRoles = computed(
    () =>
      new Set(
        this.roles()
          .filter((role) => role.builtIn)
          .map((role) => role.name),
      ),
  );
  /** The roles the person may give, as the API bounds them: an admin is never offered « propriétaire » (hunt N-1). */
  protected readonly roleOptions = computed(() =>
    this.grantable().map((role) => ({
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
          this.mayManage() &&
          row.status === 'joined' &&
          row.userId !== this.auth.me()?.user.id &&
          this.mayTake(row.role),
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
        // A member's password locked by wrong guesses: the owner lets them back in, without the wait or the mailed link.
        id: 'unlock',
        rare: true,
        label: 'members.unlock',
        icon: 'lock_open',
        run: (row) => void this.unlock(row),
        disabled: () => this.busy(),
        shown: (row) =>
          this.isOwner() &&
          row.status === 'joined' &&
          row.passwordLockedUntil !== null &&
          row.userId !== this.auth.me()?.user.id,
      },
      {
        id: 'resend',
        // Occasional: behind « ⋮ », so the list keeps the width it had.
        rare: true,
        label: 'members.resend',
        icon: 'forward_to_inbox',
        run: (row) => void this.resend(row),
        disabled: () => this.busy(),
        shown: (row) => this.mayManage() && row.status !== 'joined' && this.mayGive(row.role),
      },
      {
        // An invitation's in the form, which sends it again; a member's from a dialog, its toast putting it back.
        id: 'change-role',
        rare: true,
        label: 'members.change_role',
        icon: 'manage_accounts',
        run: (row) => this.changeRole(row),
        disabled: () => this.busy(),
        // A member's role is taken and another given; an invitation's is withdrawn and sent again: both bounded.
        shown: (row) =>
          this.mayManage() &&
          (row.status === 'joined' ? this.mayTake(row.role) : this.mayGive(row.role)) &&
          this.roleOptions().some((option) => option.value !== row.role),
      },
      {
        // Undone from its toast, which sends it again: no question first.
        id: 'withdraw',
        rare: true,
        label: 'members.withdraw',
        icon: 'cancel',
        run: (row) => void this.withdraw(row),
        disabled: () => this.busy(),
        shown: (row) =>
          this.mayManage() &&
          row.status !== 'joined' &&
          row.invitationId !== null &&
          this.mayGive(row.role),
      },
    ],
  }));
  protected readonly rowTestId = (row: MemberRow): string => `member-${row.email}`;
  protected readonly rows = this.members.members;
  protected readonly busy = this.members.busy;
  protected readonly error = this.members.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('user.write'));
  /** Lifting a member's password lock is the owner's alone, whatever a custom role grants, as the API holds it. */
  protected readonly isOwner = computed(() => this.auth.me()?.company?.role === 'owner');
  protected readonly mayReadActivity = computed(() => this.auth.hasPermission('audit.read'));
  /** The person acting, as the API's role bounds read them: their role here and what it grants, unnarrowed. */
  private readonly actor = computed<Actor>(() => {
    const me = this.auth.me();
    return { role: me?.company?.role ?? '', permissions: me?.permissions ?? [] };
  });
  private readonly grantable = computed(() =>
    this.roles().filter((role) => mayGrant(this.actor(), role)),
  );

  /** Whether a role may be taken from whoever holds it: removing them, or changing it for another. */
  private mayTake(roleName: string): boolean {
    return mayRemove(this.actor(), roleName);
  }

  /**
   * Whether an invitation to a role may be sent again, changed or withdrawn. A role deleted since bounds nothing, as
   * the API says; one this company still has is given only by whoever may give it.
   */
  private mayGive(roleName: string): boolean {
    const role = this.roles().find((each) => each.name === roleName);
    return role === undefined || mayGrant(this.actor(), role);
  }

  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [onTypedText(Validators.required), emailRule],
    }),
    role: new FormControl<string>('member', { nonNullable: true }),
  });
  private readonly typedEmail = toSignal(this.form.controls.email.valueChanges, {
    initialValue: this.form.controls.email.value,
  });
  /** The address a slip of a key most likely meant, offered under the field and never written into it. */
  protected readonly typo = computed(() => emailTypo(this.typedEmail()));
  /** The invitation already waiting for the address typed, which sending replaces. */
  protected readonly replaces = computed(() => {
    const typed = typedText(this.typedEmail()).toLowerCase();
    return (
      this.rows().find((row) => row.status !== 'joined' && row.email.toLowerCase() === typed) ??
      null
    );
  });
  /** An invitation typed and not sent: leaving or reloading would lose it, so the page is held while it is there. */
  protected readonly changes = unsavedChanges(signal(this.form), signal(null));

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        // An account's lock changes outside the company, by a guess, the operator or the mailed link: it is said here too.
        ['membership', 'invitation', 'role', 'account'],
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
    const row = await this.members.add(companyId, typedText(email), role);
    if (row !== null) {
      this.form.reset({ email: '', role: 'member' });
      // Every address is invited, one with an account included: nobody is a member until they accept.
      this.feedback.success('members.invited');
    }
  }

  protected async unlock(row: MemberRow): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId && (await this.members.unlock(companyId, row.userId))) {
      this.feedback.success('members.unlocked', { name: row.displayName });
    }
  }

  /** A new link to the same address at the same role: the one sent before stops working. */
  protected async resend(row: MemberRow): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId && (await this.members.add(companyId, row.email, row.role)) !== null) {
      this.feedback.success('members.resent', { email: row.email });
    }
  }

  /**
   * An invitation's address put in the form, its role ready to change: inviting again replaces the one sent. A
   * member's role chosen again in a dialog, without removing them and inviting them again (hunt F5).
   */
  protected changeRole(row: MemberRow): void {
    if (row.status !== 'joined') {
      this.form.setValue({ email: row.email, role: row.role });
      this.document.getElementById('member-role-input')?.focus();
      return;
    }
    void this.chooseRole(row);
  }

  private async chooseRole(row: MemberRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    const chosen = await firstValueFrom(
      this.dialog
        .open<MemberRoleDialog, MemberRoleChoice, string | null>(MemberRoleDialog, {
          data: { name: row.displayName, role: row.role, options: this.roleOptions() },
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (chosen) await this.setRole(companyId, row, chosen, row.role);
  }

  /**
   * Said once done, naming the role now held; « Annuler » puts the one before back. An owner stepping down is offered
   * no « Annuler »: putting it back would ask of their new role what it no longer grants.
   */
  private async setRole(
    companyId: string,
    row: MemberRow,
    role: string,
    before: string,
  ): Promise<void> {
    if (!(await this.members.changeRole(companyId, row.userId, role))) return;
    const said = { name: row.displayName, role: this.roleName(role) };
    if (row.userId === this.auth.me()?.user.id) {
      this.feedback.effect('members.role_changed', said, 'corrigeable');
      return;
    }
    this.feedback.effect('members.role_changed', said, 'corrigeable', {
      key: 'members.undo',
      run: () => void this.setRole(companyId, row, before, role),
    });
  }

  private roleName(name: string): string {
    const role = this.roles().find((each) => each.name === name);
    return role === undefined ? name : this.translate.instant(roleLabel(role));
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

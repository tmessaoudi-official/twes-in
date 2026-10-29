// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  ElementRef,
  inject,
  OnInit,
  signal,
  viewChild,
} from '@angular/core';
import { FormControl, FormGroup, FormsModule, NgModel } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { LiveChanges } from '../shared/realtime/live-changes';
import { CapitalizePipe } from '../shared/i18n/capitalize-pipe';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FieldValue } from '../shared/form/form-types';
import { liveRecord } from '../shared/form/live-record';
import { PartConflict } from '../shared/form/part-conflict';
import type { ActionConfirm, ScreenAction } from '../shared/actions/screen-action';
import { RecordBar } from '../shared/form/record-bar';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { RecordChanged } from '../shared/form/record-changed';
import { MatDialog } from '@angular/material/dialog';
import { runAction } from '../shared/actions/run-action';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { RolesFacade } from './roles-facade';
import type { RoleRow } from './roles-types';
import { lengthSaid } from '../shared/form/length-said';
import { RefusedField } from '../shared/form/refused-field';

/** What a role's name keeps, as the API holds it. */
const ROLE_NAME_MAX = 64;

/**
 * The company's roles, and a matrix of what each may do (docs/SPEC.md § 7, 2026-09-20 11:30, row 104).
 *
 * The five built-in roles are the release's: they are listed and their matrix is readable, but every box is
 * disabled and neither the name nor the delete button is offered. The owner holds everything there is — now and in
 * whatever later releases add — so its matrix is replaced by one sentence saying so rather than by every box
 * ticked, which would be a smaller and untrue promise.
 */
@Component({
  selector: 'app-roles-page',
  imports: [
    Label,
    CapitalizePipe,
    FormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    PartConflict,
    RecordBar,
    RecordChanged,
    RefusedField,
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
  private readonly translate = inject(TranslateService);
  private readonly dialog = inject(MatDialog);

  protected readonly roles = this.facade.roles;
  protected readonly groups = this.facade.groups;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly held = this.facade.held;
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
  /** A name past what a role keeps, kept whole and said under the field rather than cut as it is pasted. */
  protected readonly nameTooLong = computed(() => lengthSaid(this.draftName(), ROLE_NAME_MAX));

  private readonly ticksSignal = signal<ReadonlySet<string>>(new Set());
  private readonly nameModel = viewChild('roleNameModel', { read: NgModel });
  private readonly nameInput = viewChild<ElementRef<HTMLInputElement>>('roleName');
  protected readonly ticks = this.ticksSignal.asReadonly();

  /**
   * The editor has no descriptor form: the name and the ticks are its two parts, each merged as one field, over a
   * form with no controls that stands for the open role. Keyed on the role opened alone, so a reload of the list
   * leaves it, and what it stands on, in place.
   */
  protected readonly form = computed<DescriptorFormGroup | null>(() => {
    const id = this.openId();
    return id === null || id === RolesPage.NEW
      ? null
      : new FormGroup<Record<string, FormControl<FieldValue>>>({});
  });

  /**
   * The saved version the open role stands on, and what another person's save changed in it. A role decides what
   * everyone holding it may do: an editor opened before someone took a permission away is told, rather than giving
   * it back on save.
   */
  protected readonly sync = liveRecord({
    kind: 'role',
    id: () => {
      const id = this.openId();
      return id === RolesPage.NEW ? null : id;
    },
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      if (companyId) await this.facade.load(companyId);
    },
    saved: () => (this.open() === null ? null : {}),
    version: () => this.open()?.version ?? null,
    parts: [
      {
        field: 'name',
        saved: () => (this.open() === null ? null : this.openName()),
        shown: () => this.draftName(),
        take: () => this.draftName.set(this.openName()),
      },
      {
        field: 'permissions',
        saved: () => {
          const role = this.open();
          return role === null ? null : tickText(role.permissions);
        },
        shown: () => tickText([...this.ticks()]),
        take: () => this.ticksSignal.set(new Set(this.open()?.permissions ?? [])),
      },
    ],
  });
  /**
   * What is changed and not saved: the name and the ticks, one each, as liveRecord merges them. A new role counts from
   * an empty name and no tick.
   */
  protected readonly changes = computed(() => {
    if (!this.editorOpen()) return 0;
    const role = this.open();
    const savedName = role === null ? '' : this.openName();
    const savedTicks = role === null ? '' : tickText(role.permissions);
    const name = this.draftName().trim() === savedName.trim() ? 0 : 1;
    return name + (tickText([...this.ticks()]) === savedTicks ? 0 : 1);
  });
  /** The editor's bar, beside its title: the matrix runs well below the first screen. */
  protected readonly editorActions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    return [
      { id: 'close', label: 'company.roles.close', run: () => this.close() },
      {
        id: 'save',
        label: 'company.roles.save',
        icon: 'save',
        primary: true,
        disabled: busy || this.changes() === 0,
        run: () => void this.save(),
        shown: this.editable(),
      },
    ];
  });
  protected readonly isNameConflict = (conflict: { field: string }): boolean =>
    conflict.field === 'name';
  protected readonly isPermissionsConflict = (conflict: { field: string }): boolean =>
    conflict.field === 'permissions';

  constructor() {
    inject(UnsavedChanges).declare(this.changes);
  }

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
    // A shipped role is named in the screen's language; its stored name is a key nobody may change (audit V-18).
    this.draftName.set(row.builtIn ? this.shippedName(row.name) : row.name);
    this.ticksSignal.set(new Set(row.permissions));
  }

  /** The open role's saved name, as the list shows it; not what is being typed. */
  protected readonly openName = computed(() => {
    const role = this.open();
    if (role === null) return '';
    return role.builtIn ? this.shippedName(role.name) : role.name;
  });

  private shippedName(name: string): string {
    const shown = this.translate.instant(`roles.${name}`) as string;
    return shown.charAt(0).toLocaleUpperCase() + shown.slice(1);
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
    if (name === '') {
      // Spaces alone pass `required`: the name is said needed here, under its field, where the cursor goes.
      this.nameModel()?.control.setErrors({ required: true });
      this.nameModel()?.control.markAsTouched();
      this.nameInput()?.nativeElement.focus();
      return;
    }
    if (this.nameTooLong() !== null) {
      this.nameInput()?.nativeElement.focus();
      return;
    }
    if (!companyId || this.busy()) {
      return;
    }
    const permissions = [...this.ticks()];
    const role = this.open();
    const saved =
      role === null
        ? await this.facade.create(companyId, name, permissions)
        : await this.facade.revise(companyId, role.id, name, permissions, this.sync.version());
    if (saved) {
      this.feedback.success('company.roles.saved');
      this.close();
    } else if (this.error() === 'record_changed') {
      this.facade.clearError();
      await this.sync.savedElsewhere();
    }
  }

  /**
   * Nothing brings a deleted role back, so the question names it first; the delete names the version the list read,
   * and a role revised since stays, said so, with the list read again to show what it permits now.
   */
  protected remove(row: RoleRow): void {
    if (this.busy()) return;
    runAction(
      {
        run: () => void this.removeAsked(row),
        confirm: {
          kind: 'definitif',
          title: 'company.roles.delete_title',
          message: 'company.roles.delete_message',
          messageParams: { name: row.name },
          confirmLabel: 'company.roles.delete',
          keepLabel: 'company.roles.keep',
        },
      },
      (asked) =>
        this.dialog
          .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
            data: asked,
            autoFocus: 'dialog',
          })
          .afterClosed(),
    );
  }

  private async removeAsked(row: RoleRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    if (await this.facade.remove(companyId, row.id, row.version)) {
      this.feedback.effect('company.roles.deleted', {}, 'definitif');
      this.close();
    } else if (this.error() === 'record_changed') {
      this.facade.clearError();
      await this.facade.load(companyId);
      this.feedback.notice('company.roles.changed_since', { name: row.name });
    }
  }
}

/** The ticks as one comparable text: the same permissions, in whatever order, are the same content. */
function tickText(permissions: readonly string[]): string {
  return [...permissions].sort().join(' ');
}

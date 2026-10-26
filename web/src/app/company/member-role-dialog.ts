// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { Select, type SelectOption } from '../shared/form/select';

export interface MemberRoleChoice {
  readonly name: string;
  readonly role: string;
  readonly options: readonly SelectOption[];
}

/**
 * The role a member holds, chosen again where they are listed (hunt F5): closed with the role chosen, or nothing when
 * it is the one held. A choice, not a confirmation: the toast after it puts the role back, so nothing more is asked.
 */
@Component({
  selector: 'app-member-role-dialog',
  imports: [MatButtonModule, MatDialogModule, ReactiveFormsModule, Select, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="member-role-title">
      {{ 'members.change_role_title' | translate: { name: data.name } }}
    </h2>
    <mat-dialog-content>
      <div class="flex flex-col gap-1.5 pt-1">
        <label id="member-role-change-label" class="twes-field-label" for="member-role-change">{{
          'members.role' | translate
        }}</label>
        <app-select
          inputId="member-role-change"
          labelledBy="member-role-change-label"
          describedBy="member-role-change-hint"
          testId="member-role-change"
          [formControl]="role"
          [options]="data.options"
          [translateLabels]="true"
        />
        <p id="member-role-change-hint" class="text-sm text-on-surface-variant">
          {{ 'members.change_role_hint' | translate: { name: data.name } }}
        </p>
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="member-role-keep">
        {{ 'members.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        (click)="ref.close(role.value === data.role ? null : role.value)"
        data-testid="member-role-confirm"
      >
        {{ 'members.change_role_confirm' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class MemberRoleDialog {
  protected readonly data = inject<MemberRoleChoice>(MAT_DIALOG_DATA);
  protected readonly ref = inject<MatDialogRef<MemberRoleDialog, string | null>>(MatDialogRef);
  protected readonly role = new FormControl(this.data.role, { nonNullable: true });
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { PlaceContents } from './place-contents';
import { type MovementAsked, PlaceMovement } from './place-movement';

export interface PlaceContentsDialogData {
  companyId: string;
  locationId: string;
  /** The place as the list names it, its path from the establishment's default location down. */
  path: string;
}

/**
 * « Ce qu'il y a ici » opened from « Emplacements », for a place the map has no drawing of or a person who does not
 * go through the plan. Each line's « ⋮ » moves, counts or receives it here, in the same panel the plan opens beside
 * itself, and comes back to the contents once recorded or left; its links leave for Stock and Movements, and the
 * dialog closes as they navigate.
 */
@Component({
  selector: 'app-place-contents-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, PlaceContents, PlaceMovement],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="place-contents-title">
      {{ 'inventory.plan.here.title' | translate }} · <bdi>{{ data.path }}</bdi>
    </h2>
    <mat-dialog-content>
      @if (asked(); as movement) {
        <app-place-movement
          [companyId]="data.companyId"
          [asked]="movement"
          (closed)="asked.set(null)"
        />
      } @else {
        <app-place-contents
          [companyId]="data.companyId"
          [locationId]="data.locationId"
          [actions]="true"
          (act)="asked.set($event)"
        />
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" mat-dialog-close data-testid="place-contents-close">
        {{ 'inventory.plan.here.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class PlaceContentsDialog {
  protected readonly data = inject<PlaceContentsDialogData>(MAT_DIALOG_DATA);
  /** The movement a line's « ⋮ » asked for, recorded in the dialog; null while the contents are shown. */
  protected readonly asked = signal<MovementAsked | null>(null);
}

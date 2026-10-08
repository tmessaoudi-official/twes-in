// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { PlaceContents } from './place-contents';

export interface PlaceContentsDialogData {
  companyId: string;
  locationId: string;
  /** The place as the list names it, its path from the establishment's default location down. */
  path: string;
}

/**
 * « Ce qu'il y a ici » opened from « Emplacements », for a place the map has no drawing of or a person who does not
 * go through the plan. Its links leave for Stock and Movements, and the dialog closes as they navigate.
 */
@Component({
  selector: 'app-place-contents-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, PlaceContents],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="place-contents-title">
      {{ 'inventory.plan.here.title' | translate }} · {{ data.path }}
    </h2>
    <mat-dialog-content>
      <app-place-contents [companyId]="data.companyId" [locationId]="data.locationId" />
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
}

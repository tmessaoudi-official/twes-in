// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { Brand } from '../brand/brand';

/**
 * How to install the application on an iPhone or an iPad, where Safari offers no dialog a page may open: its two steps,
 * named as Safari names them, each beside the symbol Safari draws. Opened by `AppInstall`, its words already opened.
 */
@Component({
  selector: 'app-install-guide',
  imports: [MatButtonModule, MatDialogModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'install.guide.title' | translate: { name: brand.name() } }}</h2>
    <mat-dialog-content>
      <div class="flex flex-col gap-3" data-testid="install-guide">
        <p>{{ 'install.guide.intro' | translate }}</p>
        <ol class="flex flex-col gap-3">
          <li class="flex items-center gap-3">
            <mat-icon aria-hidden="true">ios_share</mat-icon>
            <span>{{ 'install.guide.share' | translate }}</span>
          </li>
          <li class="flex items-center gap-3">
            <mat-icon aria-hidden="true">add_box</mat-icon>
            <span>{{ 'install.guide.add' | translate }}</span>
          </li>
        </ol>
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-flat-button type="button" mat-dialog-close data-testid="install-guide-close">
        {{ 'install.guide.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class InstallGuide {
  protected readonly brand = inject(Brand);
}

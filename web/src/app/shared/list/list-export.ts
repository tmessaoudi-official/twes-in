// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import type { ExportFormat } from './export-address';

/**
 * The two links that download what a list shows as a CSV or an Excel file. Nothing is shown until the list has asked for
 * its first page, since until then there is no search to hand over.
 */
@Component({
  selector: 'app-list-export',
  imports: [MatButtonModule, TranslatePipe],
  template: `
    @if (address(); as make) {
      <a mat-stroked-button [href]="make('csv')" download [attr.data-testid]="testId() + '-csv'">
        {{ 'export.csv' | translate }}
      </a>
      <a mat-stroked-button [href]="make('xlsx')" download [attr.data-testid]="testId() + '-xlsx'">
        {{ 'export.xlsx' | translate }}
      </a>
    }
  `,
  // Two links in a bare inline host touch; the host keeps them a gap apart, and wraps them on a narrow screen.
  host: { class: 'inline-flex flex-wrap gap-2' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ListExport {
  /** Writes the address of the file in a format, or is null while there is no search to hand over. */
  readonly address = input.required<((format: ExportFormat) => string) | null>();
  readonly testId = input.required<string>();
}

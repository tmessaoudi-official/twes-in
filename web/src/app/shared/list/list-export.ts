// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { TranslatePipe } from '@ngx-translate/core';
import { Feedback } from '../feedback/feedback';
import { FileSaver } from '../files/save-file';
import { StepUp } from '../step-up/step-up';
import type { ExportFormat } from './export-address';
import { ExportApi } from './export-api';

/**
 * « Exporter ▾ »: one button whose menu downloads what a list shows as a CSV or an Excel file. Nothing is shown until the
 * list has asked for its first page, since until then there is no search to hand over.
 *
 * A whole list leaving the company waits for the person to have proved who they are in the last few minutes (docs/SPEC.md
 * § 7, audit H-b2). The API decides: the file is fetched, and only when it answers `step_up_required` is the proof
 * asked for and the file fetched again, so several files in a row ask once.
 */
@Component({
  selector: 'app-list-export',
  imports: [MatButtonModule, MatIconModule, MatMenuModule, TranslatePipe],
  template: `
    @if (address(); as make) {
      <button
        mat-stroked-button
        type="button"
        [disabled]="busy()"
        [matMenuTriggerFor]="formats"
        [attr.data-testid]="testId()"
      >
        <mat-icon aria-hidden="true">download</mat-icon>
        {{ 'export.open' | translate }}
        <mat-icon iconPositionEnd aria-hidden="true">arrow_drop_down</mat-icon>
      </button>
      <mat-menu #formats="matMenu">
        @for (format of FORMATS; track format) {
          <button
            mat-menu-item
            type="button"
            (click)="download(make(format))"
            [attr.data-address]="make(format)"
            [attr.data-testid]="testId() + '-' + format"
          >
            {{ 'export.' + format | translate }}
          </button>
        }
      </mat-menu>
    }
  `,
  // `data-address` says which file an entry fetches, which a page's spec reads once the menu is open.
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ListExport {
  protected readonly FORMATS: readonly ExportFormat[] = ['csv', 'xlsx'];
  /** Writes the address of the file in a format, or is null while there is no search to hand over. */
  readonly address = input.required<((format: ExportFormat) => string) | null>();
  readonly testId = input.required<string>();

  private readonly api = inject(ExportApi);
  private readonly stepUp = inject(StepUp);
  private readonly feedback = inject(Feedback);
  private readonly saver = inject(FileSaver);
  protected readonly busy = signal(false);

  protected async download(address: string): Promise<void> {
    this.busy.set(true);
    try {
      let fetched = await this.fetch(address);
      if (fetched === 'step_up') {
        if (!(await this.stepUp.request('step_up.intro_export'))) return;
        fetched = await this.fetch(address);
      }
      if (fetched !== 'saved') this.feedback.failure('export.failed');
    } finally {
      this.busy.set(false);
    }
  }

  private async fetch(address: string): Promise<'saved' | 'step_up' | 'failed'> {
    const file = await this.api.file(address);
    if (typeof file === 'string') return file;
    // The address ends with the file's name, which is also the API's own.
    this.saver.save(file, address.split('?')[0].split('/').at(-1) ?? 'export');
    return 'saved';
  }
}

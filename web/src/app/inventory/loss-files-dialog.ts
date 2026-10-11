// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { Feedback } from '../shared/feedback/feedback';
import { FileDrop } from '../shared/form/file-drop';
import { ATTACHMENT_MAX_BYTES } from '../shared/form/file-limits';
import { MomentPipe } from '../shared/i18n/format-pipes';
import { InventoryFacade } from './inventory-facade';
import type { StockLossFile } from './inventory-types';

export interface LossFilesDialogData {
  companyId: string;
  movementId: string;
  /** What was lost, as the movements list names it. */
  product: string;
  /** Whether this person may add a file or take one off: stock.write. Anyone who sees the loss may open its files. */
  mayWrite: boolean;
}

/**
 * The files a loss keeps — the photo of what broke, the complaint filed for a theft, a destruction certificate —
 * opened by whoever sees the movements, added and taken off by whoever writes stock. A file taken off by mistake comes
 * back with « Annuler » on the toast that says so, so nothing here asks before doing it.
 */
@Component({
  selector: 'app-loss-files-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, FileDrop, MomentPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="loss-files-title">
      {{ 'inventory.loss.files.title' | translate }}
    </h2>
    <mat-dialog-content>
      <!-- Material's own display beats a layout class on mat-dialog-content: the column is laid out inside it. -->
      <div class="flex flex-col gap-4">
        <p class="font-semibold" data-testid="loss-files-product">{{ data.product }}</p>
        <p class="text-sm text-on-surface-variant">{{ 'inventory.loss.files.hint' | translate }}</p>
        @if (error(); as code) {
          <p role="alert" class="text-error" data-testid="loss-files-error">
            {{ 'inventory.errors.' + code | translate }}
          </p>
        }
        @if (files(); as list) {
          @if (list.length === 0) {
            <p data-testid="loss-files-none">{{ 'inventory.loss.files.none' | translate }}</p>
          } @else {
            <ul class="flex flex-col gap-2" data-testid="loss-files">
              @for (file of list; track file.id) {
                <li class="flex flex-wrap items-center justify-between gap-2">
                  <a
                    [href]="url(file)"
                    target="_blank"
                    rel="noopener"
                    class="underline"
                    [attr.data-testid]="'loss-file-open-' + file.name"
                  >
                    {{ file.name }}
                  </a>
                  <span class="text-sm text-on-surface-variant">
                    {{ kilobytes(file.size) }} KB · {{ file.createdAt | moment }}
                  </span>
                  @if (data.mayWrite) {
                    <button
                      mat-button
                      type="button"
                      (click)="detach(file)"
                      [disabled]="busy()"
                      [attr.data-testid]="'loss-file-remove-' + file.name"
                      [attr.aria-label]="
                        'inventory.loss.files.remove' | translate: { name: file.name }
                      "
                    >
                      {{ 'inventory.loss.files.remove_short' | translate }}
                    </button>
                  }
                </li>
              }
            </ul>
          }
        }
        @if (data.mayWrite) {
          <app-file-drop
            [label]="'inventory.loss.files.add' | translate"
            accept="application/pdf,image/png,image/jpeg,image/webp"
            [maxBytes]="maxBytes"
            [disabled]="busy()"
            testId="loss-files-add"
            (picked)="attach($event)"
          />
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="close()" data-testid="loss-files-close">
        {{ 'inventory.loss.files.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class LossFilesDialog {
  protected readonly data = inject<LossFilesDialogData>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<LossFilesDialog>>(MatDialogRef);
  private readonly facade = inject(InventoryFacade);
  private readonly feedback = inject(Feedback);

  protected readonly maxBytes = ATTACHMENT_MAX_BYTES;
  /** Null until read, and again when they could not be. */
  protected readonly files = signal<readonly StockLossFile[] | null>(null);
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;

  constructor() {
    void this.read();
  }

  protected url(file: StockLossFile): string {
    return this.facade.lossFileUrl(this.data.companyId, this.data.movementId, file.id);
  }

  /** Whole kilobytes, rounded up: a file of a few bytes is not shown as nothing. */
  protected kilobytes(size: number): number {
    return Math.ceil(size / 1024);
  }

  protected async attach(file: File): Promise<void> {
    if (this.busy()) return;
    if (await this.facade.attachToLoss(this.data.companyId, this.data.movementId, file)) {
      this.feedback.success('inventory.loss.files.attached', { name: file.name });
      await this.read();
    }
  }

  protected async detach(file: StockLossFile): Promise<void> {
    if (this.busy()) return;
    if (await this.facade.detachFromLoss(this.data.companyId, this.data.movementId, file.id)) {
      this.feedback.success(
        'inventory.loss.files.removed',
        { name: file.name },
        { key: 'inventory.loss.files.undo', run: () => void this.restore(file) },
      );
      await this.read();
    }
  }

  /** Put back where it was, whether this dialog is still open or not: the toast outlives it. */
  private async restore(file: StockLossFile): Promise<void> {
    if (await this.facade.restoreToLoss(this.data.companyId, this.data.movementId, file.id)) {
      this.feedback.success('inventory.loss.files.restored', { name: file.name });
      await this.read();
    }
  }

  /** What the page under it shows stays its own: a refusal said here leaves with the dialog. */
  protected close(): void {
    this.facade.clearError();
    this.ref.close();
  }

  private async read(): Promise<void> {
    this.files.set(await this.facade.lossFiles(this.data.companyId, this.data.movementId));
  }
}

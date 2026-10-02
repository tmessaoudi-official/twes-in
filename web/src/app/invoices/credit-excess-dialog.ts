// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatRadioModule } from '@angular/material/radio';
import { TranslatePipe } from '@ngx-translate/core';
import type { CreditExcessTo } from './invoices-types';

export interface CreditExcessDialogData {
  /** The credit note's number or a short name for it, said in the message. */
  invoiceNumber: string | null;
}

/**
 * A credit note of an invoice that was paid beyond what the credit note leaves due gives that money back: kept to the
 * customer's credit balance, or paid back to them. The API refuses to issue it until it is told which, so this is
 * asked once the issuing has been refused for that. The dialog answers with the choice, or null to leave it a draft.
 */
@Component({
  selector: 'app-credit-excess-dialog',
  imports: [MatButtonModule, MatDialogModule, MatRadioModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="credit-excess-title">
      {{ 'invoices.excess.title' | translate }}
    </h2>
    <mat-dialog-content>
      <p>{{ 'invoices.excess.message' | translate: { number: data.invoiceNumber ?? '' } }}</p>
      <mat-radio-group
        [value]="choice()"
        [attr.aria-label]="'invoices.excess.title' | translate"
        class="flex flex-col"
        data-testid="credit-excess-choice"
      >
        <mat-radio-button
          value="balance"
          (change)="choice.set('balance')"
          data-testid="credit-excess-balance"
        >
          {{ 'invoices.excess.balance' | translate }}
        </mat-radio-button>
        <mat-radio-button
          value="refund"
          (change)="choice.set('refund')"
          data-testid="credit-excess-refund"
        >
          {{ 'invoices.excess.refund' | translate }}
        </mat-radio-button>
      </mat-radio-group>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="credit-excess-keep">
        {{ 'invoices.excess.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        (click)="ref.close(choice())"
        data-testid="credit-excess-issue"
      >
        {{ 'invoices.excess.issue' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class CreditExcessDialog {
  protected readonly ref =
    inject<MatDialogRef<CreditExcessDialog, CreditExcessTo | null>>(MatDialogRef);
  protected readonly data = inject<CreditExcessDialogData>(MAT_DIALOG_DATA);
  /** The credit balance first: nothing leaves the company until someone says it does. */
  protected readonly choice = signal<CreditExcessTo>('balance');
}

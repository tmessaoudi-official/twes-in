// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatRadioModule } from '@angular/material/radio';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FormAnswer } from '../shared/feedback/request-state';
import { DecimalInput } from '../shared/form/decimal-input';
import type { FieldRefusal } from '../shared/form/field-refusals';
import type { FieldError } from '../shared/form/form-builder';
import { FormStep } from '../shared/form/form-step';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { AmountPipe } from '../shared/i18n/format-pipes';
import type { DepositShare, QuoteRow } from './quotes-types';

/** The fields a deposit's refusal names, all said under the one value the dialog asks. */
const SHARE_FIELDS = new Set(['percentage', 'amount', 'depositPercentage', 'depositAmount']);

export interface QuoteDepositDialogData {
  /** What the quote comes to, tax included, as the API answered it. */
  total: string;
  /** The currency's number of decimals: an amount finer than that is not money. */
  scale: number;
  /** Draws the deposit; the dialog stays open on a refusal and says it under the value. */
  draw: (share: DepositShare) => Promise<FormAnswer<QuoteRow>>;
}

/**
 * « Facture d'acompte »: how much of the accepted quote the deposit asks for, a percentage of it or an amount tax
 * included. The share is the API's to refuse beyond the quote or what its other deposits leave; this only keeps out
 * what is not a share at all. It closes on the share, or on null.
 */
@Component({
  selector: 'app-quote-deposit-dialog',
  imports: [
    AmountPipe,
    DecimalInput,
    FormsModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    MatRadioModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="quote-deposit-title">
      {{ 'quotes.deposit.title' | translate }}
    </h2>
    <mat-dialog-content>
      <!-- Material's own display beats a layout class on mat-dialog-content: the layout goes on a div inside it. -->
      <div class="flex flex-col gap-4">
        <p class="text-sm text-on-surface-variant">
          {{ 'quotes.deposit.help' | translate: { total: (data.total | amount: data.scale) } }}
        </p>
        <mat-radio-group
          [value]="by()"
          [attr.aria-label]="'quotes.deposit.by' | translate"
          class="flex flex-col"
          data-testid="quote-deposit-by"
        >
          <mat-radio-button
            value="percentage"
            (change)="by.set('percentage'); valueRefused.set(null)"
            data-testid="quote-deposit-by-percentage"
          >
            {{ 'quotes.deposit.by_percentage' | translate }}
          </mat-radio-button>
          <mat-radio-button
            value="amount"
            (change)="by.set('amount'); valueRefused.set(null)"
            data-testid="quote-deposit-by-amount"
          >
            {{ 'quotes.deposit.by_amount' | translate }}
          </mat-radio-button>
        </mat-radio-group>
        <mat-form-field subscriptSizing="dynamic" class="w-full">
          <mat-label>{{ 'quotes.deposit.' + by() | translate }}</mat-label>
          <input
            matInput
            appDecimal
            autocomplete="off"
            [ngModel]="value()"
            (ngModelChange)="onValue($event)"
            data-testid="quote-deposit-value"
          />
          <span matTextSuffix
            ><bdi>{{ by() === 'percentage' ? '%' : '' }}</bdi></span
          >
          <mat-hint>{{ 'quotes.deposit.' + by() + '_hint' | translate }}</mat-hint>
        </mat-form-field>
        @if (valueRefused(); as said) {
          <p role="alert" class="px-4 text-xs text-error" data-testid="quote-deposit-value-error">
            {{ said.key | translate: said.params }}
          </p>
        }
        @if (step.unplaced(); as said) {
          <p role="alert" class="text-sm text-error" data-testid="quote-deposit-refused">
            {{ said.key | translate: said.params }}
          </p>
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="quote-deposit-keep">
        {{ 'quotes.actions.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="share() === null || step.busy()"
        (click)="draw()"
        data-testid="quote-deposit-confirm"
      >
        {{ 'quotes.deposit.confirm' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class QuoteDepositDialog {
  protected readonly ref = inject<MatDialogRef<QuoteDepositDialog, QuoteRow | null>>(MatDialogRef);
  protected readonly data = inject<QuoteDepositDialogData>(MAT_DIALOG_DATA);
  /** A percentage first: it is how a deposit is usually agreed (« 30 % à la commande »). */
  protected readonly by = signal<'percentage' | 'amount'>('percentage');
  protected readonly value = signal('');
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  /** What the API refused of the value, until it or the way it is given changes. */
  protected readonly valueRefused = signal<FieldError | null>(null);

  /**
   * The share asked, or null while it is none: above zero, a percentage under 100 with at most three decimals, an
   * amount with at most the currency's.
   */
  protected readonly share = computed<DepositShare | null>(() => {
    const text = this.value().trim();
    if (this.by() === 'percentage') {
      if (!/^(0|[1-9]\d?)(\.\d{1,3})?$/.test(text) || Number(text) === 0) return null;
      return { percentage: text };
    }
    const decimals = this.data.scale > 0 ? `(\\.\\d{1,${this.data.scale}})?` : '';
    if (!new RegExp(`^(0|[1-9]\\d{0,10})${decimals}$`).test(text) || Number(text) === 0)
      return null;
    return { amount: text };
  });

  /** What the field holds: the API's point, whatever separator was typed (`appDecimal`). */
  protected onValue(value: unknown): void {
    this.value.set(typeof value === 'string' ? value : '');
    this.valueRefused.set(null);
  }

  protected async draw(): Promise<void> {
    const share = this.share();
    if (share === null || this.step.busy()) return;
    this.valueRefused.set(null);
    const answer = await this.step.run(
      () => this.data.draw(share),
      (refusals) => this.placed(refusals),
    );
    if (answer !== 'refused') this.ref.close(answer?.kept ?? null);
  }

  /** A refusal of the share under its value; hands back the rest. */
  private placed(refusals: readonly FieldRefusal[]): readonly FieldRefusal[] {
    const [onValue] = refusals.filter((refusal) => SHARE_FIELDS.has(refusal.field));
    this.valueRefused.set(onValue === undefined ? null : this.step.wordsOf(onValue));
    return refusals.filter((refusal) => !SHARE_FIELDS.has(refusal.field));
  }
}

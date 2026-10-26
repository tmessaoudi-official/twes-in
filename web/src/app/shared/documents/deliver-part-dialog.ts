// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FormAnswer } from '../feedback/request-state';
import type { FieldRefusal } from '../form/field-refusals';
import type { FieldError } from '../form/form-builder';
import { DecimalInput } from '../form/decimal-input';
import { FormStep } from '../form/form-step';
import { RefusalFigures } from '../form/refusal-figures';
import { AmountPipe } from '../i18n/format-pipes';

/** A line a note may deliver part of: its id, what it says, and what is left to deliver of it. */
export interface DeliverableLine {
  lineId: string;
  description: string;
  left: string;
}

export interface DeliverPartData {
  lines: readonly DeliverableLine[];
  /** Drafts the note of the quantities by line; the dialog stays open on a refusal and says it on its line. */
  deliver: (quantities: Record<string, string>) => Promise<FormAnswer<string>>;
}

/**
 * « Livrer une partie »: how much of each line the note delivers. Every line starts at what is left to deliver of it, a
 * line with nothing left is not offered, and a quantity of zero leaves the line out. It drafts the note of the quantities
 * by the line of the document delivered (a quote's or an invoice's) and closes on its id, or on `null` when nothing was
 * asked of it. What is more than is left is the API's to refuse, in exact decimals, said on the line it names.
 */
@Component({
  selector: 'app-deliver-part-dialog',
  imports: [
    AmountPipe,
    DecimalInput,
    FormsModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="deliver-part-title">
      {{ 'deliveries.part.title' | translate }}
    </h2>
    <mat-dialog-content>
      <p class="text-sm text-on-surface-variant">
        {{ 'deliveries.part.help' | translate }}
      </p>
      <div class="flex flex-col gap-3">
        @for (line of lines; track line.lineId; let index = $index) {
          <mat-form-field subscriptSizing="dynamic" class="w-full">
            <mat-label
              ><bdi>{{ line.description }}</bdi></mat-label
            >
            <input
              matInput
              appDecimal
              autocomplete="off"
              [ngModel]="quantities()[line.lineId]"
              (ngModelChange)="onQuantity(line.lineId, $event)"
              [attr.data-testid]="'deliver-part-quantity-' + index"
            />
            <mat-hint [attr.data-testid]="'deliver-part-left-' + index">
              {{ 'deliveries.part.left' | translate: { left: line.left | amount: null } }}
            </mat-hint>
          </mat-form-field>
          @if (refused()[line.lineId]; as said) {
            <p
              role="alert"
              class="px-4 text-xs text-error"
              [attr.data-testid]="'deliver-part-error-' + index"
            >
              {{ said.key | translate: said.params }}
            </p>
          }
        }
        @if (step.unplaced(); as said) {
          <p role="alert" class="text-sm text-error" data-testid="deliver-part-refused">
            {{ said.key | translate: said.params }}
          </p>
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="deliver-part-cancel">
        {{ 'deliveries.part.cancel' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="!any() || step.busy()"
        (click)="deliver()"
        data-testid="deliver-part-confirm"
      >
        {{ 'deliveries.part.confirm' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class DeliverPartDialog {
  protected readonly ref = inject<MatDialogRef<DeliverPartDialog, string | null>>(MatDialogRef);
  private readonly data = inject<DeliverPartData>(MAT_DIALOG_DATA);
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  /** What the API refused of each line, by its id, until that line's quantity changes. */
  protected readonly refused = signal<Readonly<Record<string, FieldError>>>({});
  /** Only the lines with something left to deliver. */
  protected readonly lines = this.data.lines
    .filter((line) => !isNothing(line.left))
    .map((line) => ({ ...line, left: plainQuantity(line.left) }));
  protected readonly quantities = signal<Record<string, string>>(
    Object.fromEntries(this.lines.map((line) => [line.lineId, line.left])),
  );

  /** The lines given a quantity above zero: a blank or a zero leaves the line out. */
  protected chosen(): Record<string, string> {
    const kept: Record<string, string> = {};
    for (const [lineId, quantity] of Object.entries(this.quantities())) {
      const text = quantity.trim().replace(',', '.');
      if (!isNothing(text)) kept[lineId] = text;
    }
    return kept;
  }

  protected any(): boolean {
    return Object.keys(this.chosen()).length > 0;
  }

  /** What the field holds: the API's point, whatever separator was typed (`appDecimal`). */
  protected onQuantity(lineId: string, value: unknown): void {
    this.quantities.update((all) => ({ ...all, [lineId]: typeof value === 'string' ? value : '' }));
    if (this.refused()[lineId] !== undefined) {
      this.refused.update((all) => {
        const others = { ...all };
        delete others[lineId];
        return others;
      });
    }
  }

  protected async deliver(): Promise<void> {
    if (!this.any() || this.step.busy()) return;
    this.refused.set({});
    const answer = await this.step.run(
      () => this.data.deliver(this.chosen()),
      (refusals) => this.placed(refusals),
    );
    if (answer !== 'refused') this.ref.close(answer?.kept ?? null);
  }

  private placed(refusals: readonly FieldRefusal[]): readonly FieldRefusal[] {
    const { onLines, rest } = quantityRefusals(refusals, this.lines, this.step);
    this.refused.set(onLines);
    return rest;
  }
}

/**
 * The refusals of quantities asked by line, `quantities[<line id>]`, in their words by the line shown, and the rest,
 * which name no line shown: how a dialog asking how much of each line says what the API refused of each.
 */
export function quantityRefusals(
  refusals: readonly FieldRefusal[],
  lines: readonly { lineId: string }[],
  step: FormStep,
): { onLines: Record<string, FieldError>; rest: FieldRefusal[] } {
  const shown = new Set(lines.map((line) => line.lineId));
  const onLines: Record<string, FieldError> = {};
  const rest: FieldRefusal[] = [];
  for (const refusal of refusals) {
    const lineId = /^quantities\[(.+)\]$/.exec(refusal.field)?.[1];
    if (lineId !== undefined && shown.has(lineId)) onLines[lineId] ??= step.wordsOf(refusal);
    else rest.push(refusal);
  }
  return { onLines, rest };
}

/** Blank, zero, or below it: nothing to deliver. */
function isNothing(value: string): boolean {
  return /^-?0*(\.0*)?$/.test(value) || value.trim().startsWith('-');
}

/** "4.500" as "4.5": the zeros the API pads a quantity with say nothing to the person reading it. */
function plainQuantity(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

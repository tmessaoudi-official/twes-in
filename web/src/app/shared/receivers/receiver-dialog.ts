// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, ElementRef, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FormAnswer } from '../feedback/request-state';
import { FormStep } from '../form/form-step';
import { RefusalFigures } from '../form/refusal-figures';
import { revealInvalid } from '../form/reveal-invalid';
import {
  type Receiver,
  type ReceiverChoice,
  type ReceiverContact,
  receiverChoice,
  receiverForm,
} from './receiver';
import { ReceiverField } from './receiver-field';

export interface ReceiverDialogData {
  /** The dialog's title and its save button, as text keys. */
  title: string;
  /** Who the document names now. */
  current: Receiver | null;
  /** Whether the customer's documents must name someone. */
  required: boolean;
  /** The customer's contacts, or the company's members, offered once they are read. */
  contacts: Promise<readonly ReceiverContact[]>;
  /** What a name picked from the list is said to be, `receivers.a_contact` unless given. */
  pickedHint?: string;
  /** Whether a name typed may be added to the list, true unless given: a company's members are never added this way. */
  offersAdding?: boolean;
  /** Records the choice; the dialog stays open on a refusal, said under the field. */
  save: (receivedBy: ReceiverChoice | null) => Promise<FormAnswer<unknown>>;
}

/** « Reçu par » put right on a document already recorded, which closes on `true` once saved. */
@Component({
  selector: 'app-receiver-dialog',
  imports: [MatButtonModule, MatDialogModule, ReceiverField, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="receiver-dialog-title">{{ data.title | translate }}</h2>
    <mat-dialog-content>
      <div class="pt-2">
        <app-receiver-field
          [form]="form"
          [contacts]="contacts()"
          [pickedHint]="data.pickedHint ?? 'receivers.a_contact'"
          [offersAdding]="data.offersAdding ?? true"
          testId="receiver-dialog"
        />
      </div>
      @if (step.unplaced(); as said) {
        <p role="alert" class="text-sm text-error" data-testid="receiver-dialog-refused">
          {{ said.key | translate: said.params }}
        </p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button
        mat-button
        type="button"
        (click)="ref.close(false)"
        data-testid="receiver-dialog-keep"
      >
        {{ 'receivers.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="step.busy()"
        (click)="save()"
        data-testid="receiver-dialog-save"
      >
        {{ 'receivers.save' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class ReceiverDialog {
  protected readonly ref = inject<MatDialogRef<ReceiverDialog, boolean>>(MatDialogRef);
  protected readonly data = inject<ReceiverDialogData>(MAT_DIALOG_DATA);
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  protected readonly form = receiverForm(this.data.current, this.data.required);
  protected readonly contacts = signal<readonly ReceiverContact[]>([]);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  constructor() {
    void this.data.contacts.then((contacts) => this.contacts.set(contacts));
  }

  protected async save(): Promise<void> {
    if (revealInvalid(this.form, this.host) || this.step.busy()) return;
    const answer = await this.step.run(
      () => this.data.save(receiverChoice(this.form)),
      (refusals) =>
        refusals.filter((refusal) => {
          if (refusal.field !== 'receivedBy') return true;
          this.form.controls.name.setErrors({ refused: this.step.wordsOf(refusal) });
          this.form.controls.name.markAsTouched();
          return false;
        }),
    );
    if (answer !== 'refused') this.ref.close(answer !== null);
  }
}

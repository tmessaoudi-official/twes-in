// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatRadioModule } from '@angular/material/radio';
import { TranslatePipe } from '@ngx-translate/core';
import { PickField, type PickOption } from '../form/pick-field';
import { AmountPipe } from '../i18n/format-pipes';

/** A document goods can be added to while it is a draft. */
export type AddToKind = 'invoice' | 'quote' | 'delivery_note';

/** An open draft as the picker names it. */
export interface AddToDraft {
  readonly id: string;
  readonly customerName: string | null;
  readonly customerReference: string | null;
  readonly total: string;
  readonly lineCount: number;
}

/**
 * A kind of document the person may add to: how its open drafts are read, one customer's or every customer's, and how
 * the customers it may be written for are found.
 */
export interface AddToTarget {
  readonly kind: AddToKind;
  readonly drafts: (customerId: string | null) => Promise<readonly AddToDraft[]>;
  readonly customers: (words: string) => Promise<readonly PickOption[]>;
}

export interface AddToData {
  /** The question asked, a text key given the count. */
  readonly title: string;
  /** A text key saying more under the question, or null. */
  readonly help: string | null;
  /** The kinds offered, in the order shown: only those the person may write, in modules switched on. */
  readonly targets: readonly AddToTarget[];
  /** How many things are being added. */
  readonly count: number;
  /** The customer the screen already knows, whose drafts alone are offered; null lets the person pick one. */
  readonly customerId: string | null;
  /** The decimals of the company's currency. */
  readonly scale: number | null;
}

/** Where the goods go: a new draft of that kind, or one of its open drafts. */
export interface AddToChoice {
  readonly kind: AddToKind;
  readonly draftId: string | null;
}

/**
 * « Ajouter à… »: asks which document the goods go on, a new draft or an open one of the kind chosen, never an issued
 * document, which only a credit note corrects. It closes on the choice, or on `null` when nothing was chosen; the
 * document's own editor then shows the goods on its lines, unsaved.
 */
@Component({
  selector: 'app-add-to-dialog',
  imports: [
    AmountPipe,
    FormsModule,
    MatButtonModule,
    MatDialogModule,
    MatRadioModule,
    PickField,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="add-to-title">
      {{ data.title | translate: { count: data.count } }}
    </h2>
    <mat-dialog-content>
      <div class="flex flex-col gap-4">
        @if (data.help) {
          <p class="m-0 text-sm text-on-surface-variant">{{ data.help | translate }}</p>
        }
        @if (data.targets.length > 1) {
          <mat-radio-group
            class="flex flex-wrap gap-x-4"
            [attr.aria-label]="'documents.add_to.kind' | translate"
            [ngModel]="kind()"
            (ngModelChange)="choose($event)"
          >
            @for (target of data.targets; track target.kind) {
              <mat-radio-button
                [value]="target.kind"
                [attr.data-testid]="'add-to-kind-' + target.kind"
              >
                {{ 'documents.add_to.kinds.' + target.kind | translate }}
              </mat-radio-button>
            }
          </mat-radio-group>
        }
        <button mat-flat-button type="button" (click)="close(null)" data-testid="add-to-new">
          {{ 'documents.add_to.new.' + kind() | translate }}
        </button>
        @if (data.customerId === null) {
          <!-- A draft is found by its customer: a draft's number is given at issue, so its customer is all it has. -->
          <app-pick-field
            [label]="'documents.add_to.customer' | translate"
            testId="add-to-customer"
            [search]="customers"
            [value]="chosen()"
            [clearable]="true"
            [noneLabel]="'documents.add_to.every_customer' | translate"
            [noneFoundLabel]="'documents.add_to.no_customer' | translate"
            (picked)="pick($event)"
          />
        }
        @if (unreadable()) {
          <p role="alert" class="m-0 text-error" data-testid="add-to-unreadable">
            {{ 'documents.add_to.unreadable' | translate }}
          </p>
        } @else if (drafts(); as drafts) {
          @if (drafts.length === 0) {
            <p class="m-0 text-on-surface-variant" data-testid="add-to-none">
              {{ 'documents.add_to.none' | translate }}
            </p>
          } @else {
            <ul class="m-0 flex list-none flex-col gap-2 p-0" data-testid="add-to-list">
              @for (draft of drafts; track draft.id) {
                <li>
                  <button
                    mat-stroked-button
                    type="button"
                    class="w-full"
                    (click)="close(draft.id)"
                    [attr.data-testid]="'add-to-draft-' + draft.id"
                  >
                    <!-- One customer's drafts need not name it on every row. -->
                    {{
                      (customerId() === null
                        ? 'documents.add_to.draft_of'
                        : 'documents.add_to.draft'
                      )
                        | translate
                          : {
                              customer: draft.customerName ?? '',
                              lines:
                                ('documents.add_to.lines' | translate: { count: draft.lineCount }),
                              total: (draft.total | amount: data.scale),
                            }
                    }}
                    @if (draft.customerReference) {
                      · {{ draft.customerReference }}
                    }
                  </button>
                </li>
              }
            </ul>
          }
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="add-to-close">
        {{ 'documents.add_to.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class AddToDialog {
  protected readonly ref = inject<MatDialogRef<AddToDialog, AddToChoice | null>>(MatDialogRef);
  protected readonly data = inject<AddToData>(MAT_DIALOG_DATA);

  protected readonly kind = signal<AddToKind>(this.data.targets[0]!.kind);
  protected readonly customerId = signal<string | null>(this.data.customerId);
  /** The customer picked in the field, which it shows. */
  protected readonly chosen = signal<PickOption | null>(null);
  /** The open drafts read for the kind and customer shown; null while they are read. */
  protected readonly drafts = signal<readonly AddToDraft[] | null>(null);
  protected readonly unreadable = signal(false);
  /** Only the last reading is shown: a choice changed quickly must not show an older answer over a newer one. */
  private reading = 0;

  constructor() {
    void this.read();
  }

  protected choose(kind: AddToKind): void {
    this.kind.set(kind);
    void this.read();
  }

  /** The customers of the kind shown, the one choosing which drafts are offered. */
  protected readonly customers = (words: string): Promise<readonly PickOption[]> =>
    this.target()?.customers(words) ?? Promise.resolve([]);

  protected pick(customer: PickOption | null): void {
    this.chosen.set(customer);
    this.customerId.set(customer?.id ?? null);
    void this.read();
  }

  protected close(draftId: string | null): void {
    this.ref.close({ kind: this.kind(), draftId });
  }

  private async read(): Promise<void> {
    const reading = ++this.reading;
    const target = this.target();
    this.drafts.set(null);
    this.unreadable.set(false);
    try {
      const drafts = (await target?.drafts(this.customerId())) ?? [];
      if (reading === this.reading) this.drafts.set(drafts);
    } catch {
      if (reading === this.reading) this.unreadable.set(true);
    }
  }

  private target(): AddToTarget | undefined {
    return this.data.targets.find((each) => each.kind === this.kind());
  }
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { ReactiveFormsModule } from '@angular/forms';
import { MatAutocompleteModule } from '@angular/material/autocomplete';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { lengthSaidOf } from '../form/length-said';
import { type ReceiverContact, type ReceiverForm } from './receiver';

/** A name as two people write it alike: case and the spaces around and between its words set aside. */
function sameName(name: string): string {
  return name.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
}

/**
 * « Reçu par »: one of the customer's contacts (or, for goods received from a vendor, the company's members), offered
 * as the person types, or any name typed, which a tick adds to the customer's contacts as the document is recorded;
 * left empty, nobody. A contact picked is copied by name; typing
 * over its name makes it a name typed. A refusal the API answers is set on the name as `refused`.
 */
@Component({
  selector: 'app-receiver-field',
  imports: [
    ReactiveFormsModule,
    MatAutocompleteModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-col gap-1">
      <mat-form-field subscriptSizing="dynamic" class="w-full">
        <mat-label>{{ 'receivers.received_by' | translate }}</mat-label>
        <input
          matInput
          type="text"
          autocomplete="off"
          [formControl]="form().controls.name"
          [matAutocomplete]="contactsOffered"
          matAutocompletePosition="above"
          (input)="typed()"
          [attr.data-testid]="testId() + '-name'"
        />
        <mat-autocomplete #contactsOffered="matAutocomplete">
          @for (contact of offered(); track contact.contactId) {
            <mat-option
              [value]="contact.name"
              (onSelectionChange)="$event.isUserInput && picked(contact)"
              [attr.data-testid]="testId() + '-contact'"
            >
              <bdi>{{ contact.name }}</bdi>
            </mat-option>
          }
        </mat-autocomplete>
        @if (form().controls.contactId.value !== null) {
          <mat-hint [attr.data-testid]="testId() + '-is-contact'">{{
            pickedHint() | translate
          }}</mat-hint>
        }
        @if (form().controls.name.getError('refused'); as refused) {
          <mat-error [attr.data-testid]="testId() + '-error'">{{
            refused.key | translate: refused.params
          }}</mat-error>
        } @else if (form().controls.name.hasError('required')) {
          <mat-error [attr.data-testid]="testId() + '-error'">{{
            'receivers.required' | translate
          }}</mat-error>
        } @else if (lengthSaidOf(form().controls.name); as error) {
          <mat-error [attr.data-testid]="testId() + '-error'"
            >{{ error.key | translate: error.params }} <bdi>{{ error.count }}</bdi></mat-error
          >
        }
      </mat-form-field>
      @if (addable()) {
        <mat-checkbox
          [formControl]="form().controls.addToContacts"
          [attr.data-testid]="testId() + '-add'"
          >{{ 'receivers.add_to_contacts' | translate }}</mat-checkbox
        >
      }
    </div>
  `,
})
export class ReceiverField {
  readonly form = input.required<ReceiverForm>();
  /** The customer's contacts, offered as the person types. */
  readonly contacts = input<readonly ReceiverContact[]>([]);
  readonly testId = input('receiver');
  /** What a name picked from the list is said to be: a contact of the customer, or a member of the company. */
  readonly pickedHint = input('receivers.a_contact');
  /** Whether a name typed may be added to the list it was not in; a company's members are never added this way. */
  readonly offersAdding = input(true);
  protected readonly lengthSaidOf = lengthSaidOf;

  /** The contacts whose name holds what is typed, whatever its case; all of them before anything is. */
  protected offered(): readonly ReceiverContact[] {
    const typed = this.form().controls.name.value.trim().toLocaleLowerCase();
    return typed === ''
      ? this.contacts()
      : this.contacts().filter((contact) => contact.name.toLocaleLowerCase().includes(typed));
  }

  /**
   * A name typed that is not a contact, which the person may add to the customer's contacts. One typed in full that
   * already is one, case and spaces aside, is not offered: added, the customer would hold the person twice.
   */
  protected addable(): boolean {
    const { name, contactId } = this.form().controls;
    const typed = sameName(name.value);
    return (
      this.offersAdding() &&
      contactId.value === null &&
      typed !== '' &&
      !this.contacts().some((contact) => sameName(contact.name) === typed)
    );
  }

  /** A contact picked, copied by its name, which the list then writes into the field. */
  protected picked(contact: ReceiverContact): void {
    this.form().patchValue({ contactId: contact.contactId, addToContacts: false });
    this.form().markAsDirty();
  }

  /** Typing over a contact's name makes it a name typed; a refusal said under it goes as it is changed. */
  protected typed(): void {
    const { name, contactId } = this.form().controls;
    if (contactId.value !== null) contactId.setValue(null);
    if (name.hasError('refused')) name.updateValueAndValidity();
  }
}

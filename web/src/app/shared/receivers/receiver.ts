// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormControl, FormGroup, Validators } from '@angular/forms';

/** Whether a customer's documents name who received them: never, when the person recording wishes, or always. */
export type ReceiverRule = 'off' | 'optional' | 'required';

/** Who received, as a document copied them: their name, and the customer's contact it was copied from, if any. */
export interface Receiver {
  name: string;
  contactId: string | null;
}

/** One of the customer's contacts a document offers to name. */
export interface ReceiverContact {
  contactId: string;
  name: string;
}

/** Who received, as a person chose them: one of the customer's contacts, or a name typed, which may be added to them. */
export interface ReceiverChoice {
  contactId: string | null;
  name: string | null;
  addToContacts: boolean;
}

/** The longest name a document prints, the API's `Receiver::NAME_MAX`. */
export const RECEIVER_NAME_MAX = 201;

export type ReceiverForm = FormGroup<{
  name: FormControl<string>;
  contactId: FormControl<string | null>;
  addToContacts: FormControl<boolean>;
}>;

/** A form holding the receiver a document names, or nobody; `required` where the customer's documents must name one. */
export function receiverForm(receiver: Receiver | null, required = false): ReceiverForm {
  return new FormGroup({
    name: new FormControl(receiver?.name ?? '', {
      nonNullable: true,
      validators: [
        Validators.maxLength(RECEIVER_NAME_MAX),
        ...(required ? [Validators.required] : []),
      ],
    }),
    contactId: new FormControl<string | null>(receiver?.contactId ?? null),
    addToContacts: new FormControl(false, { nonNullable: true }),
  });
}

/**
 * Nobody, said: where leaving the choice out keeps whoever the document named (a delivery), an emptied field sends this,
 * so what the screen showed is what is recorded.
 */
export const NOBODY: ReceiverChoice = { contactId: null, name: null, addToContacts: false };

/** What the API is sent: a contact by its id, the name typed, or null for nobody. */
export function receiverChoice(form: ReceiverForm): ReceiverChoice | null {
  const { name, contactId, addToContacts } = form.getRawValue();
  if (contactId !== null) return { contactId, name: null, addToContacts: false };
  const typed = name.trim();
  return typed === '' ? null : { contactId: null, name: typed, addToContacts };
}

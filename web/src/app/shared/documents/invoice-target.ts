// SPDX-License-Identifier: AGPL-3.0-or-later

import type { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { type AddToChoice, type AddToData, type AddToDraft, AddToDialog } from './add-to-dialog';

/** Where a document is invoiced: a new invoice, or the draft its lines are added to. */
export interface InvoiceTarget {
  readonly draftId: string | null;
}

/** The question asked, in the words of the document being invoiced: text keys. */
export interface InvoiceTargetTexts {
  readonly title: string;
  readonly help: string;
}

/** The question for delivery notes, alone or several at once. */
export const NOTE_INVOICE_TARGET: InvoiceTargetTexts = {
  title: 'delivery_notes.invoice_target.title',
  help: 'delivery_notes.invoice_target.help',
};

/** The question for an accepted quote. */
export const QUOTE_INVOICE_TARGET: InvoiceTargetTexts = {
  title: 'quotes.invoice_target.title',
  help: 'quotes.invoice_target.help',
};

/**
 * Asks where a document is invoiced, through the same picker as « Ajouter à… »: a new invoice, or one of the customer's
 * drafts in the document's establishment, read beforehand. An issued invoice is never offered, since a credit note
 * corrects it. Null when the person left the question.
 */
export async function askInvoiceTarget(
  dialog: MatDialog,
  texts: InvoiceTargetTexts,
  drafts: readonly Omit<AddToDraft, 'customerName'>[],
  customerId: string,
  scale: number | null,
): Promise<InvoiceTarget | null> {
  const choice = await firstValueFrom(
    dialog
      .open<AddToDialog, AddToData, AddToChoice | null>(AddToDialog, {
        data: {
          title: texts.title,
          help: texts.help,
          targets: [
            {
              kind: 'invoice',
              party: 'customer',
              drafts: async () => drafts.map((draft) => ({ ...draft, customerName: null })),
              customers: async () => [],
            },
          ],
          count: 1,
          customerId,
          scale,
        },
        autoFocus: 'first-tabbable',
      })
      .afterClosed(),
  );
  return choice ? { draftId: choice.draftId } : null;
}

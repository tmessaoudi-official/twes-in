// SPDX-License-Identifier: AGPL-3.0-or-later

import type { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { type AddToChoice, type AddToData, AddToDialog } from '../shared/documents/add-to-dialog';
import type { InvoiceDraftOption } from './delivery-notes-types';

/** Where notes are invoiced: a new invoice, or the draft they are added to. */
export interface InvoiceTarget {
  readonly draftId: string | null;
}

/**
 * Asks where delivery notes are invoiced, through the same picker as « Ajouter à… »: a new invoice, or one of the
 * customer's drafts in the notes' establishment, read beforehand. An issued invoice is never offered, since a credit
 * note corrects it. Null when the person left the question.
 */
export async function askInvoiceTarget(
  dialog: MatDialog,
  drafts: readonly InvoiceDraftOption[],
  customerId: string,
  scale: number | null,
): Promise<InvoiceTarget | null> {
  const choice = await firstValueFrom(
    dialog
      .open<AddToDialog, AddToData, AddToChoice | null>(AddToDialog, {
        data: {
          title: 'delivery_notes.invoice_target.title',
          help: 'delivery_notes.invoice_target.help',
          targets: [
            {
              kind: 'invoice',
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

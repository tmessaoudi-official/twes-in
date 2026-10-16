// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { DeliveryNotesApi } from '../delivery-notes/delivery-notes-api';
import { InvoicesApi } from '../invoices/invoices-api';
import { QuotesApi } from '../quotes/quotes-api';
import {
  type AddToChoice,
  type AddToData,
  AddToDialog,
  type AddToDraft,
  type AddToKind,
  type AddToTarget,
} from '../shared/documents/add-to-dialog';
import type { PickOption } from '../shared/form/pick-field';

/** Each kind of document goods go on, with the module and the right writing it takes, in the order offered. */
const KINDS: readonly { kind: AddToKind; module: string; permission: string; path: string }[] = [
  { kind: 'invoice', module: 'invoices', permission: 'invoice.write', path: '/invoices' },
  { kind: 'quote', module: 'quotes', permission: 'quote.write', path: '/quotes' },
  {
    kind: 'delivery_note',
    module: 'delivery_notes',
    permission: 'delivery_note.write',
    path: '/delivery-notes',
  },
];

/** How many open drafts the picker shows at once: picking their customer finds the others. */
const DRAFTS_SHOWN = 20;

/**
 * « Ajouter à… » for products: asks which document they go on, a new draft or an open one, then opens that document
 * with them on its lines, unsaved (`?add=`), where its editor prices and taxes them as it does a scan.
 */
@Injectable({ providedIn: 'root' })
export class AddToDocuments {
  private readonly auth = inject(AuthFacade);
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly invoices = inject(InvoicesApi);
  private readonly quotes = inject(QuotesApi);
  private readonly notes = inject(DeliveryNotesApi);

  /** The kinds the person may add goods to; none means « Ajouter à… » is not offered. */
  kinds(): AddToKind[] {
    return KINDS.filter(
      (each) => this.auth.hasModule(each.module) && this.auth.hasPermission(each.permission),
    ).map((each) => each.kind);
  }

  /** Asks where the products go and opens it; the customer's drafts alone when the screen knows the customer. */
  async open(productIds: readonly string[], customer: PickOption | null = null): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    const kinds = this.kinds();
    if (!companyId || kinds.length === 0 || productIds.length === 0) return;
    const choice = await firstValueFrom(
      this.dialog
        .open<AddToDialog, AddToData, AddToChoice | null>(AddToDialog, {
          data: {
            targets: kinds.map((kind) => this.target(companyId, kind)),
            count: productIds.length,
            customer,
            scale: null,
          },
        })
        .afterClosed(),
    );
    if (!choice) return;
    const path = KINDS.find((each) => each.kind === choice.kind)!.path;
    await this.router.navigate([path, choice.draftId ?? 'new'], {
      queryParams: { add: [...productIds] },
    });
  }

  private target(companyId: string, kind: AddToKind): AddToTarget {
    const search = (customerId: string | null) => ({
      page: 1,
      itemsPerPage: DRAFTS_SHOWN,
      q: '',
      status: ['draft' as const],
      customerIds: customerId === null ? [] : [customerId],
      intervals: {},
      order: null,
    });
    // Each kind finds its customers through its own options, which its writing right opens.
    const apis: Record<AddToKind, Omit<AddToTarget, 'kind'>> = {
      invoice: {
        drafts: async (customerId) =>
          (
            await this.invoices.invoices(companyId, {
              ...search(customerId),
              documentType: ['invoice'],
            })
          ).rows.map(draftOf),
        customers: async (words) =>
          (await this.invoices.pickCustomers(companyId, { words })).map(customerOf),
      },
      quote: {
        drafts: async (customerId) =>
          (await this.quotes.quotes(companyId, search(customerId))).rows.map(draftOf),
        customers: async (words) =>
          (await this.quotes.pickCustomers(companyId, { words })).map(customerOf),
      },
      delivery_note: {
        drafts: async (customerId) =>
          (await this.notes.notes(companyId, search(customerId))).rows.map(draftOf),
        customers: async (words) =>
          (await this.notes.pickCustomers(companyId, { words })).map(customerOf),
      },
    };
    return { kind, ...apis[kind] };
  }
}

function customerOf(customer: { id: string; number: string; name: string }): PickOption {
  return { id: customer.id, code: customer.number, name: customer.name };
}

function draftOf(row: {
  id: string;
  customerName: string | null;
  customerReference: string | null;
  total: string;
  lines: readonly unknown[];
}): AddToDraft {
  return {
    id: row.id,
    customerName: row.customerName,
    customerReference: row.customerReference,
    total: row.total,
    lineCount: row.lines.length,
  };
}

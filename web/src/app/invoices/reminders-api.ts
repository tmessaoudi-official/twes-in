// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { InvoiceReminderInvoiceReminderRead } from '../api/types.gen';
import { codeOf, InvoicesRefused } from './invoices-api';
import type { InvoiceReminderRow } from './invoices-types';

/** The HTTP edge of an invoice's reminder stages: the only code here that knows their endpoint and generated type. */
@Injectable({ providedIn: 'root' })
export class RemindersApi {
  private readonly http = inject(HttpClient);

  /** The stages the invoice reached, the first first. 404 for an invoice the member may not read. */
  async list(companyId: string, invoiceId: string): Promise<InvoiceReminderRow[]> {
    try {
      const rows = await firstValueFrom(
        this.http.get<InvoiceReminderInvoiceReminderRead[]>(
          `/api/companies/${encodeURIComponent(companyId)}/invoices/${encodeURIComponent(invoiceId)}/reminders`,
        ),
      );
      return rows.map(toRow);
    } catch (error) {
      throw new InvoicesRefused(codeOf(error));
    }
  }
}

function toRow(raw: InvoiceReminderInvoiceReminderRead): InvoiceReminderRow {
  return {
    id: raw.id ?? '',
    stage: raw.stage ?? 1,
    daysLate: raw.daysLate ?? 0,
    reachedOn: raw.reachedOn ?? '',
    lateFeeInvoiceId: raw.lateFeeInvoiceId ?? null,
  };
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  RecurringInvoiceRecurringInvoiceCreateValidationRecurringInvoiceCreate as RecurringInvoiceCreate,
  RecurringInvoiceRecurringInvoiceRead,
  RecurringInvoiceRecurringInvoiceReviseValidationRecurringInvoiceRevise as RecurringInvoiceRevise,
} from '../api/types.gen';
import {
  RECURRING_FREQUENCIES,
  type RecurringError,
  type RecurringFrequency,
  type RecurringInvoiceDraft,
  type RecurringInvoiceRevision,
  type RecurringInvoiceRow,
} from './recurring-types';

/** Thrown when the API refuses; carries the code the screen translates and, for a 422, the field it was about. */
export class RecurringRefused extends Error {
  constructor(
    readonly code: RecurringError,
    readonly field: string | null = null,
  ) {
    super(code);
  }
}

/** The HTTP edge of the recurring invoices: the only code here that knows their endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class RecurringApi {
  private readonly http = inject(HttpClient);

  /** The company's recurring invoices, the next to draft first and the ended last. */
  async list(companyId: string): Promise<RecurringInvoiceRow[]> {
    try {
      const rows = await firstValueFrom(
        this.http.get<RecurringInvoiceRecurringInvoiceRead[]>(path(companyId)),
      );
      return rows.map(toRow);
    } catch (error) {
      throw refused(error);
    }
  }

  /** Makes an invoice recurring: from its first day on, each occurrence drafts a copy of it. */
  async create(companyId: string, draft: RecurringInvoiceDraft): Promise<RecurringInvoiceRow> {
    const body: RecurringInvoiceCreate = { ...draft };
    try {
      return toRow(
        await firstValueFrom(
          this.http.post<RecurringInvoiceRecurringInvoiceRead>(path(companyId), body),
        ),
      );
    } catch (error) {
      throw refused(error);
    }
  }

  async revise(
    companyId: string,
    id: string,
    revision: RecurringInvoiceRevision,
  ): Promise<RecurringInvoiceRow> {
    const body: RecurringInvoiceRevise = { ...revision };
    try {
      return toRow(
        await firstValueFrom(
          this.http.put<RecurringInvoiceRecurringInvoiceRead>(
            `${path(companyId)}/${encodeURIComponent(id)}`,
            body,
          ),
        ),
      );
    } catch (error) {
      throw refused(error);
    }
  }

  /** Deletes it; the drafts it made stay. */
  async delete(companyId: string, id: string): Promise<void> {
    try {
      await firstValueFrom(this.http.delete(`${path(companyId)}/${encodeURIComponent(id)}`));
    } catch (error) {
      throw refused(error);
    }
  }
}

const path = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/recurring-invoices`;

function toRow(raw: RecurringInvoiceRecurringInvoiceRead): RecurringInvoiceRow {
  return {
    id: raw.id ?? '',
    modelInvoiceId: raw.modelInvoiceId ?? '',
    modelNumber: raw.modelNumber ?? null,
    customerName: raw.customerName ?? '',
    frequency: frequencyOf(raw.frequency),
    startsOn: raw.startsOn ?? '',
    endsOn: raw.endsOn ?? null,
    paused: raw.paused === true,
    nextOn: raw.nextOn ?? null,
    drafted: raw.drafted ?? 0,
    lastInvoiceId: raw.lastInvoiceId ?? null,
  };
}

function frequencyOf(value: string | undefined): RecurringFrequency {
  return (RECURRING_FREQUENCIES as readonly string[]).includes(value ?? '')
    ? (value as RecurringFrequency)
    : 'monthly';
}

/** The fields a 422 may be about, as the screens name them in their messages. */
const FIELDS: Readonly<Record<string, string>> = {
  modelInvoiceId: 'model_invoice_id',
  frequency: 'frequency',
  startsOn: 'starts_on',
  endsOn: 'ends_on',
};

/** A 422's detail starts with the field it is about: « startsOn: … »; a field this screen has no words for is none. */
function refused(error: unknown): RecurringRefused {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return new RecurringRefused('network');
  }
  if (error.status === 404) {
    return new RecurringRefused('not_found');
  }
  const detail: unknown = (error.error as { detail?: unknown } | null)?.detail;
  const named = typeof detail === 'string' ? /^(\w+):/.exec(detail)?.[1] : undefined;
  return new RecurringRefused('invalid', named === undefined ? null : (FIELDS[named] ?? null));
}

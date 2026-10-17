// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  RecurringInvoiceRecurringInvoiceCreateValidationRecurringInvoiceCreate as RecurringInvoiceCreate,
  RecurringInvoiceRecurringInvoiceRead,
  RecurringInvoiceRecurringInvoiceReviseRecordReviseValidationRecurringInvoiceReviseRecordRevise as RecurringInvoiceRevise,
} from '../api/types.gen';
import { isRecordChanged } from '../shared/errors/record-changed';
import { refusalsIn } from '../shared/form/field-refusals';
import {
  SCHEDULE_ANCHORS,
  SCHEDULE_UNITS,
  type Schedule,
  type ScheduleAnchor,
  type ScheduleUnit,
} from '../shared/schedule/schedule';
import type {
  RecurringError,
  RecurringInvoiceDraft,
  RecurringInvoiceRevision,
  RecurringInvoiceRow,
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
    const body: RecurringInvoiceCreate = {
      modelInvoiceId: draft.modelInvoiceId,
      ...flat(draft.schedule),
      startsOn: draft.startsOn,
      endsOn: draft.endsOn,
    };
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

  /** `version` is the one the row was read at: a later one, a draft the run made included, answers `record_changed`. */
  async revise(
    companyId: string,
    id: string,
    revision: RecurringInvoiceRevision,
  ): Promise<RecurringInvoiceRow> {
    const body: RecurringInvoiceRevise = {
      ...flat(revision.schedule),
      endsOn: revision.endsOn,
      paused: revision.paused,
      version: revision.version,
    };
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

/** The schedule as the API writes it, flat; a day or a week sends no anchor at all. */
function flat(
  schedule: Schedule,
): Pick<RecurringInvoiceCreate, 'unit' | 'every' | 'anchor' | 'monthDay' | 'ordinal' | 'weekday'> {
  return {
    unit: schedule.unit,
    every: schedule.every,
    ...(schedule.anchor === null ? {} : { anchor: schedule.anchor }),
    monthDay: schedule.monthDay,
    ordinal: schedule.ordinal,
    weekday: schedule.weekday,
  };
}

function toRow(raw: RecurringInvoiceRecurringInvoiceRead): RecurringInvoiceRow {
  return {
    id: raw.id ?? '',
    modelInvoiceId: raw.modelInvoiceId ?? '',
    modelNumber: raw.modelNumber ?? null,
    customerName: raw.customerName ?? '',
    schedule: {
      unit: (SCHEDULE_UNITS as readonly string[]).includes(raw.unit ?? '')
        ? (raw.unit as ScheduleUnit)
        : 'month',
      every: raw.every ?? 1,
      anchor: (SCHEDULE_ANCHORS as readonly string[]).includes(raw.anchor ?? '')
        ? (raw.anchor as ScheduleAnchor)
        : null,
      monthDay: raw.monthDay ?? null,
      ordinal: raw.ordinal ?? null,
      weekday: raw.weekday ?? null,
    },
    startsOn: raw.startsOn ?? '',
    endsOn: raw.endsOn ?? null,
    paused: raw.paused === true,
    nextOn: raw.nextOn ?? null,
    drafted: raw.drafted ?? 0,
    lastInvoiceId: raw.lastInvoiceId ?? null,
    // Every read carries it; a 0 would be refused by the API rather than taken for a version.
    version: raw.version ?? 0,
  };
}

/** A 422 names its field as the form does (`startsOn`, `monthDay`); a refusal naming none is the form's as a whole. */
function refused(error: unknown): RecurringRefused {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return new RecurringRefused('network');
  }
  if (error.status === 404) {
    return new RecurringRefused('not_found');
  }
  if (error.status === 409 && isRecordChanged(error.error)) {
    return new RecurringRefused('record_changed');
  }
  const [first] = refusalsIn(error.error);
  return new RecurringRefused('invalid', first?.field ?? null);
}

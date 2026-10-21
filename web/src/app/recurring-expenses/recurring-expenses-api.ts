// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  RecurringExpenseRecurringExpenseCreateValidationRecurringExpenseCreate as RecurringExpenseCreate,
  RecurringExpenseRecurringExpenseRead,
  RecurringExpenseRecurringExpenseReviseRecordReviseValidationRecurringExpenseReviseRecordRevise as RecurringExpenseRevise,
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
  RecurringExpenseDraft,
  RecurringExpenseError,
  RecurringExpenseRevision,
  RecurringExpenseRow,
} from './recurring-expenses-types';
import { refusedFor } from '../shared/errors/said-already';

/** Thrown when the API refuses; carries the code the screen translates and, for a 422, the field it was about. */
export class RecurringExpenseRefused extends Error {
  constructor(
    readonly code: RecurringExpenseError,
    readonly field: string | null = null,
  ) {
    super(code);
  }
}

/** The HTTP edge of the recurring expenses: the only code here that knows their endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class RecurringExpensesApi {
  private readonly http = inject(HttpClient);

  /** The company's recurring expenses, the next to draft first and the ended last. */
  async list(companyId: string): Promise<RecurringExpenseRow[]> {
    try {
      const rows = await firstValueFrom(
        this.http.get<RecurringExpenseRecurringExpenseRead[]>(path(companyId)),
      );
      return rows.map(toRow);
    } catch (error) {
      throw refused(error);
    }
  }

  /** Makes an expense recurring: from its first day on, each occurrence drafts a copy of it. */
  async create(companyId: string, draft: RecurringExpenseDraft): Promise<RecurringExpenseRow> {
    const body: RecurringExpenseCreate = {
      modelExpenseId: draft.modelExpenseId,
      ...flat(draft.schedule),
      startsOn: draft.startsOn,
      endsOn: draft.endsOn,
      variableAmount: draft.variableAmount,
    };
    try {
      return toRow(
        await firstValueFrom(
          this.http.post<RecurringExpenseRecurringExpenseRead>(path(companyId), body),
        ),
      );
    } catch (error) {
      throw refused(error);
    }
  }

  async revise(
    companyId: string,
    id: string,
    revision: RecurringExpenseRevision,
  ): Promise<RecurringExpenseRow> {
    const body: RecurringExpenseRevise = {
      ...flat(revision.schedule),
      endsOn: revision.endsOn,
      variableAmount: revision.variableAmount,
      paused: revision.paused,
      version: revision.version,
    };
    try {
      return toRow(
        await firstValueFrom(
          this.http.put<RecurringExpenseRecurringExpenseRead>(
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
  `/api/companies/${encodeURIComponent(companyId)}/recurring-expenses`;

/** The schedule as the API writes it, flat; a day or a week sends no anchor at all. */
function flat(
  schedule: Schedule,
): Pick<RecurringExpenseCreate, 'unit' | 'every' | 'anchor' | 'monthDay' | 'ordinal' | 'weekday'> {
  return {
    unit: schedule.unit,
    every: schedule.every,
    ...(schedule.anchor === null ? {} : { anchor: schedule.anchor }),
    monthDay: schedule.monthDay,
    ordinal: schedule.ordinal,
    weekday: schedule.weekday,
  };
}

function toRow(raw: RecurringExpenseRecurringExpenseRead): RecurringExpenseRow {
  return {
    id: raw.id ?? '',
    modelExpenseId: raw.modelExpenseId ?? '',
    description: raw.description ?? '',
    payee: raw.payee ?? null,
    amountGross: raw.amountGross ?? null,
    currency: raw.currency ?? null,
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
    variableAmount: raw.variableAmount === true,
    paused: raw.paused === true,
    nextOn: raw.nextOn ?? null,
    drafted: raw.drafted ?? 0,
    lastExpenseId: raw.lastExpenseId ?? null,
    // Every read carries it; a 0 would be refused by the API rather than taken for a version.
    version: raw.version ?? 0,
  };
}

/** A 422 names its field as the form does (`startsOn`, `monthDay`); a refusal naming none is the form's as a whole. */
function refused(error: unknown): RecurringExpenseRefused {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return refusedFor(error, new RecurringExpenseRefused('network'));
  }
  if (error.status === 404) {
    return refusedFor(error, new RecurringExpenseRefused('not_found'));
  }
  if (error.status === 409 && isRecordChanged(error.error)) {
    return refusedFor(error, new RecurringExpenseRefused('record_changed'));
  }
  const [first] = refusalsIn(error.error);
  return refusedFor(error, new RecurringExpenseRefused('invalid', first?.field ?? null));
}

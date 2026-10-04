// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  PaymentInstrumentPaymentInstrumentRead,
  PaymentInstrumentPaymentInstrumentWriteValidationPaymentInstrumentWrite as PaymentInstrumentWrite,
} from '../api/types.gen';
import { codeOf, InvoicesRefused } from './invoices-api';
import {
  INSTRUMENT_KINDS,
  INSTRUMENT_STATUSES,
  type InstrumentInput,
  type InstrumentRow,
  type InstrumentStep,
} from './instruments-types';

/** The HTTP edge of an invoice's cheques and traites: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class InstrumentsApi {
  private readonly http = inject(HttpClient);

  /** An invoice's instruments, by due day. 404 for an invoice the member may not read. */
  async list(companyId: string, invoiceId: string): Promise<InstrumentRow[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<PaymentInstrumentPaymentInstrumentRead[]>(path(companyId, invoiceId)),
      );
      return rows.map(toRow);
    });
  }

  /** 422 naming an amount above what is still due and not covered by another instrument, or a day before the issue day. */
  async receive(
    companyId: string,
    invoiceId: string,
    input: InstrumentInput,
  ): Promise<InstrumentRow> {
    const body: PaymentInstrumentWrite = { ...input };
    return this.guard(async () =>
      toRow(
        await firstValueFrom(
          this.http.post<PaymentInstrumentPaymentInstrumentRead>(path(companyId, invoiceId), body),
        ),
      ),
    );
  }

  /** 409 for a step the instrument's status does not allow; cashing records the payment. */
  async advance(
    companyId: string,
    invoiceId: string,
    instrumentId: string,
    step: InstrumentStep,
  ): Promise<InstrumentRow> {
    return this.guard(async () =>
      toRow(
        await firstValueFrom(
          this.http.post<PaymentInstrumentPaymentInstrumentRead>(
            `${path(companyId, invoiceId)}/${encodeURIComponent(instrumentId)}/${step}`,
            {},
          ),
        ),
      ),
    );
  }

  /** 409 for an instrument that is not still held. */
  async remove(companyId: string, invoiceId: string, instrumentId: string): Promise<void> {
    await this.guard(() =>
      firstValueFrom(
        this.http.delete(`${path(companyId, invoiceId)}/${encodeURIComponent(instrumentId)}`),
      ),
    );
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new InvoicesRefused(codeOf(error));
    }
  }
}

const path = (companyId: string, invoiceId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/invoices/${encodeURIComponent(invoiceId)}/instruments`;

function toRow(raw: PaymentInstrumentPaymentInstrumentRead): InstrumentRow {
  return {
    id: raw.id ?? '',
    kind: INSTRUMENT_KINDS.find((kind) => kind === raw.kind) ?? 'check',
    amount: raw.amount ?? '0.000',
    dueOn: raw.dueOn ?? '',
    bank: raw.bank ?? null,
    number: raw.number ?? null,
    status: INSTRUMENT_STATUSES.find((status) => status === raw.status) ?? 'held',
    settledOn: raw.settledOn ?? null,
    paymentId: raw.paymentId ?? null,
  };
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  CompanySubscriptionCompanySubscriptionRead,
  PaymentDeclarationCompanySubscriptionRead,
  PaymentDeclarationPaymentDeclarationRead,
  PaymentDeclarationPaymentDeclarationWrite,
} from '../api/types.gen';
import type {
  DeclaredPayment,
  PaymentRow,
  SubscriptionError,
  SubscriptionView,
  WaitingPayment,
} from './subscription-types';

export class SubscriptionRefused extends Error {
  constructor(readonly code: SubscriptionError) {
    super(code);
  }
}

/** The HTTP edge of the licensing feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class SubscriptionApi {
  private readonly http = inject(HttpClient);

  /** The working company's subscription, or null where licensing does not manage it: the API answers that with a 404. */
  async ofCompany(companyId: string): Promise<SubscriptionView | null> {
    try {
      return toView(
        await firstValueFrom(
          this.http.get<CompanySubscriptionCompanySubscriptionRead>(subscriptionPath(companyId)),
        ),
      );
    } catch (error) {
      if (error instanceof HttpErrorResponse && error.status === 404) return null;
      throw new SubscriptionRefused(codeOf(error));
    }
  }

  /** Says the company paid; answers the declaration now waiting for the operator. */
  declare(companyId: string, payment: DeclaredPayment): Promise<PaymentRow> {
    const body: PaymentDeclarationPaymentDeclarationWrite = { ...payment };
    return this.guard(async () =>
      toPayment(
        await firstValueFrom(
          this.http.post<PaymentDeclarationPaymentDeclarationRead>(
            `${subscriptionPath(companyId)}/payments`,
            body,
          ),
        ),
      ),
    );
  }

  /** Every payment waiting for a decision, oldest first: an operator's queue, across all companies. */
  waiting(): Promise<WaitingPayment[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<PaymentDeclarationPaymentDeclarationRead[]>(
            '/api/platform/payment-declarations',
          ),
        )
      ).map(toWaiting),
    );
  }

  /** Confirms a payment, covering that many billing periods. */
  confirm(
    declarationId: string,
    periods: number,
    decisionNote: string | null,
  ): Promise<PaymentRow> {
    return this.decide(declarationId, 'confirm', { periods, decisionNote });
  }

  /** Rejects it, which ends the hold it kept on the company at once. */
  reject(declarationId: string, decisionNote: string | null): Promise<PaymentRow> {
    return this.decide(declarationId, 'reject', { decisionNote });
  }

  private decide(
    declarationId: string,
    decision: 'confirm' | 'reject',
    body: { periods?: number; decisionNote: string | null },
  ): Promise<PaymentRow> {
    return this.guard(async () =>
      toPayment(
        await firstValueFrom(
          this.http.post<PaymentDeclarationPaymentDeclarationRead>(
            `/api/platform/payment-declarations/${encodeURIComponent(declarationId)}/${decision}`,
            body,
          ),
        ),
      ),
    );
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new SubscriptionRefused(codeOf(error));
    }
  }
}

const subscriptionPath = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/subscription`;

function toView(read: CompanySubscriptionCompanySubscriptionRead): SubscriptionView {
  return {
    companyId: read.companyId ?? '',
    stage: read.stage,
    access: read.access,
    coveredUntil: read.coveredUntil,
    graceEndsAt: read.graceEndsAt,
    daysLeft: read.daysLeft ?? null,
    trialEndsOn: read.trialEndsOn ?? null,
    paidThrough: read.paidThrough ?? null,
    periodCount: read.periodCount,
    periodUnit: read.periodUnit,
    price: read.price ?? null,
    currency: read.currency ?? null,
    // Absent and null are the same answer: API Platform leaves a null property out unless the operation says
    // otherwise, and a reader that breaks on a missing key hides a standing that is perfectly readable.
    openPayment: read.openPayment ? toPayment(read.openPayment) : null,
    payments: (read.payments ?? []).map(toPayment),
    canDeclare: read.canDeclare ?? false,
  };
}

function toPayment(
  read: PaymentDeclarationCompanySubscriptionRead | PaymentDeclarationPaymentDeclarationRead,
): PaymentRow {
  return {
    id: read.id ?? '',
    amount: read.amount,
    currency: read.currency,
    method: read.method,
    paidOn: read.paidOn,
    reference: read.reference ?? null,
    note: read.note ?? null,
    status: read.status,
    declaredAt: read.declaredAt,
    decidedAt: read.decidedAt ?? null,
    decisionNote: read.decisionNote ?? null,
  };
}

function toWaiting(read: PaymentDeclarationPaymentDeclarationRead): WaitingPayment {
  return {
    ...toPayment(read),
    companyId: read.companyId ?? '',
    companyName: read.companyName ?? '',
  };
}

function codeOf(error: unknown): SubscriptionError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) return 'network';
  if (error.status === 404) return 'not_managed';
  if (error.status === 409) return 'already_declared';
  return error.status === 422 ? 'invalid' : 'refused';
}

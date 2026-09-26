// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  CompanyCompanyRead,
  CompanyCompanyWrite,
  ModuleDemandModuleDemandRead,
  PlatformAccountPlatformAccountRead,
  PlatformCompanyPlatformCompanyRead,
  PlatformSubscriptionPlatformSubscriptionRead,
  PlatformSubscriptionPlatformSubscriptionWrite,
  PlatformOwnerInvitationPlatformOwnerInvitationRead,
  PlatformOwnerInvitationPlatformOwnerInvitationWrite,
  SettingSettingRead,
} from '../api/types.gen';
import type {
  AccountAction,
  ModuleDemandRow,
  PlatformSubscriptionRow,
  SubscriptionTerms,
  NewCompany,
  PlatformAccountRow,
  PlatformCompanyRow,
  PlatformError,
  PlatformSignup,
  SignupSwitch,
} from './platform-types';

export class PlatformRefused extends Error {
  constructor(readonly code: PlatformError) {
    super(code);
  }
}

/** The HTTP edge of the platform feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class PlatformApi {
  private readonly http = inject(HttpClient);

  waitingCompanies(): Promise<PlatformCompanyRow[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<PlatformCompanyPlatformCompanyRead[]>('/api/platform/companies', {
          params: { status: 'pending' },
        }),
      );
      return rows.map(toRow);
    });
  }

  /** Every company, whatever its status. */
  companies(): Promise<PlatformCompanyRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<PlatformCompanyPlatformCompanyRead[]>('/api/platform/companies'),
        )
      ).map(toRow),
    );
  }

  /** Opens a company, which waits for its first owner; answers its identifier. A name already taken is refused. */
  createCompany(company: NewCompany): Promise<string> {
    const body: CompanyCompanyWrite = { ...company };
    return this.guard(
      async () =>
        (await firstValueFrom(this.http.post<CompanyCompanyRead>('/api/companies', body))).id ?? '',
      'name_taken',
    );
  }

  /** Mails an owner's invitation for that company; an address that already belongs to it is refused. */
  inviteOwner(companyId: string, email: string): Promise<void> {
    const body: PlatformOwnerInvitationPlatformOwnerInvitationWrite = { email };
    return this.guard(async () => {
      await firstValueFrom(
        this.http.post<PlatformOwnerInvitationPlatformOwnerInvitationRead>(
          `/api/platform/companies/${encodeURIComponent(companyId)}/owners`,
          body,
        ),
      );
    }, 'already_member');
  }

  approve(companyId: string): Promise<PlatformCompanyRow> {
    return this.decide(companyId, 'approve');
  }

  reject(companyId: string): Promise<PlatformCompanyRow> {
    return this.decide(companyId, 'reject');
  }

  /** Whether anyone may sign up (closed unless set), and whether a new company waits (it does unless set). */
  signup(): Promise<PlatformSignup> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<SettingSettingRead[]>('/api/platform/settings'),
      );
      const valueOf = (key: SignupSwitch) => rows.find((row) => row.key === key)?.value;
      return {
        enabled: valueOf('signup.enabled') === true,
        approvalRequired: valueOf('signup.approval_required') !== false,
      };
    });
  }

  setSignup(key: SignupSwitch, value: boolean): Promise<void> {
    return this.guard(async () => {
      await firstValueFrom(
        this.http.put<SettingSettingRead>(`/api/platform/settings/${key}`, { value }),
      );
    });
  }

  private decide(companyId: string, decision: 'approve' | 'reject'): Promise<PlatformCompanyRow> {
    return this.guard(async () =>
      toRow(
        await firstValueFrom(
          this.http.post<PlatformCompanyPlatformCompanyRead>(
            `/api/platform/companies/${encodeURIComponent(companyId)}/${decision}`,
            {},
          ),
        ),
      ),
    );
  }

  /** Accounts whose address or name holds that text, a few at most; an empty text lists the first of them all. */
  accounts(text: string): Promise<PlatformAccountRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<PlatformAccountPlatformAccountRead[]>('/api/platform/accounts', {
            params: { q: text },
          }),
        )
      ).map(toAccount),
    );
  }

  actOnAccount(userId: string, action: AccountAction): Promise<PlatformAccountRow> {
    return this.guard(async () =>
      toAccount(
        await firstValueFrom(
          this.http.post<PlatformAccountPlatformAccountRead>(
            `/api/platform/accounts/${encodeURIComponent(userId)}/${action}`,
            {},
          ),
        ),
      ),
    );
  }

  /** A 409 means something different on each endpoint, so each names its own. */
  /** A company's subscription, or null where licensing does not manage it: the API answers that with a 404. */
  async subscription(companyId: string): Promise<PlatformSubscriptionRow | null> {
    try {
      return toSubscription(
        await firstValueFrom(
          this.http.get<PlatformSubscriptionPlatformSubscriptionRead>(subscriptionPath(companyId)),
        ),
      );
    } catch (error) {
      if (error instanceof HttpErrorResponse && error.status === 404) return null;
      throw new PlatformRefused(codeOf(error, 'refused'));
    }
  }

  /** Sets the terms, starting to manage the company or revising what it holds. */
  setSubscription(companyId: string, terms: SubscriptionTerms): Promise<PlatformSubscriptionRow> {
    const body: PlatformSubscriptionPlatformSubscriptionWrite = { ...terms };
    return this.guard(
      async () =>
        toSubscription(
          await firstValueFrom(
            this.http.put<PlatformSubscriptionPlatformSubscriptionRead>(
              subscriptionPath(companyId),
              body,
            ),
          ),
        ),
      'refused',
    );
  }

  /** Stops managing the company: it gets full access again, and the audit log keeps what was set. */
  stopSubscription(companyId: string): Promise<void> {
    return this.guard(async () => {
      await firstValueFrom(this.http.delete(subscriptionPath(companyId)));
    }, 'refused');
  }

  /** How many companies wait for each planned module, the most asked for first. */
  moduleDemand(): Promise<ModuleDemandRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ModuleDemandModuleDemandRead[]>('/api/platform/module-demand'),
        )
      ).map((read) => ({
        key: read.key,
        labelKey: read.labelKey,
        planned: read.planned,
        companies: read.companies,
      })),
    );
  }

  private async guard<T>(
    call: () => Promise<T>,
    conflict: PlatformError = 'own_account',
  ): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new PlatformRefused(codeOf(error, conflict));
    }
  }
}

const subscriptionPath = (companyId: string): string =>
  `/api/platform/companies/${encodeURIComponent(companyId)}/subscription`;

function toRow(read: PlatformCompanyPlatformCompanyRead): PlatformCompanyRow {
  return {
    id: read.id ?? '',
    name: read.name ?? '',
    countryCode: read.countryCode ?? '',
    status: read.status ?? 'pending',
    createdAt: read.createdAt ?? '',
    owners: read.owners ?? [],
    subscription:
      read.subscription === null || read.subscription === undefined
        ? null
        : {
            stage: read.subscription.stage,
            access: read.subscription.access,
            coveredUntil: read.subscription.coveredUntil,
            daysLeft: read.subscription.daysLeft ?? null,
          },
  };
}

function toSubscription(
  read: PlatformSubscriptionPlatformSubscriptionRead,
): PlatformSubscriptionRow {
  return {
    companyId: read.companyId ?? '',
    periodCount: read.periodCount,
    periodUnit: read.periodUnit,
    trialEndsOn: read.trialEndsOn ?? null,
    paidThrough: read.paidThrough ?? null,
    price: read.price ?? null,
    currency: read.currency ?? null,
    graceDays: read.graceDays ?? null,
    unpaidMode: read.unpaidMode ?? null,
    holdDays: read.holdDays ?? null,
    stage: read.stage,
    access: read.access,
    coveredUntil: read.coveredUntil,
    graceEndsAt: read.graceEndsAt,
    daysLeft: read.daysLeft ?? null,
    updatedAt: read.updatedAt,
  };
}

function toAccount(read: PlatformAccountPlatformAccountRead): PlatformAccountRow {
  return {
    id: read.id ?? '',
    email: read.email ?? '',
    displayName: read.displayName ?? '',
    active: read.active ?? false,
    platformOperator: read.platformOperator ?? false,
    createdAt: read.createdAt ?? '',
  };
}

function codeOf(error: unknown, conflict: PlatformError): PlatformError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  if (error.status === 409) {
    return conflict;
  }
  return error.status === 404 ? 'not_found' : 'refused';
}

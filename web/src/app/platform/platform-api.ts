// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  CompanyCompanyReadValidationCreate as CompanyCompanyRead,
  CompanyCompanyWriteValidationCreate as CompanyCompanyWrite,
  FailedMessages,
  ModuleDemandModuleDemandRead,
  PlatformAccountPlatformAccountRead,
  PlatformCompanyPlatformCompanyRead,
  PlatformSubscriptionPlatformSubscriptionRead,
  PlatformSubscriptionPlatformSubscriptionWrite,
  PlatformOwnerInvitationPlatformOwnerInvitationReadValidationInvite as PlatformOwnerInvitationPlatformOwnerInvitationRead,
  PlatformOwnerInvitationPlatformOwnerInvitationWriteValidationInvite as PlatformOwnerInvitationPlatformOwnerInvitationWrite,
  SettingSettingRead,
} from '../api/types.gen';
import type { ListPage } from '../shared/list/list-types';
import type {
  AccountAction,
  PlatformAccountSearch,
  PlatformCompanySearch,
  FailedMessagesRead,
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

  /** One page of the companies the search finds, as Hydra carries it: the rows and the total. */
  companies(search: PlatformCompanySearch): Promise<ListPage<PlatformCompanyRow>> {
    return this.guard(async () => {
      const page = await firstValueFrom(
        this.http.get<HydraPage<PlatformCompanyPlatformCompanyRead>>('/api/platform/companies', {
          headers: { Accept: 'application/ld+json' },
          params: companyParams(search),
        }),
      );
      return { rows: page.member.map(toRow), total: totalOf(page) };
    });
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

  /** One page of the accounts the search finds, each with the companies it belongs to. */
  accounts(search: PlatformAccountSearch): Promise<ListPage<PlatformAccountRow>> {
    return this.guard(async () => {
      const page = await firstValueFrom(
        this.http.get<HydraPage<PlatformAccountPlatformAccountRead>>('/api/platform/accounts', {
          headers: { Accept: 'application/ld+json' },
          params: accountParams(search),
        }),
      );
      return { rows: page.member.map(toAccount), total: totalOf(page) };
    });
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

  /** How many messages the worker gave up on, by kind, the most first. */
  failedMessages(): Promise<FailedMessagesRead> {
    return this.guard(async () => {
      const read = await firstValueFrom(
        this.http.get<FailedMessages>('/api/platform/failed-messages'),
      );
      return {
        total: read.total,
        kinds: read.kinds.map(({ kind, count }) => ({ kind, count })),
      };
    });
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

/** A collection as Hydra answers it. */
interface HydraPage<T> {
  readonly member: readonly T[];
  readonly totalItems?: number;
}

function totalOf(page: HydraPage<unknown>): number {
  if (page.totalItems === undefined) throw new Error('A page came without its total.');
  return page.totalItems;
}

function pagingParams(page: number, itemsPerPage: number, q: string): HttpParams {
  let params = new HttpParams().set('page', page).set('itemsPerPage', itemsPerPage);
  if (q.trim() !== '') params = params.set('q', q.trim());
  return params;
}

function companyParams(search: PlatformCompanySearch): HttpParams {
  let params = pagingParams(search.page, search.itemsPerPage, search.q);
  if (search.status !== null) params = params.set('status', search.status);
  if (search.countryCode !== null) params = params.set('countryCode', search.countryCode);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

function accountParams(search: PlatformAccountSearch): HttpParams {
  let params = pagingParams(search.page, search.itemsPerPage, search.q);
  if (search.active !== null) params = params.set('active', search.active);
  if (search.platformOperator !== null)
    params = params.set('platformOperator', search.platformOperator);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
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
    companies: (read.companies ?? []).map((company) => ({
      id: company.id ?? '',
      name: company.name ?? '',
      role: company.role ?? '',
    })),
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

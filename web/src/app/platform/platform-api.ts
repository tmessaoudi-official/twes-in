// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { PlatformCompanyPlatformCompanyRead, SettingSettingRead } from '../api/types.gen';
import type {
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

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new PlatformRefused(codeOf(error));
    }
  }
}

function toRow(read: PlatformCompanyPlatformCompanyRead): PlatformCompanyRow {
  return {
    id: read.id ?? '',
    name: read.name ?? '',
    countryCode: read.countryCode ?? '',
    status: read.status ?? 'pending',
    createdAt: read.createdAt ?? '',
    owners: read.owners ?? [],
  };
}

function codeOf(error: unknown): PlatformError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  return error.status === 404 ? 'not_found' : 'refused';
}

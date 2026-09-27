// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  LegalTextStatusLegalTextStatusRead,
  SettingSettingRead,
  PlatformLegalTextPlatformLegalTextRead,
  PlatformLegalTextPlatformLegalTextWrite,
} from '../api/types.gen';
import type { LegalLanguage } from '../shared/legal/legal-api';
import type { LegalPage } from '../shared/legal/legal-pages';
import type { LegalStatusRow, LegalVersionRow } from './platform-legal-types';

/** The HTTP edge of the operator's legal pages (docs/SPEC.md § 8 row 148): the only code here that knows the endpoints. */
@Injectable({ providedIn: 'root' })
export class PlatformLegalApi {
  private readonly http = inject(HttpClient);

  async overview(): Promise<LegalStatusRow[]> {
    const rows = await firstValueFrom(
      this.http.get<LegalTextStatusLegalTextStatusRead[]>('/api/platform/legal-texts'),
    );
    return rows.map((row) => ({
      page: row.page as LegalPage,
      language: row.language as LegalLanguage,
      written: row.written,
      publishedOn: row.publishedOn ?? null,
      validated: row.validated,
    }));
  }

  async versions(page: LegalPage, language: LegalLanguage): Promise<LegalVersionRow[]> {
    const rows = await firstValueFrom(
      this.http.get<PlatformLegalTextPlatformLegalTextRead[]>(
        this.path(page, language, 'versions'),
      ),
    );
    return rows.map(toRow);
  }

  async write(page: LegalPage, language: LegalLanguage, body: string): Promise<LegalVersionRow> {
    const input: PlatformLegalTextPlatformLegalTextWrite = { body };
    return toRow(
      await firstValueFrom(
        this.http.post<PlatformLegalTextPlatformLegalTextRead>(
          this.path(page, language, 'versions'),
          input,
        ),
      ),
    );
  }

  async validate(page: LegalPage, language: LegalLanguage): Promise<LegalVersionRow> {
    return toRow(
      await firstValueFrom(
        this.http.post<PlatformLegalTextPlatformLegalTextRead>(
          this.path(page, language, 'validate'),
          {},
        ),
      ),
    );
  }

  /**
   * The publisher's and host's identity the legal texts name: every `legal.*` platform setting, in the API's order, by
   * the placeholder a text writes it with (its key without `legal.`), the empty ones included.
   */
  async identity(): Promise<Record<string, string>> {
    const rows = await firstValueFrom(
      this.http.get<SettingSettingRead[]>('/api/platform/settings'),
    );
    const identity: Record<string, string> = {};
    for (const row of rows) {
      const key = row.key ?? '';
      if (key.startsWith(IDENTITY_PREFIX)) {
        identity[key.slice(IDENTITY_PREFIX.length)] =
          typeof row.value === 'string' ? row.value : '';
      }
    }
    return identity;
  }

  async setIdentity(placeholder: string, value: string): Promise<void> {
    await firstValueFrom(
      this.http.put<SettingSettingRead>(
        `/api/platform/settings/${encodeURIComponent(IDENTITY_PREFIX + placeholder)}`,
        { value },
      ),
    );
  }

  private path(page: LegalPage, language: LegalLanguage, action: string): string {
    return `/api/platform/legal-texts/${page}/${language}/${action}`;
  }
}

/** The settings the legal texts' placeholders name (`LegalSettings` in the API). */
const IDENTITY_PREFIX = 'legal.';

function toRow(row: PlatformLegalTextPlatformLegalTextRead): LegalVersionRow {
  return {
    id: row.id,
    body: row.body,
    publishedOn: row.publishedOn,
    validated: row.validated,
    validatedAt: row.validatedAt ?? null,
    validatedBy: row.validatedBy ?? null,
    createdAt: row.createdAt,
    createdBy: row.createdBy ?? null,
  };
}

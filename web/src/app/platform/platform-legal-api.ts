// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  LegalTextStatusLegalTextStatusRead,
  SettingChangesSettingChangesWriteRecordReviseValidationSettingChangesWriteRecordRevise as PlatformSettingChangesBody,
  SettingSettingRead,
  PlatformLegalTextPlatformLegalTextRead,
  PlatformLegalTextPlatformLegalTextWrite,
} from '../api/types.gen';
import type { LegalLanguage } from '../shared/legal/legal-api';
import { isRecordChanged } from '../shared/errors/record-changed';
import type { LegalPage } from '../shared/legal/legal-pages';
import type { LegalIdentity, LegalStatusRow, LegalVersionRow } from './platform-legal-types';

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
      language: row.language,
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
  /** The identity by placeholder, the empty ones included, with each one's row version, 0 where none is stored. */
  async identity(): Promise<LegalIdentity> {
    const rows = await firstValueFrom(
      this.http.get<SettingSettingRead[]>('/api/platform/settings'),
    );
    const values: Record<string, string> = {};
    const versions: Record<string, number> = {};
    for (const row of rows) {
      const key = row.key ?? '';
      if (key.startsWith(IDENTITY_PREFIX)) {
        const placeholder = key.slice(IDENTITY_PREFIX.length);
        values[placeholder] = typeof row.value === 'string' ? row.value : '';
        versions[placeholder] = row.versions?.['platform'] ?? 0;
      }
    }
    return { values, versions };
  }

  /**
   * Every placeholder the form changed, stored together or not at all, each with the version read. `saved_since`: one
   * was saved since, and nothing was kept.
   */
  async saveIdentity(
    changes: readonly { placeholder: string; value: string; version: number }[],
  ): Promise<'saved' | 'saved_since'> {
    const body: PlatformSettingChangesBody = {
      changes: changes.map(({ placeholder, value, version }) => ({
        key: IDENTITY_PREFIX + placeholder,
        value,
        version,
      })),
    };
    try {
      await firstValueFrom(this.http.put<null>('/api/platform/settings', body));
      return 'saved';
    } catch (error) {
      if (
        error instanceof HttpErrorResponse &&
        error.status === 409 &&
        isRecordChanged(error.error)
      ) {
        return 'saved_since';
      }
      throw error;
    }
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

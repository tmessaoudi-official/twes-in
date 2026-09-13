// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { SettingSettingRead } from '../../api/types.gen';
import type {
  SettingChain,
  SettingLevel,
  SettingRow,
  SettingsError,
  SettingSubject,
  SettingType,
} from './settings-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class SettingsRefused extends Error {
  constructor(readonly code: SettingsError) {
    super(code);
  }
}

/** The HTTP edge of the settings engine: the only code here that knows its endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class SettingsApi {
  private readonly http = inject(HttpClient);

  /** A chain as the caller sees it, or as the named customer or customer group does. */
  async chain(
    companyId: string,
    chain: SettingChain,
    subject?: SettingSubject,
  ): Promise<SettingRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<SettingSettingRead[]>(this.path(companyId), {
            params: { chain, ...subject },
          }),
        )
      ).map(toRow),
    );
  }

  async change(
    companyId: string,
    key: string,
    level: SettingLevel,
    value: unknown,
    roleId?: string,
    subject?: SettingSubject,
  ): Promise<SettingRow> {
    const body = { level, value, ...(roleId === undefined ? {} : { roleId }), ...subject };
    return this.guard(async () =>
      toRow(
        await firstValueFrom(
          this.http.put<SettingSettingRead>(
            `${this.path(companyId)}/${encodeURIComponent(key)}`,
            body,
          ),
        ),
      ),
    );
  }

  async reset(
    companyId: string,
    key: string,
    level: SettingLevel,
    roleId?: string,
    subject?: SettingSubject,
  ): Promise<void> {
    const params: Record<string, string> = {
      level,
      ...(roleId === undefined ? {} : { roleId }),
      ...subject,
    };
    await this.guard(() =>
      firstValueFrom(
        this.http.delete<null>(`${this.path(companyId)}/${encodeURIComponent(key)}`, { params }),
      ),
    );
  }

  private path(companyId: string): string {
    return `/api/companies/${encodeURIComponent(companyId)}/settings`;
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      if (error instanceof HttpErrorResponse && error.status === 404) {
        throw new SettingsRefused('not_found');
      }
      if (error instanceof HttpErrorResponse && (error.status === 400 || error.status === 422)) {
        throw new SettingsRefused('invalid');
      }
      throw new SettingsRefused('network');
    }
  }
}

function toRow(raw: SettingSettingRead): SettingRow {
  return {
    key: raw.key ?? '',
    chain: raw.chain as SettingChain,
    type: raw.type as SettingType,
    labelKey: raw.labelKey ?? '',
    module: raw.module ?? '',
    defaultValue: raw.default ?? null,
    value: raw.value ?? null,
    source: (raw.source ?? null) as SettingLevel | null,
    levels: (raw.levels ?? []).map((level) => ({
      level: level.level as SettingLevel,
      value: level.value ?? null,
    })),
    overridableLevels: (raw.overridableLevels ?? []) as SettingLevel[],
    writableLevels: (raw.writableLevels ?? []) as SettingLevel[],
    choices: (raw.choices ?? []).filter((choice): choice is string => choice !== null),
    min: raw.min ?? null,
    max: raw.max ?? null,
    maxLength: raw.maxLength ?? null,
    pattern: raw.pattern ?? null,
  };
}

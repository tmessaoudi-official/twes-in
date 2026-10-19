// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpContext, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  SettingChangesSettingChangesWriteSettingChangesCompanyRecordReviseValidationSettingChangesWriteSettingChangesCompanyRecordRevise as SettingChangesBody,
  SettingSettingRead,
  SettingValuesInUse as SettingValuesInUseRead,
} from '../../api/types.gen';
import type {
  SettingChain,
  SettingLevel,
  SettingRow,
  SettingsError,
  SettingSubject,
  SettingType,
  SettingValuesInUse,
  VersionedSettingChange,
} from './settings-types';
import { SILENT } from '../feedback/activity-interceptor';
import { isRecordChanged } from '../errors/record-changed';

/** A preference written as it is clicked is quiet: nobody waits for it, so it never shows the activity bar. */
const quietly = (quiet: boolean) => new HttpContext().set(SILENT, quiet);

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

  /**
   * What a parties setting holds across the company: the value in force for the company, and the values its customer
   * groups and customers hold of their own, null when the caller may not read customers.
   */
  async valuesInUse(companyId: string, key: string): Promise<SettingValuesInUse> {
    return this.guard(async () => {
      const answer = await firstValueFrom(
        this.http.get<SettingValuesInUseRead>(
          `${this.path(companyId)}/${encodeURIComponent(key)}/in-use`,
        ),
      );
      return { company: answer.company ?? null, parties: answer.parties ?? null };
    });
  }

  async change(
    companyId: string,
    key: string,
    level: SettingLevel,
    value: unknown,
    roleId?: string,
    subject?: SettingSubject,
    quiet = false,
  ): Promise<SettingRow> {
    const body = { level, value, ...(roleId === undefined ? {} : { roleId }), ...subject };
    return this.guard(async () =>
      toRow(
        await firstValueFrom(
          this.http.put<SettingSettingRead>(
            `${this.path(companyId)}/${encodeURIComponent(key)}`,
            body,
            { context: quietly(quiet) },
          ),
        ),
      ),
    );
  }

  /**
   * Every key a page changed at one level, stored together or not at all: each sends the version its row held when
   * read, and one saved since refuses the whole save as `record_changed`.
   */
  async changeAll(
    companyId: string,
    level: SettingLevel,
    changes: readonly VersionedSettingChange[],
    subject?: SettingSubject,
  ): Promise<void> {
    const body: SettingChangesBody = { level, ...subject, changes: changes.map(toApiChange) };
    await this.guard(() => firstValueFrom(this.http.put<null>(this.path(companyId), body)));
  }

  async reset(
    companyId: string,
    key: string,
    level: SettingLevel,
    roleId?: string,
    subject?: SettingSubject,
    quiet = false,
  ): Promise<void> {
    const params: Record<string, string> = {
      level,
      ...(roleId === undefined ? {} : { roleId }),
      ...subject,
    };
    await this.guard(() =>
      firstValueFrom(
        this.http.delete<null>(`${this.path(companyId)}/${encodeURIComponent(key)}`, {
          params,
          context: quietly(quiet),
        }),
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
      if (
        error instanceof HttpErrorResponse &&
        error.status === 409 &&
        isRecordChanged(error.error)
      ) {
        throw new SettingsRefused('record_changed');
      }
      throw new SettingsRefused('network');
    }
  }
}

/** A change as the API takes it: a list is sent as a copy, which the contract types as mutable. */
export function toApiChange({ key, value, version }: VersionedSettingChange) {
  return { key, version, value: typeof value === 'object' && value !== null ? [...value] : value };
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
    versions: (raw.versions ?? {}) as Partial<Record<SettingLevel, number>>,
    overridableLevels: (raw.overridableLevels ?? []) as SettingLevel[],
    writableLevels: (raw.writableLevels ?? []) as SettingLevel[],
    choices: (raw.choices ?? []).filter((choice): choice is string => choice !== null),
    min: raw.min ?? null,
    max: raw.max ?? null,
    maxLength: raw.maxLength ?? null,
    pattern: raw.pattern ?? null,
  };
}

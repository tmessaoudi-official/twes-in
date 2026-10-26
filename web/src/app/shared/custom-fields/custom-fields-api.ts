// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable, Injector } from '@angular/core';
import { formRefusalsIn } from '../form/form-refusals-api';
import { firstValueFrom } from 'rxjs';
import type {
  CustomFieldCustomFieldRead,
  CustomFieldCustomFieldWriteRecordReviseValidationCustomFieldWriteRecordRevise as CustomFieldRevise,
  CustomFieldCustomFieldWriteValidationCustomFieldWrite as CustomFieldCustomFieldWrite,
} from '../../api/types.gen';
import { isRecordChanged } from '../errors/record-changed';
import {
  CUSTOM_FIELD_ENTITIES,
  CUSTOM_FIELD_TYPES,
  type CustomFieldDefinition,
  type CustomFieldEntity,
  type CustomFieldInput,
} from './custom-fields-types';
import { refusedFor } from '../errors/said-already';
import type { FieldRefusal } from '../form/field-refusals';

/** Why the API refused, as the screens translate it. */
export type CustomFieldsError =
  'network' | 'not_found' | 'key_taken' | 'invalid' | 'record_changed';

/** Thrown when the API refuses; carries the code the UI translates. */
export class CustomFieldsRefused extends Error {
  constructor(
    readonly code: CustomFieldsError,
    /** Each field a save was refused on, when the API named them. */
    readonly refusals: readonly FieldRefusal[] = [],
  ) {
    super(code);
  }
}

/** The HTTP edge of custom fields: the only code here that knows their endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class CustomFieldsApi {
  private readonly http = inject(HttpClient);
  private readonly injector = inject(Injector);

  /** Every field of the company for one kind of record, retired ones included, by position then key. */
  async list(companyId: string, entity: CustomFieldEntity): Promise<CustomFieldDefinition[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<CustomFieldCustomFieldRead[]>(path(companyId), { params: { entity } }),
        )
      ).map(toDefinition),
    );
  }

  /** 409 when the company already has a field with this key for this kind of record. */
  async create(companyId: string, input: CustomFieldInput): Promise<CustomFieldDefinition> {
    const body: CustomFieldCustomFieldWrite = { ...input };
    return this.guard(async () =>
      toDefinition(
        await firstValueFrom(this.http.post<CustomFieldCustomFieldRead>(path(companyId), body)),
      ),
    );
  }

  /**
   * The key, the type and the kind of record must say what the field already says. `version` is the one the form
   * was opened at: a later one saved elsewhere answers `record_changed`.
   */
  async revise(
    companyId: string,
    id: string,
    input: CustomFieldInput,
    version: number | null,
  ): Promise<CustomFieldDefinition> {
    const body: CustomFieldRevise = { ...input, version };
    return this.guard(async () =>
      toDefinition(
        await firstValueFrom(
          this.http.put<CustomFieldCustomFieldRead>(
            `${path(companyId)}/${encodeURIComponent(id)}`,
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
      throw refusedFor(
        error,
        new CustomFieldsRefused(codeOf(error), await formRefusalsIn(this.injector, error)),
      );
    }
  }
}

function codeOf(error: unknown): CustomFieldsError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return isRecordChanged(error.error) ? 'record_changed' : 'key_taken';
    default:
      return 'invalid';
  }
}

const path = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/custom-fields`;

function toDefinition(raw: CustomFieldCustomFieldRead): CustomFieldDefinition {
  return {
    id: raw.id ?? '',
    entity: CUSTOM_FIELD_ENTITIES.find((entity) => entity === raw.entity) ?? 'customer',
    key: raw.key ?? '',
    label: raw.label ?? '',
    type: CUSTOM_FIELD_TYPES.find((type) => type === raw.type) ?? 'text',
    required: raw.required ?? false,
    choices: (raw.choices ?? []).filter((choice): choice is string => typeof choice === 'string'),
    sortOrder: raw.sortOrder ?? 0,
    isActive: raw.isActive ?? true,
    // Every read carries it; a 0 would be refused by the API rather than taken for a version.
    version: raw.version ?? 0,
  };
}

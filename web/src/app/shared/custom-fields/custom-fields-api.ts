// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { CustomFieldCustomFieldRead, CustomFieldCustomFieldWrite } from '../../api/types.gen';
import {
  CUSTOM_FIELD_TYPES,
  type CustomFieldDefinition,
  type CustomFieldEntity,
  type CustomFieldInput,
} from './custom-fields-types';

/** Why the API refused, as the screens translate it. */
export type CustomFieldsError = 'network' | 'not_found' | 'key_taken' | 'invalid';

/** Thrown when the API refuses; carries the code the UI translates. */
export class CustomFieldsRefused extends Error {
  constructor(readonly code: CustomFieldsError) {
    super(code);
  }
}

/** The HTTP edge of custom fields: the only code here that knows their endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class CustomFieldsApi {
  private readonly http = inject(HttpClient);

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

  /** The key, the type and the kind of record must say what the field already says. */
  async revise(
    companyId: string,
    id: string,
    input: CustomFieldInput,
  ): Promise<CustomFieldDefinition> {
    const body: CustomFieldCustomFieldWrite = { ...input };
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
      throw new CustomFieldsRefused(codeOf(error));
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
      return 'key_taken';
    default:
      return 'invalid';
  }
}

const path = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/custom-fields`;

function toDefinition(raw: CustomFieldCustomFieldRead): CustomFieldDefinition {
  return {
    id: raw.id ?? '',
    entity: 'customer',
    key: raw.key ?? '',
    label: raw.label ?? '',
    type: CUSTOM_FIELD_TYPES.find((type) => type === raw.type) ?? 'text',
    required: raw.required ?? false,
    choices: (raw.choices ?? []).filter((choice): choice is string => typeof choice === 'string'),
    sortOrder: raw.sortOrder ?? 0,
    isActive: raw.isActive ?? true,
  };
}

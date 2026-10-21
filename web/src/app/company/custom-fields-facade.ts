// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { requestState } from '../shared/feedback/request-state';
import {
  CustomFieldsApi,
  CustomFieldsRefused,
  type CustomFieldsError,
} from '../shared/custom-fields/custom-fields-api';
import type {
  CustomFieldDefinition,
  CustomFieldEntity,
  CustomFieldInput,
} from '../shared/custom-fields/custom-fields-types';

/** The custom fields the company being worked in adds to one kind of record, retired ones included. */
@Injectable({ providedIn: 'root' })
export class CustomFieldsFacade {
  private readonly api = inject(CustomFieldsApi);
  private readonly fieldsSignal = signal<readonly CustomFieldDefinition[]>([]);
  private readonly state = requestState(codeOf);

  readonly fields = this.fieldsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string, entity: CustomFieldEntity): Promise<void> {
    await this.state.read(async () =>
      this.fieldsSignal.set(await this.api.list(companyId, entity)),
    );
  }

  /** True when the API accepted it; the fields of its kind of record are read again. */
  async create(companyId: string, input: CustomFieldInput): Promise<boolean> {
    return this.write(companyId, input.entity, () => this.api.create(companyId, input));
  }

  async revise(
    companyId: string,
    id: string,
    input: CustomFieldInput,
    version: number | null,
  ): Promise<boolean> {
    return this.write(companyId, input.entity, () =>
      this.api.revise(companyId, id, input, version),
    );
  }

  clearError(): void {
    this.state.clearError();
  }

  private async write(
    companyId: string,
    entity: CustomFieldEntity,
    call: () => Promise<unknown>,
  ): Promise<boolean> {
    return this.state.write(async () => {
      await call();
      this.fieldsSignal.set(await this.api.list(companyId, entity));
    });
  }
}

function codeOf(error: unknown): CustomFieldsError {
  return error instanceof CustomFieldsRefused ? error.code : 'network';
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
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
import { QuietReads } from '../shared/feedback/quiet-reads';

/** The custom fields the company being worked in adds to one kind of record, retired ones included. */
@Injectable({ providedIn: 'root' })
export class CustomFieldsFacade {
  private readonly api = inject(CustomFieldsApi);
  private readonly quietReads = inject(QuietReads);
  private readonly fieldsSignal = signal<readonly CustomFieldDefinition[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<CustomFieldsError | null>(null);

  readonly fields = this.fieldsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string, entity: CustomFieldEntity): Promise<void> {
    // A reload nobody asked for leaves busy as it is, so no action turns disabled under a pointer (QuietReads).
    const marked = !this.quietReads.quiet();
    if (marked) this.busySignal.set(true);
    try {
      this.fieldsSignal.set(await this.api.list(companyId, entity));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      if (marked) this.busySignal.set(false);
    }
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
    this.errorSignal.set(null);
  }

  private async write(
    companyId: string,
    entity: CustomFieldEntity,
    call: () => Promise<unknown>,
  ): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      this.fieldsSignal.set(await this.api.list(companyId, entity));
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): CustomFieldsError {
  return error instanceof CustomFieldsRefused ? error.code : 'network';
}

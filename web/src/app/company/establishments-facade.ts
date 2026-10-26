// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { requestState } from '../shared/feedback/request-state';
import { CompanyApi, CompanyRefused } from './company-api';
import type {
  CompanyError,
  EstablishmentInput,
  EstablishmentRow,
  NumberingChanges,
  NumberingSeriesRow,
} from './company-types';
import { sessionSignal } from '../shared/session/session-data';
import type { FieldRefusal } from '../shared/form/field-refusals';

/** The establishments of the company being worked in, and how each of them numbers its documents. */
@Injectable({ providedIn: 'root' })
export class EstablishmentsFacade {
  private readonly api = inject(CompanyApi);
  private readonly establishmentsSignal = sessionSignal<readonly EstablishmentRow[]>([]);
  private readonly seriesSignal = sessionSignal<readonly NumberingSeriesRow[]>([]);
  private readonly state = requestState(codeOf);
  private readonly refusalsSignal = sessionSignal<readonly FieldRefusal[]>([]);

  readonly establishments = this.establishmentsSignal.asReadonly();
  readonly series = this.seriesSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  /** The fields the last save was refused on, for the form to say under each. */
  readonly refusals = this.refusalsSignal.asReadonly();

  async loadEstablishments(companyId: string): Promise<void> {
    await this.state.read(async () =>
      this.establishmentsSignal.set(await this.api.establishments(companyId)),
    );
  }

  async loadSeries(companyId: string): Promise<void> {
    await this.state.read(async () =>
      this.seriesSignal.set(await this.api.numberingSeries(companyId)),
    );
  }

  /** True when the API accepted it; the list is read again, since a change of default touches two rows. */
  async createEstablishment(companyId: string, input: EstablishmentInput): Promise<boolean> {
    return this.write(
      () => this.api.createEstablishment(companyId, input),
      async () => this.establishmentsSignal.set(await this.api.establishments(companyId)),
    );
  }

  async reviseEstablishment(
    companyId: string,
    id: string,
    input: EstablishmentInput,
    version: number | null,
  ): Promise<boolean> {
    return this.write(
      () => this.api.reviseEstablishment(companyId, id, input, version),
      async () => this.establishmentsSignal.set(await this.api.establishments(companyId)),
    );
  }

  async reviseSeries(
    companyId: string,
    id: string,
    changes: NumberingChanges,
    version: number | null,
  ): Promise<boolean> {
    return this.write(
      () => this.api.reviseNumberingSeries(companyId, id, changes, version),
      async () => this.seriesSignal.set(await this.api.numberingSeries(companyId)),
    );
  }

  clearError(): void {
    this.state.clearError();
  }

  private async write(call: () => Promise<unknown>, reload: () => Promise<void>): Promise<boolean> {
    this.refusalsSignal.set([]);
    return this.state.write(
      async () => {
        await call();
        await reload();
      },
      {
        failed: (error) =>
          this.refusalsSignal.set(error instanceof CompanyRefused ? error.refusals : []),
      },
    );
  }
}

function codeOf(error: unknown): CompanyError {
  return error instanceof CompanyRefused ? error.code : 'network';
}

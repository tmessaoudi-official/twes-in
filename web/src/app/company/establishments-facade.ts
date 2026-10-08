// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { CompanyApi, CompanyRefused } from './company-api';
import type {
  CompanyError,
  EstablishmentInput,
  EstablishmentRow,
  NumberingChanges,
  NumberingSeriesRow,
} from './company-types';

/** The establishments of the company being worked in, and how each of them numbers its documents. */
@Injectable({ providedIn: 'root' })
export class EstablishmentsFacade {
  private readonly api = inject(CompanyApi);
  private readonly establishmentsSignal = signal<readonly EstablishmentRow[]>([]);
  private readonly seriesSignal = signal<readonly NumberingSeriesRow[]>([]);
  private readonly busySignal = signal(false);
  private reads = 0;
  private readonly errorSignal = signal<CompanyError | null>(null);

  readonly establishments = this.establishmentsSignal.asReadonly();
  readonly series = this.seriesSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async loadEstablishments(companyId: string): Promise<void> {
    await this.read(async () =>
      this.establishmentsSignal.set(await this.api.establishments(companyId)),
    );
  }

  async loadSeries(companyId: string): Promise<void> {
    await this.read(async () => this.seriesSignal.set(await this.api.numberingSeries(companyId)));
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
  ): Promise<boolean> {
    return this.write(
      () => this.api.reviseEstablishment(companyId, id, input),
      async () => this.establishmentsSignal.set(await this.api.establishments(companyId)),
    );
  }

  async reviseSeries(companyId: string, id: string, changes: NumberingChanges): Promise<boolean> {
    return this.write(
      () => this.api.reviseNumberingSeries(companyId, id, changes),
      async () => this.seriesSignal.set(await this.api.numberingSeries(companyId)),
    );
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async read(load: () => Promise<void>): Promise<void> {
    this.reads++;
    this.busySignal.set(true);
    // Cleared when a read starts, never when one ends, so a read answering after another failed does not hide it.
    this.errorSignal.set(null);
    try {
      await load();
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      // Several reads run at once (a list and what its filters name): busy until the last one answers.
      if (--this.reads === 0) this.busySignal.set(false);
    }
  }

  private async write(call: () => Promise<unknown>, reload: () => Promise<void>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      await reload();
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): CompanyError {
  return error instanceof CompanyRefused ? error.code : 'network';
}

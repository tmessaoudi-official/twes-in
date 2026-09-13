// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { FiscalApi, FiscalRefused } from './fiscal-api';
import type {
  CustomerTaxRegimeRow,
  FiscalError,
  TaxComponentInput,
  TaxComponentRow,
  UnitInput,
  UnitRow,
} from './fiscal-types';

/** The fiscal setup of one company: its taxes, its units and the regimes its customers may be under. */
@Injectable({ providedIn: 'root' })
export class FiscalFacade {
  private readonly api = inject(FiscalApi);
  private readonly taxesSignal = signal<readonly TaxComponentRow[]>([]);
  private readonly unitsSignal = signal<readonly UnitRow[]>([]);
  private readonly regimesSignal = signal<readonly CustomerTaxRegimeRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<FiscalError | null>(null);

  readonly taxes = this.taxesSignal.asReadonly();
  readonly units = this.unitsSignal.asReadonly();
  readonly regimes = this.regimesSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async loadTaxes(companyId: string): Promise<void> {
    await this.read(async () => {
      const [taxes, regimes] = await Promise.all([
        this.api.taxComponents(companyId),
        this.api.customerTaxRegimes(companyId),
      ]);
      this.taxesSignal.set(taxes);
      this.regimesSignal.set(regimes);
    });
  }

  async loadUnits(companyId: string): Promise<void> {
    await this.read(async () => this.unitsSignal.set(await this.api.units(companyId)));
  }

  /** True when the API accepted it; the list is read again either way it succeeds. */
  async createTax(companyId: string, input: TaxComponentInput): Promise<boolean> {
    return this.write(
      () => this.api.createTaxComponent(companyId, input),
      async () => this.taxesSignal.set(await this.api.taxComponents(companyId)),
    );
  }

  async reviseTax(companyId: string, id: string, input: TaxComponentInput): Promise<boolean> {
    return this.write(
      () => this.api.reviseTaxComponent(companyId, id, input),
      async () => this.taxesSignal.set(await this.api.taxComponents(companyId)),
    );
  }

  async createUnit(companyId: string, input: UnitInput): Promise<boolean> {
    return this.write(
      () => this.api.createUnit(companyId, input),
      async () => this.unitsSignal.set(await this.api.units(companyId)),
    );
  }

  async reviseUnit(companyId: string, id: string, input: UnitInput): Promise<boolean> {
    return this.write(
      () => this.api.reviseUnit(companyId, id, input),
      async () => this.unitsSignal.set(await this.api.units(companyId)),
    );
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async read(load: () => Promise<void>): Promise<void> {
    this.busySignal.set(true);
    try {
      await load();
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
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

function codeOf(error: unknown): FiscalError {
  return error instanceof FiscalRefused ? error.code : 'network';
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { VendorsApi, VendorsRefused } from './vendors-api';
import type { VendorInput, VendorOptions, VendorRow, VendorsError } from './vendors-types';

/** The vendors of the company being worked in, and the one open in the form. */
@Injectable({ providedIn: 'root' })
export class VendorsFacade {
  private readonly api = inject(VendorsApi);
  private readonly vendorsSignal = signal<readonly VendorRow[]>([]);
  private readonly optionsSignal = signal<VendorOptions | null>(null);
  private readonly vendorSignal = signal<VendorRow | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<VendorsError | null>(null);

  readonly vendors = this.vendorsSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  readonly vendor = this.vendorSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async loadList(companyId: string): Promise<void> {
    await this.read(async () => this.vendorsSignal.set(await this.api.vendors(companyId)));
  }

  /** What the vendor form needs: its options, and the vendor unless it is new. */
  async loadVendor(companyId: string, id: string | null): Promise<void> {
    await this.read(async () => {
      const [options, vendor] = await Promise.all([
        this.api.options(companyId),
        id === null ? Promise.resolve(null) : this.api.vendor(companyId, id),
      ]);
      this.optionsSignal.set(options);
      this.vendorSignal.set(vendor);
    });
  }

  /** The vendor as the API kept it, or null with the reason in `error`. */
  async createVendor(companyId: string, input: VendorInput): Promise<VendorRow | null> {
    return this.save(() => this.api.createVendor(companyId, input));
  }

  async reviseVendor(companyId: string, id: string, input: VendorInput): Promise<VendorRow | null> {
    return this.save(() => this.api.reviseVendor(companyId, id, input));
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

  private async save(call: () => Promise<VendorRow>): Promise<VendorRow | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const vendor = await call();
      this.vendorSignal.set(vendor);
      return vendor;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): VendorsError {
  return error instanceof VendorsRefused ? error.code : 'network';
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import type { ExportFormat } from '../shared/list/export-address';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { StepUp } from '../shared/step-up/step-up';
import { VendorsApi, VendorsRefused } from './vendors-api';
import type {
  VendorInput,
  VendorOptions,
  VendorRow,
  VendorSearch,
  VendorsError,
} from './vendors-types';
import { QuietReads } from '../shared/feedback/quiet-reads';

/** The vendors of the company being worked in, and the one open in the form. */
@Injectable({ providedIn: 'root' })
export class VendorsFacade {
  private readonly api = inject(VendorsApi);
  private readonly quietReads = inject(QuietReads);
  private readonly stepUp = inject(StepUp);
  private readonly vendorsSignal = signal<readonly VendorRow[]>([]);
  private readonly totalSignal = signal(0);
  private pageRequest = 0;
  private readonly optionsSignal = signal<VendorOptions | null>(null);
  private readonly vendorSignal = signal<VendorRow | null>(null);
  private readonly busySignal = signal(false);
  private reads = 0;
  private readonly errorSignal = signal<VendorsError | null>(null);
  private readonly refusalsSignal = signal<readonly FieldRefusal[]>([]);

  readonly vendors = this.vendorsSignal.asReadonly();
  /** How many vendors the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  readonly vendor = this.vendorSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  /** The fields the last save was refused on, each with the reason the screens translate. */
  readonly refusals = this.refusalsSignal.asReadonly();

  exportUrl(companyId: string, search: VendorSearch, format: ExportFormat): string {
    return this.api.exportUrl(companyId, search, format);
  }

  /**
   * One page of the vendors the search finds. Only the latest search's answer is shown: typing sends one search per
   * pause, and an earlier one answering late would otherwise put back rows the words no longer find.
   */
  async loadPage(companyId: string, search: VendorSearch): Promise<void> {
    const request = ++this.pageRequest;
    await this.read(async () => {
      const page = await this.api.vendors(companyId, search);
      if (request !== this.pageRequest) return;
      this.vendorsSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
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

  async reviseVendor(
    companyId: string,
    id: string,
    input: VendorInput,
    version: number | null,
  ): Promise<VendorRow | null> {
    return this.save(() => this.api.reviseVendor(companyId, id, input, version));
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async read(load: () => Promise<void>): Promise<void> {
    // A reload nobody asked for leaves busy as it is, so no action turns disabled under a pointer (QuietReads).
    const marked = !this.quietReads.quiet();
    if (marked) {
      this.reads++;
      this.busySignal.set(true);
    }
    // Cleared when a read starts, never when one ends, so a read answering after another failed does not hide it.
    this.errorSignal.set(null);
    try {
      await load();
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      // Several reads run at once (a list and what its filters name): busy until the last one answers.
      if (marked && --this.reads === 0) this.busySignal.set(false);
    }
  }

  private async save(call: () => Promise<VendorRow>): Promise<VendorRow | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    this.refusalsSignal.set([]);
    try {
      // A vendor's bank details, set or changed, wait for the person to prove who they are again (docs/SPEC.md § 7).
      const vendor = await this.stepUp.retrying(
        call,
        (error) => error instanceof VendorsRefused && error.code === 'step_up_required',
        'step_up.intro_sensitive',
      );
      this.vendorSignal.set(vendor);
      return vendor;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      this.refusalsSignal.set(error instanceof VendorsRefused ? error.refusals : []);
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): VendorsError {
  return error instanceof VendorsRefused ? error.code : 'network';
}

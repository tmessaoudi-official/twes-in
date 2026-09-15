// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { vendorForm, vendorInput, vendorValues } from './vendor-forms';
import { VendorsFacade } from './vendors-facade';

/** One vendor: a new one to fill in, or an existing one to revise or deactivate. */
@Component({
  selector: 'app-vendor-page',
  imports: [MatButtonModule, MatCardModule, RouterLink, TranslatePipe, DescriptorForm],
  templateUrl: './vendor-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VendorPage {
  private readonly facade = inject(VendorsFacade);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `vendors/new`. */
  readonly vendorId = input<string | undefined>(undefined);

  protected readonly id = computed(() => this.vendorId() ?? null);
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('vendor.write'));
  protected readonly saved = signal(false);

  /** Null while a new vendor is filled in; undefined until the vendor asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const vendor = this.facade.vendor();
    return vendor?.id === id ? vendor : undefined;
  });
  protected readonly descriptor = computed(() => {
    const options = this.facade.options();
    return options === null ? null : vendorForm(options);
  });
  /**
   * What the form is of: the vendor and the fields shown. Reading the vendor again yields new objects with the same
   * content, and must not rebuild the form over what is being typed; another vendor or other fields must.
   */
  private readonly formKey = computed(() => {
    const descriptor = this.descriptor();
    const current = this.current();
    if (descriptor === null || current === undefined) return null;
    return `${current?.id ?? 'new'}|${JSON.stringify(descriptor)}`;
  });
  protected readonly form = computed(() => {
    if (this.formKey() === null) return null;
    return untracked(() => {
      const descriptor = this.descriptor();
      const options = this.facade.options();
      const current = this.current();
      if (descriptor === null || options === null || current === undefined) return null;
      return buildFormGroup(descriptor, vendorValues(current, options));
    });
  });

  constructor() {
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        this.saved.set(false);
        if (companyId) {
          void this.facade.loadVendor(companyId, id);
        }
      });
    });
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const options = this.facade.options();
    if (!companyId || options === null || this.busy()) return;
    const input = vendorInput(values, options);
    const id = this.id();
    this.saved.set(false);
    if (id === null) {
      const created = await this.facade.createVendor(companyId, input);
      if (created !== null) {
        await this.router.navigate(['/vendors', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.reviseVendor(companyId, id, input)) !== null) {
      this.saved.set(true);
    }
  }
}

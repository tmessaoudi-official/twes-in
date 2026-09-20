// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { liveRecord } from '../shared/form/live-record';
import { RecordChanged } from '../shared/form/record-changed';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { vendorForm, vendorInput, vendorValues } from './vendor-forms';
import { VendorsFacade } from './vendors-facade';
import { Feedback } from '../shared/feedback/feedback';
import { revertToSaved, unsavedChanges } from '../shared/form/dirty-count';
import { RecordBar } from '../shared/form/record-bar';

/** One vendor: a new one to fill in, or an existing one to revise or deactivate. */
@Component({
  selector: 'app-vendor-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    DescriptorForm,
    RecordBar,
    RecordChanged,
  ],
  templateUrl: './vendor-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VendorPage {
  private readonly facade = inject(VendorsFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `vendors/new`. */
  readonly vendorId = input<string | undefined>(undefined);

  protected readonly id = computed(() => this.vendorId() ?? null);
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('vendor.write'));

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

  /** The saved version the form stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'vendor',
    id: this.id,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      const id = this.id();
      if (companyId && id !== null) await this.facade.loadVendor(companyId, id);
    },
    saved: () => {
      const current = this.current();
      const options = this.facade.options();
      return current && options ? vendorValues(current, options) : null;
    },
  });

  /** What the form holds that the API has not been told yet; the bar beside the title shows it. */
  protected readonly savedValues = computed(() => {
    const current = this.current();
    const options = this.facade.options();
    return current && options ? vendorValues(current, options) : null;
  });
  protected readonly changes = unsavedChanges(this.form, this.savedValues);

  constructor() {
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        if (companyId) {
          void this.facade.loadVendor(companyId, id);
        }
      });
    });
  }

  /** From the bar beside the title, which holds no form of its own. */
  protected saveFromBar(): void {
    const form = this.form();
    if (form === null) return;
    if (form.invalid) {
      form.markAllAsTouched();
      return;
    }
    void this.save(form.getRawValue());
  }

  protected revert(): void {
    const form = this.form();
    const saved = this.savedValues();
    if (form !== null && saved !== null) revertToSaved(form, saved);
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const options = this.facade.options();
    if (!companyId || options === null || this.busy()) return;
    const input = vendorInput(values, options);
    const id = this.id();
    if (id === null) {
      const created = await this.facade.createVendor(companyId, input);
      if (created !== null) {
        this.feedback.success('vendors.saved');
        await this.router.navigate(['/vendors', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.reviseVendor(companyId, id, input)) !== null) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('vendors.saved');
    }
  }
}

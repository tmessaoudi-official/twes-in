// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  untracked,
  viewChild,
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
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { revertToSaved, unsavedChanges } from '../shared/form/dirty-count';
import { ScreenActions } from '../shared/actions/screen-actions';
import type { ScreenAction } from '../shared/actions/screen-action';
import { RecordBar } from '../shared/form/record-bar';
import { PURCHASES_MODULE } from '../purchases/purchases-nav';
import { RecordDeletes } from '../shared/records/record-deletes';

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
  private readonly unsaved = inject(UnsavedChanges);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly deletes = inject(RecordDeletes);

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
  /** The vendor kept when this one was merged into it: this one stays only for what already names it. */
  protected readonly mergedAway = computed(() => this.current()?.mergedInto ?? null);
  /** A vendor merged away is kept as it was, whoever looks at it. */
  protected readonly editable = computed(() => this.mayWrite() && this.mergedAway() === null);
  protected readonly descriptor = computed(() => {
    const options = this.facade.options();
    return options === null
      ? null
      : vendorForm(options, this.current()?.defaultExpenseCategoryId ?? null);
  });
  /**
   * What the form is of: the vendor and the fields shown. Reading the vendor again yields new objects with the same
   * content, and must not rebuild the form over what is being typed; another vendor or other fields must.
   */
  private readonly formKey = computed(() => {
    const options = this.facade.options();
    const current = this.current();
    if (options === null || current === undefined) return null;
    // Without the category the vendor holds: another person's save that moves it off an archived one changes the
    // choices offered, not the fields, and is merged as a change like any other.
    return `${current?.id ?? 'new'}|${JSON.stringify(vendorForm(options))}`;
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
    version: () => this.current()?.version ?? null,
  });

  /** What the form holds that the API has not been told yet; the bar beside the title shows it. */
  protected readonly savedValues = computed(() => {
    const current = this.current();
    const options = this.facade.options();
    return current && options ? vendorValues(current, options) : null;
  });
  protected readonly changes = unsavedChanges(this.form, this.savedValues);

  constructor() {
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet (row 45).
    inject(ScreenActions).declare(this.recordActions);
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

  /**
   * What this page offers, declared once (row 45): the bar beside the title draws it, and the keyboard, the Ctrl K
   * palette and the "?" sheet read the same list — so "s" saves here as it does on a document.
   */
  protected readonly recordActions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    const changes = this.changes();
    const may = this.editable();
    return [
      {
        id: 'save',
        label: 'form.save',
        icon: 'save',
        primary: true,
        shortcut: 's',
        // A save that is always available teaches nothing about whether there is anything to save.
        disabled: busy || changes === 0,
        run: () => this.saveFromBar(),
        shown: may,
      },
      {
        id: 'revert',
        label: 'form.revert',
        disabled: busy,
        run: () => this.revert(),
        shown: may && changes > 0,
      },
      {
        id: 'new-purchase',
        label: 'nav.new_purchase',
        icon: 'shopping_cart',
        run: () => void this.orderFrom(),
        // An order names an active vendor only, for whoever may write orders while the company buys through them.
        shown:
          this.current()?.isActive === true &&
          this.auth.hasModule(PURCHASES_MODULE) &&
          this.auth.hasPermission('purchase.write'),
      },
      {
        id: 'delete',
        label: 'records.delete',
        icon: 'delete',
        // Asks nothing: the toast offers « Annuler », and « Récemment supprimés » keeps it a day. A vendor something
        // still names is refused whole, the toast saying what names it.
        destructive: true,
        undoable: true,
        disabled: busy,
        run: () => void this.remove(),
        shown: may && this.current() != null,
      },
    ];
  });

  private async remove(): Promise<void> {
    const companyId = this.company()?.id;
    const vendor = this.current();
    if (!companyId || !vendor) return;
    const gone = await this.deletes.remove(companyId, 'vendor', [
      { id: vendor.id, label: `${vendor.number} ${vendor.name}` },
    ]);
    if (!gone) return;
    // What was typed went with it: leaving is not abandoning unsaved work.
    this.unsaved.savedAndLeaving();
    await this.router.navigate(['/vendors']);
  }

  /** A new order to this vendor, the vendor already named in its header. */
  private async orderFrom(): Promise<void> {
    const id = this.current()?.id;
    if (id) await this.router.navigate(['/purchases/new'], { queryParams: { vendorId: id } });
  }

  /** The record's own form, which shows what a save from the bar refused and puts the person on it. */
  private readonly mainForm = viewChild<DescriptorForm>('mainForm');

  /** From the bar beside the title, which holds no form of its own. */
  protected saveFromBar(): void {
    const form = this.form();
    if (form === null) return;
    if (form.invalid) {
      const shown = this.mainForm();
      if (shown) shown.showErrors();
      else form.markAllAsTouched();
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
    const input = vendorInput(values, options, this.current()?.defaultExpenseCategoryId ?? null);
    const id = this.id();
    if (id === null) {
      const created = await this.facade.createVendor(companyId, input);
      if (created !== null) {
        this.feedback.success('vendors.saved');
        // It exists now: going to it is not leaving unsaved work, though the form still holds what was
        // typed and the record holds what the API answered (row 45's leave guard, 2026-09-20).
        this.unsaved.savedAndLeaving();
        await this.router.navigate(['/vendors', created.id], { replaceUrl: true });
      } else {
        this.showRefused();
      }
    } else if (
      (await this.facade.reviseVendor(companyId, id, input, this.sync.version())) !== null
    ) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('vendors.saved');
    } else if (this.facade.error() === 'record_changed') {
      this.facade.clearError();
      await this.sync.savedElsewhere();
    } else {
      this.showRefused();
    }
  }

  /** Each field the API refused said on that field; the line above the form stays for what it says as a whole. */
  private showRefused(): void {
    const refused = this.facade.refusals();
    if (refused.length > 0) this.mainForm()?.showRefused(refused);
  }
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DOCUMENT,
  effect,
  inject,
  input,
  linkedSignal,
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
import { ArticleDefaults } from './article-defaults';
import { ProductBarcodesSection } from './product-barcodes';
import { ProductHomes } from './product-homes-facade';
import { ProductHomesSection } from './product-homes';
import { CustomerView } from '../shared/customer-view/customer-view';
import { productForm, productInput, productValues } from './product-forms';
import { ProductsFacade } from './products-facade';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { revertToSaved, unsavedChanges } from '../shared/form/dirty-count';
import { ScreenActions } from '../shared/actions/screen-actions';
import type { ScreenAction } from '../shared/actions/screen-action';
import { RecordBar } from '../shared/form/record-bar';
import { MatTabsModule } from '@angular/material/tabs';

/** One product: a new one to fill in, or an existing one to revise. */
@Component({
  selector: 'app-product-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    DescriptorForm,
    MatTabsModule,
    RecordBar,
    RecordChanged,
    ArticleDefaults,
    ProductHomesSection,
    ProductBarcodesSection,
  ],
  templateUrl: './product-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
  // Its own instance per product screen: what one product's homes are is not shared state.
  providers: [ProductHomes],
})
export class ProductPage {
  private readonly facade = inject(ProductsFacade);
  private readonly unsaved = inject(UnsavedChanges);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  protected readonly customerView = inject(CustomerView);
  private readonly router = inject(Router);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `products/new`. */
  readonly productId = input<string | undefined>(undefined);
  /** `?tab=codes` opens the product on its codes: where a scan's card sends a person. */
  readonly tab = input<string | undefined>(undefined);
  /** `?barcode=` on a new product: the code a scan card found nobody holding, listed on its codes once it exists. */
  readonly barcode = input<string | undefined>(undefined);
  /** `?add=` on a product: a code a scan card sent to be added, listed on its codes waiting to be saved. */
  readonly add = input<string | undefined>(undefined);

  private readonly document = inject(DOCUMENT);
  protected readonly id = computed(() => this.productId() ?? null);
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('product.write'));

  /** Null while a new product is filled in; undefined until the product asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const product = this.facade.product();
    return product?.id === id ? product : undefined;
  });
  /**
   * The tab on view. It starts on the one the address names once that tab exists — the codes tab only appears when
   * the product has been read — and a reload of the same product leaves the person's own choice alone.
   */
  protected readonly selectedTab = linkedSignal<string, number>({
    source: () => `${this.tab() ?? ''}|${this.current() != null}`,
    computation: (key: string) => (key === 'codes|true' ? 1 : 0),
  });
  protected readonly descriptor = computed(() => {
    const options = this.facade.options();
    return options === null
      ? null
      : productForm(options, this.facade.categories(), this.facade.customFields(), {
          cost: this.auth.hasPermission('product.cost.read') && !this.customerView.hides('cost'),
        });
  });
  /**
   * What the form is of: the product and the fields shown. Reading the product again yields new objects with the same
   * content, and must not rebuild the form over what is being typed; another product or other fields must.
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
      return buildFormGroup(
        descriptor,
        productValues(current, options, this.facade.customFields(), this.facade.defaultUnitCode()),
      );
    });
  });

  /** The saved version the form stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'product',
    id: this.id,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      const id = this.id();
      if (companyId && id !== null) await this.facade.loadProduct(companyId, id);
    },
    saved: () => {
      const current = this.current();
      const options = this.facade.options();
      return current && options
        ? productValues(current, options, this.facade.customFields(), this.facade.defaultUnitCode())
        : null;
    },
  });

  /** What the form holds that the API has not been told yet; the bar beside the title shows it. */
  protected readonly savedValues = computed(() => {
    const current = this.current();
    const options = this.facade.options();
    return current && options
      ? productValues(current, options, this.facade.customFields(), this.facade.defaultUnitCode())
      : null;
  });
  protected readonly changes = unsavedChanges(this.form, this.savedValues);

  /**
   * Where the product normally lives, offered once it exists and to anybody who may see the warehouse
   * (docs/SPEC.md row 101): choosing a home means reading a list of locations, and nobody points at a shelf they
   * are not allowed to see. Setting one is the PRODUCT's own permission, so `product.write` decides whether the
   * tab is editable — a reader sees where the product lives and changes nothing.
   */
  protected readonly homesOf = computed(() => {
    const current = this.current();
    if (!current || !this.auth.hasModule('inventory') || !this.auth.hasPermission('stock.read')) {
      return null;
    }
    return current.id;
  });

  /** The subject of the defaults panel: the product once it exists. */
  protected readonly defaultsSubject = computed(() => {
    const current = this.current();
    return current ? { productId: current.id } : null;
  });

  constructor() {
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet (row 45).
    inject(ScreenActions).declare(this.recordActions);
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        if (companyId) {
          void this.facade.loadProduct(companyId, id);
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
    const may = this.mayWrite();
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
        // Printed labels (docs/SPEC.md § 7, 2026-09-23 slice 8), in a tab of their own to print.
        id: 'labels',
        label: 'products.labels.open',
        icon: 'label',
        rare: true,
        run: () => this.openLabels(),
        shown: this.id() !== null,
      },
    ];
  });

  private openLabels(): void {
    const id = this.id();
    if (id !== null) this.document.defaultView?.open(`/print/product-labels/${id}`, '_blank');
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
    const input = productInput(values, options, this.facade.customFields(), this.current() ?? null);
    const id = this.id();
    if (id === null) {
      const created = await this.facade.createProduct(companyId, input);
      if (created !== null) {
        this.feedback.success('products.saved');
        // It exists now: going to it is not leaving unsaved work, though the form still holds what was
        // typed and the record holds what the API answered (row 45's leave guard, 2026-09-20).
        this.unsaved.savedAndLeaving();
        const barcode = this.barcode();
        await this.router.navigate(
          ['/products', created.id],
          barcode === undefined
            ? { replaceUrl: true }
            : { replaceUrl: true, queryParams: { tab: 'codes', add: barcode } },
        );
      }
    } else if ((await this.facade.reviseProduct(companyId, id, input)) !== null) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('products.saved');
    }
  }
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  DOCUMENT,
  effect,
  inject,
  input,
  linkedSignal,
  signal,
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
import type { FormGroup } from '@angular/forms';
import { ArticleDefaults } from './article-defaults';
import { PriceCalculator } from './price-calculator';
import { ProductBarcodesSection } from './product-barcodes';
import { ProductHomes } from './product-homes-facade';
import { ProductHomesSection } from './product-homes';
import { ProductReorderPoints } from './product-reorder-points-facade';
import { ProductReorderPointsSection } from './product-reorder-points';
import { ProductCostHistory } from './product-cost-history-facade';
import { ProductCostHistorySection } from './product-cost-history';
import { RecordHistory } from '../activity/record-history';
import { ProductSubstitutes } from './product-substitutes-facade';
import { ProductPhotos } from './product-photos-facade';
import { ProductPhotosSection } from './product-photos';
import { ProductThumbnail } from './product-thumbnail';
import { ProductSubstitutesSection } from './product-substitutes';
import { productForm, productInput, productValues } from './product-forms';
import { ProductsFacade } from './products-facade';
import { ProductOnView } from './product-on-view';
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
    ProductReorderPointsSection,
    ProductSubstitutesSection,
    ProductCostHistorySection,
    RecordHistory,
    ProductBarcodesSection,
    ProductPhotosSection,
    ProductThumbnail,
    PriceCalculator,
  ],
  templateUrl: './product-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
  // Its own instance per product screen: what one product's homes are is not shared state.
  providers: [
    ProductHomes,
    ProductReorderPoints,
    ProductSubstitutes,
    ProductCostHistory,
    ProductPhotos,
  ],
})
export class ProductPage {
  private readonly facade = inject(ProductsFacade);
  private readonly unsaved = inject(UnsavedChanges);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
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
  /** What the form offers as a field is typed: the substitution groups the company's products already carry. */
  protected readonly suggestions = computed(() => ({
    substitutionGroup: this.facade.substitutionGroups(),
  }));

  /** Null while a new product is filled in; undefined until the product asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const product = this.facade.product();
    return product?.id === id ? product : undefined;
  });
  /** How many units each of the product's packs holds, for the calculator's price per pack; none before it is saved. */
  protected readonly packCounts = computed(
    () =>
      this.current()
        ?.barcodes.filter((code) => code.role === 'pack')
        .map((code) => code.quantity) ?? [],
  );
  /**
   * The tab on view. It starts on the one the address names once that tab exists — the codes tab only appears when
   * the product has been read — and a reload of the same product leaves the person's own choice alone. The key is a
   * `computed` of its own: a linkedSignal reruns whenever what its source READS changes, not when what it returns
   * does, so a save answering with the product anew snapped an opened tab back to the record (CI e1b629d5).
   */
  private readonly tabKey = computed(() => `${this.tab() ?? ''}|${this.current() != null}`);
  protected readonly selectedTab = linkedSignal<string, number>({
    source: this.tabKey,
    computation: (key: string) => (key === 'codes|true' ? 1 : 0),
  });
  /** The cost is read by whoever holds product.cost.read. */
  protected readonly showsCost = computed(() => this.auth.hasPermission('product.cost.read'));
  protected readonly currencyScale = computed(() => this.facade.options()?.currencyScale ?? 2);
  protected readonly currency = computed(() => this.facade.options()?.currency ?? '');
  private readonly gallery = inject(ProductPhotos);
  /**
   * The main photo beside the title: the gallery's once it is read here, since a change made on this tab never comes
   * back to it as a live change, else the one the product was read with.
   */
  protected readonly mainPhotoId = computed(() => {
    const current = this.current();
    if (!current) return null;
    return this.gallery.loadedFor() === current.id
      ? (this.gallery.main()?.id ?? null)
      : current.mainPhotoId;
  });
  protected readonly photosPerProduct = computed(
    () => this.facade.options()?.photosPerProduct ?? 6,
  );
  protected readonly photoMaxBytes = computed(() => this.facade.options()?.photoMaxBytes ?? null);
  protected readonly descriptor = computed(() => {
    const options = this.facade.options();
    return options === null
      ? null
      : productForm(options, this.facade.categories(), this.facade.customFields(), {
          cost: this.showsCost(),
          newProduct: this.id() === null,
        });
  });
  /**
   * The reference a new product would be given if it were saved now, shown in its field so it can be kept or changed
   * (docs/SPEC.md § 7, 2026-09-17 (3)); null until the API said it, and on an existing product.
   */
  private readonly proposal = signal<string | null>(null);
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
        productValues(
          current,
          options,
          this.facade.customFields(),
          this.facade.defaultUnitCode(),
          this.facade.defaultTracking(),
        ),
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
        ? productValues(
            current,
            options,
            this.facade.customFields(),
            this.facade.defaultUnitCode(),
            this.facade.defaultTracking(),
          )
        : null;
    },
  });

  /** What the form holds that the API has not been told yet; the bar beside the title shows it. */
  protected readonly savedValues = computed(() => {
    const current = this.current();
    const options = this.facade.options();
    if (current === null && options !== null) {
      // A new product is measured against what it opened with, the reference proposed included: a form nobody touched
      // has nothing unsaved, and leaving it asks nothing.
      const proposal = this.proposal();
      return proposal === null
        ? null
        : {
            ...productValues(
              null,
              options,
              this.facade.customFields(),
              this.facade.defaultUnitCode(),
              this.facade.defaultTracking(),
            ),
            reference: proposal,
          };
    }
    return current && options
      ? productValues(
          current,
          options,
          this.facade.customFields(),
          this.facade.defaultUnitCode(),
          this.facade.defaultTracking(),
        )
      : null;
  });
  protected readonly changes = unsavedChanges(this.form, this.savedValues);

  /**
   * Where the product normally lives, offered once it exists and to anybody who may see the warehouse
   * (docs/SPEC.md row 101): choosing a home means reading a list of locations, and nobody points at a shelf they
   * are not allowed to see. Setting one is the PRODUCT's own permission, so `product.write` decides whether the
   * tab is editable — a reader sees where the product lives and changes nothing.
   */
  /** The saved product whose « Historique » may be read, for a reader of the activity journal. */
  protected readonly historyOf = computed(() => {
    const current = this.current();
    return current && this.auth.hasPermission('audit.read') ? current.id : null;
  });
  /** The saved product whose cost history may be read: where the cost itself is shown, so never to a customer looking on. */
  protected readonly costsOf = computed(() => {
    const current = this.current();
    return current && this.showsCost() ? current.id : null;
  });
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
    // The scan card offers a code nobody holds to the product on view (docs/SPEC.md § 7, 2026-09-25 10:13).
    const onView = inject(ProductOnView);
    const shownId = computed(() => this.current()?.id ?? null);
    const shownReference = computed(() => this.current()?.reference ?? null);
    effect(() => {
      const id = shownId();
      const reference = shownReference();
      untracked(() => onView.show(id !== null && reference !== null ? { id, reference } : null));
    });
    inject(DestroyRef).onDestroy(() => {
      const id = untracked(shownId);
      if (id !== null) onView.leave(id);
    });
    effect((onCleanup) => {
      const form = this.form();
      const companyId = this.company()?.id;
      if (form === null || !companyId || this.id() !== null || !this.mayWrite()) {
        untracked(() => this.proposal.set(null));
        return;
      }
      onCleanup(untracked(() => this.proposeReferences(form, companyId)));
    });
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        if (companyId) {
          void this.facade.loadProduct(companyId, id);
          // The names already in use, offered as the group is typed; only a person who may change it types one.
          if (this.mayWrite()) void this.facade.loadSubstitutionGroups(companyId);
        }
      });
    });
  }

  /**
   * Asks the API which reference the new product would be given, now and whenever its category changes, and puts it
   * in the field while the field still holds the previous proposal or nothing: a reference the person typed stays.
   * An answer overtaken by a later one is dropped. Returns what stops listening.
   */
  private proposeReferences(form: FormGroup, companyId: string): () => void {
    const reference = form.get('reference');
    const category = form.get('categoryId');
    if (reference === null || category === null) return () => undefined;
    let asked = 0;
    const ask = async (): Promise<void> => {
      const mine = ++asked;
      const categoryId = String(category.value ?? '');
      const shown = await this.facade.referencePreview(
        companyId,
        categoryId === '' ? null : categoryId,
      );
      if (mine !== asked || shown === null) return;
      const typed = String(reference.value ?? '').trim();
      const before = untracked(this.proposal);
      this.proposal.set(shown);
      if (typed === '' || typed === before) reference.setValue(shown);
    };
    void ask();
    const subscription = category.valueChanges.subscribe(() => void ask());
    return () => {
      asked++;
      subscription.unsubscribe();
    };
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
    const typed = productInput(values, options, this.facade.customFields(), this.current() ?? null);
    const id = this.id();
    if (id === null) {
      // The proposal left as it was is sent empty, so the API gives the next free one even if another save took it.
      const proposal = this.proposal();
      const generated = typed.reference === '' || typed.reference === proposal;
      const created = await this.facade.createProduct(
        companyId,
        generated ? { ...typed, reference: '' } : typed,
      );
      if (created !== null) {
        if (generated && proposal !== null && created.reference !== proposal) {
          this.feedback.success('products.saved_as', {
            given: created.reference,
            shown: proposal,
          });
        } else {
          this.feedback.success('products.saved');
        }
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
    } else if ((await this.facade.reviseProduct(companyId, id, typed)) !== null) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('products.saved');
    }
  }
}

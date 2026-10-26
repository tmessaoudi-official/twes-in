// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  linkedSignal,
  OnInit,
  signal,
  viewChild,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { editorActions } from '../shared/form/editor-actions';
import { RecordBar } from '../shared/form/record-bar';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { DataList, DataListSelection } from '../shared/list/data-list';
import { ArticleDefaults } from './article-defaults';
import {
  CATEGORIES_LIST,
  categoryForm,
  categoryInput,
  categoryListRows,
  categoryValues,
  type ProductCategoryListRow,
} from './product-forms';
import { ProductsFacade } from './products-facade';
import type { ProductCategoryRow } from './products-types';
import { PageTabs } from '../shared/ui/page-tabs';
import { PRODUCTS_TABS } from './products-nav';
import type { ListDescriptor } from '../shared/list/list-types';
import { Feedback } from '../shared/feedback/feedback';
import { listRecord } from '../shared/form/list-record';
import { RecordChanged } from '../shared/form/record-changed';
import { RecordDeletes } from '../shared/records/record-deletes';

/** The tree products are filed in: a category sits under another or at the top, and is deleted only once empty. */
@Component({
  selector: 'app-product-categories-page',
  imports: [
    PageTabs,
    RecordChanged,
    RouterLink,
    MatButtonModule,
    MatCardModule,
    MatIconModule,
    TranslatePipe,
    DataList,
    DataListSelection,
    DescriptorForm,
    RecordBar,
    ArticleDefaults,
  ],
  templateUrl: './product-categories-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductCategoriesPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly tabs = PRODUCTS_TABS;
  private readonly facade = inject(ProductsFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  private readonly deletes = inject(RecordDeletes);

  /**
   * The list with its own actions (design review finding 1): editing is what a person came for, so it is a button;
   * deleting is destructive, so it sits behind "⋮" whatever its frequency, and is taken back from its toast. Both
   * wait on a save in flight rather than disappearing during one.
   */
  protected readonly list = computed<ListDescriptor<ProductCategoryListRow>>(() => ({
    ...CATEGORIES_LIST,
    actions: [
      {
        id: 'edit',
        label: 'products.edit',
        icon: 'edit',
        run: (row) => this.open(row),
        disabled: () => this.busy(),
        shown: () => this.mayWrite(),
      },
      {
        id: 'delete',
        label: 'products.categories.delete_named',
        labelParams: (row) => ({ name: row.name }),
        icon: 'delete',
        destructive: true,
        // Asks nothing: the toast offers « Annuler », and « Récemment supprimés » keeps it a day with its defaults.
        undoable: true,
        run: (row) => void this.remove(row),
        disabled: () => this.busy(),
        // A category still holding products or sub-categories cannot go, as the page says: it is not offered.
        shown: (row) => this.mayWrite() && row.productCount === 0 && row.childCount === 0,
      },
    ],
    // Categories are selected to be merged, which only a person who may change them can do.
    ...(this.mayWrite() ? { selection: { countKey: 'products.categories.selected' } } : {}),
  }));
  /** The categories ticked on the page shown, by id, in the order they were ticked. */
  protected readonly selected = signal<readonly string[]>([]);
  protected readonly rows = computed(() => categoryListRows(this.facade.categories()));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('product.write'));
  protected readonly rowTestId = (row: ProductCategoryListRow): string =>
    `product-category-${row.name}`;

  protected readonly editing = signal<ProductCategoryRow | 'new' | null>(null);
  protected readonly descriptor = computed(() => {
    const editing = this.editing();
    return categoryForm(
      this.facade.categories(),
      editing === null || editing === 'new' ? null : editing,
    );
  });
  /**
   * The form of the category being edited. The categories arriving or changing while it is open (a slow first read, a
   * reload after a save) change the parents offered and rebuild the form over what was already typed, never over the
   * category's own values; opening another category starts from that one.
   */
  protected readonly form = linkedSignal<
    { editing: ProductCategoryRow | 'new' | null; descriptor: FormDescriptor },
    DescriptorFormGroup | null
  >({
    source: () => ({ editing: this.editing(), descriptor: this.descriptor() }),
    computation: ({ editing, descriptor }, previous) => {
      if (editing === null) return null;
      const typed =
        previous?.value && previous.source.editing === editing
          ? previous.value.getRawValue()
          : null;
      return buildFormGroup(
        descriptor,
        typed ?? categoryValues(editing === 'new' ? null : editing),
      );
    },
  });

  /** The subject of the defaults panel: the category being edited, once it exists. */
  protected readonly defaultsSubject = computed(() => {
    const editing = this.editing();
    return editing === null || editing === 'new' ? null : { productCategoryId: editing.id };
  });

  /** The saved version the open form stands on, and what another person's save changed in it. */
  protected readonly sync = listRecord({
    kind: 'product_category',
    editing: this.editing,
    rows: this.facade.categories,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      if (companyId) await this.facade.loadCategories(companyId);
    },
    values: categoryValues,
  });
  private readonly editor = viewChild(DescriptorForm);
  /** The open editor's bar: what is unsaved, its close and its save, which goes through the form's own submit. */
  protected readonly editorActions = editorActions({
    changes: this.sync.changes,
    busy: this.busy,
    save: () => this.editor()?.submit(),
    close: () => this.cancel(),
    closeLabel: 'products.cancel',
    saveLabel: 'products.save',
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['product_category', 'product'],
        () => this.facade.loadCategories(companyId),
        this.destroyRef,
      );
      await this.facade.loadCategories(companyId);
    }
  }

  protected open(target: ProductCategoryRow | 'new'): void {
    this.facade.clearError();
    this.editing.set(target);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.facade.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editing();
    if (!companyId || editing === null || this.busy()) return;
    const input = categoryInput(values);
    const accepted =
      editing === 'new'
        ? await this.facade.createCategory(companyId, input)
        : await this.facade.reviseCategory(companyId, editing.id, input, this.sync.version());
    if (accepted) {
      this.editing.set(null);
      this.feedback.success('products.categories.saved');
    } else if (this.facade.error() === 'record_changed') {
      this.facade.clearError();
      await this.sync.savedElsewhere();
    } else {
      // Each field the API refused said on that field; the line above the form stays for what it says as a whole.
      const refused = this.facade.refusals();
      if (refused.length > 0) this.editor()?.showRefused(refused);
    }
  }

  /**
   * Merges the categories ticked into the one the person keeps: what they hold moves to it, as the dialog says first
   * from the counts the list already shows.
   */
  protected async merge(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    const byId = new Map(this.rows().map((row) => [row.id, row]));
    const candidates = this.selected().flatMap((id) => {
      const row = byId.get(id);
      return row === undefined
        ? []
        : [
            {
              id: row.id,
              label: row.name,
              moves: [
                { table: 'product', count: row.productCount },
                { table: 'product_category', count: row.childCount },
              ],
            },
          ];
    });
    if (candidates.length < 2) return;
    if (
      await this.deletes.offerMerge(companyId, {
        subject: 'product_category',
        candidates,
        settings: true,
      })
    ) {
      this.selected.set([]);
    }
  }

  protected async remove(row: ProductCategoryRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    await this.deletes.remove(companyId, 'product_category', [{ id: row.id, label: row.name }]);
  }
}

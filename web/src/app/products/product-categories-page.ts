// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { DataList, DataListRowActions } from '../shared/list/data-list';
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

/** The tree products are filed in: a category sits under another or at the top, and goes only once empty. */
@Component({
  selector: 'app-product-categories-page',
  imports: [
    PageTabs,
    MatButtonModule,
    MatCardModule,
    TranslatePipe,
    DataList,
    DataListRowActions,
    DescriptorForm,
    ArticleDefaults,
  ],
  templateUrl: './product-categories-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductCategoriesPage implements OnInit {
  protected readonly tabs = PRODUCTS_TABS;
  private readonly facade = inject(ProductsFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = CATEGORIES_LIST;
  protected readonly rows = computed(() => categoryListRows(this.facade.categories()));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('product.write'));
  protected readonly rowTestId = (row: ProductCategoryListRow): string =>
    `product-category-${row.name}`;

  protected readonly editing = signal<ProductCategoryRow | 'new' | null>(null);
  protected readonly saved = signal(false);
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

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.facade.loadCategories(companyId);
    }
  }

  protected open(target: ProductCategoryRow | 'new'): void {
    this.facade.clearError();
    this.saved.set(false);
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
        : await this.facade.reviseCategory(companyId, editing.id, input);
    if (accepted) {
      this.editing.set(null);
      this.saved.set(true);
    }
  }

  protected async remove(row: ProductCategoryRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.saved.set(false);
    await this.facade.deleteCategory(companyId, row.id);
  }
}

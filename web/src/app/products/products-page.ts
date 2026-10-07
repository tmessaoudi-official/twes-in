// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import { StatusBadge } from '../shared/ui/status-badge';
import type { ListPickSource, ListQuery } from '../shared/list/list-types';
import {
  categoryLabels,
  type ProductListRow,
  productListRows,
  productSearch,
  productsList,
} from './product-forms';
import { ProductsFacade } from './products-facade';
import { PageTabs } from '../shared/ui/page-tabs';
import { PRODUCTS_TABS } from './products-nav';
import type { ProductSearch } from './products-types';

/** The products of the company being worked in, with a hidden column per custom field of theirs. */
@Component({
  selector: 'app-products-page',
  imports: [
    PageTabs,
    MatButtonModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DataList,
    DataListCell,
    ListExport,
    StatusBadge,
  ],
  templateUrl: './products-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductsPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly tabs = PRODUCTS_TABS;
  private readonly facade = inject(ProductsFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = computed(() => productsList(this.facade.customFields()));
  protected readonly rows = computed(() =>
    productListRows(this.facade.products(), this.facade.categories(), this.facade.options()),
  );
  protected readonly total = this.facade.total;
  protected readonly scale = computed(() => this.facade.options()?.currencyScale ?? null);
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('product.write'));
  protected readonly rowTestId = (row: ProductListRow): string => `product-${row.reference}`;
  /** Where the list's category filter searches: the categories already read, each named by its path. */
  protected readonly pickSources: Readonly<Record<string, ListPickSource>> = {
    category: {
      search: async (words) => {
        const wanted = words.trim().toLocaleLowerCase();
        return this.categoryChoices().filter((each) =>
          each.name.toLocaleLowerCase().includes(wanted),
        );
      },
      byIds: async (ids) => this.categoryChoices().filter((each) => ids.includes(each.id)),
    },
  };
  private categoryChoices() {
    return [...categoryLabels(this.facade.categories())].map(([id, name]) => ({
      id,
      code: '',
      name,
    }));
  }

  /** The page the list shows last asked for; a change elsewhere reads it again. */
  private search: ProductSearch | null = null;
  private readonly searched = signal<ProductSearch | null>(null);
  /** What the list shows now, as a file: null until the list has asked for its first page. */
  protected readonly exporter = computed(() => {
    const companyId = this.company()?.id;
    const search = this.searched();
    return companyId && search !== null
      ? (format: ExportFormat) => this.facade.exportUrl(companyId, search, format)
      : null;
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['product', 'product_category', 'custom_field', 'unit', 'tax_component', 'stock'],
        () => this.reload(companyId),
        this.destroyRef,
      );
      await this.facade.loadListContext(companyId);
    }
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = productSearch(query);
    this.searched.set(this.search);
    void this.facade.loadPage(companyId, this.search);
  }

  private async reload(companyId: string): Promise<void> {
    await Promise.all([
      this.facade.loadListContext(companyId),
      this.search === null ? Promise.resolve() : this.facade.loadPage(companyId, this.search),
    ]);
  }
}

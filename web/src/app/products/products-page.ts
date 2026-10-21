// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  OnInit,
  signal,
  untracked,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell, DataListSelection } from '../shared/list/data-list';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import { filterValues } from '../shared/list/list-filters';
import { StatusBadge } from '../shared/ui/status-badge';
import type { ListPickSource, ListQuery } from '../shared/list/list-types';
import { RecordDeletes } from '../shared/records/record-deletes';
import {
  categoryLabels,
  type ProductListRow,
  productListRows,
  productSearch,
  productsList,
} from './product-forms';
import { ProductsFacade } from './products-facade';
import { ProductBulk } from './product-bulk';
import { ProductThumbnail } from './product-thumbnail';
import { PageTabs } from '../shared/ui/page-tabs';
import { PRODUCTS_TABS } from './products-nav';
import type { ProductSearch } from './products-types';

/** The products of the company being worked in, with a hidden column per custom field of theirs. */
@Component({
  selector: 'app-products-page',
  imports: [
    PageTabs,
    MatButtonModule,
    MatIconModule,
    MatMenuModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DataList,
    DataListCell,
    DataListSelection,
    ListExport,
    ProductBulk,
    ProductThumbnail,
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
  private readonly deletes = inject(RecordDeletes);

  /** Rows are selected for what the selection bar offers on them: their labels on one sheet, and a merge. */
  protected readonly list = computed(() => ({
    ...productsList(this.facade.customFields(), this.offeredReasons() ?? []),
    selection: { countKey: 'products.selected' },
  }));
  /** The rows ticked on the page shown, by id: what the selection bar's actions act on. */
  protected readonly selected = signal<readonly string[]>([]);
  protected readonly merging = signal(false);
  protected readonly rows = computed(() =>
    productListRows(this.facade.products(), this.facade.categories(), this.facade.options()),
  );
  protected readonly total = this.facade.total;
  protected readonly scale = computed(() => this.facade.options()?.currencyScale ?? null);
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('product.write'));
  /** Who a product is bought from is written with the product, while the company keeps vendors. */
  protected readonly mayImportVendors = computed(() => this.auth.hasModule('vendors'));
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

  /** What « À vérifier » may say to this reader here; null until the product options are read. */
  private readonly offeredReasons = computed(() => this.facade.options()?.checkReasons ?? null);
  /** What the list last asked for, as the list said it: asked again once the reasons it may name are known. */
  private query: ListQuery | null = null;
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

  /**
   * Merges the products ticked into the one the person keeps; the others stay, switched off and pointing at it, for
   * what already names them, their stock going to it. The dialog first says, from the API, what moves and what stays.
   */
  protected async merge(): Promise<void> {
    const companyId = this.company()?.id;
    const ids = this.selected();
    if (!companyId || ids.length < 2 || this.merging()) return;
    this.merging.set(true);
    try {
      if (
        await this.deletes.offerMergeOf(companyId, 'product', ids, {
          settings: true,
          aliased: true,
        })
      ) {
        this.selected.set([]);
      }
    } finally {
      this.merging.set(false);
    }
  }

  constructor() {
    // A list opened from a link naming what is lacking asks before the reasons it may name are read: it asks again then.
    effect(() => {
      const offered = this.offeredReasons();
      untracked(() => {
        const query = this.query;
        if (
          offered !== null &&
          query !== null &&
          filterValues(query.filters['checkReason']).length > 0
        ) {
          this.onQuery(query);
        }
      });
    });
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.query = query;
    this.search = productSearch(query, this.offeredReasons() ?? []);
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

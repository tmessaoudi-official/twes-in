// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, OnInit } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import { type ProductListRow, productListRows, productsList } from './product-forms';
import { ProductsFacade } from './products-facade';

/** The products of the company being worked in, with a hidden column per custom field of theirs. */
@Component({
  selector: 'app-products-page',
  imports: [MatButtonModule, RouterLink, TranslatePipe, DataList, DataListCell, DataListRowActions],
  templateUrl: './products-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductsPage implements OnInit {
  private readonly facade = inject(ProductsFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = computed(() => productsList(this.facade.customFields()));
  protected readonly rows = computed(() =>
    productListRows(this.facade.products(), this.facade.categories(), this.facade.options()),
  );
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('product.write'));
  protected readonly rowTestId = (row: ProductListRow): string => `product-${row.reference}`;

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.facade.loadList(companyId);
    }
  }
}

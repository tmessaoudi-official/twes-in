// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, type OnInit } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { AmountPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { ProductCostHistory } from './product-cost-history-facade';

/**
 * Every change of what the product costs the company, the latest first: the cost before and after, what moved it, and
 * when. It only reads; the cost is changed on the form, or by a stock receipt that applies one.
 */
@Component({
  selector: 'app-product-cost-history',
  imports: [TranslatePipe, AmountPipe, MomentPipe],
  templateUrl: './product-cost-history.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductCostHistorySection implements OnInit {
  private readonly history = inject(ProductCostHistory);
  private readonly auth = inject(AuthFacade);

  readonly productId = input.required<string>();

  protected readonly rows = this.history.rows;
  protected readonly busy = this.history.busy;
  protected readonly error = this.history.error;

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id ?? null;
    if (companyId !== null) await this.history.load(companyId, this.productId());
  }
}

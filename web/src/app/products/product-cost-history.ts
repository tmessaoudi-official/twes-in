// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  input,
  type OnInit,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { AmountPipe, DayPipe, MeasurePipe, MomentPipe } from '../shared/i18n/format-pipes';
import { TextsParts } from '../shared/i18n/texts-part';
import { PURCHASE_HISTORY_TEXTS } from '../shared/i18n/purchase-history-texts';
import { LiveChanges } from '../shared/realtime/live-changes';
import { ProductCostHistory } from './product-cost-history-facade';

/**
 * Every change of what the product costs the company, the latest first: the cost before and after, what moved it, and
 * when; and the past purchases a file brought in, newest first, the undated last, each with its vendor. It only reads; the cost is changed on the form, or by a stock receipt that applies one, in this tab or another,
 * so it reads itself again whenever the product changes.
 */
@Component({
  selector: 'app-product-cost-history',
  imports: [TranslatePipe, RouterLink, AmountPipe, DayPipe, MeasurePipe, MomentPipe],
  templateUrl: './product-cost-history.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductCostHistorySection implements OnInit {
  private readonly history = inject(ProductCostHistory);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly texts = inject(TextsParts);

  readonly productId = input.required<string>();

  protected readonly rows = this.history.rows;
  protected readonly busy = this.history.busy;
  protected readonly error = this.history.error;

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id ?? null;
    if (companyId === null) return;
    // A past purchase's words are the vendor's « Achats » tab's too: their own texts, kept off the first page.
    void this.texts.open(PURCHASE_HISTORY_TEXTS);
    this.live.reloadOn(
      ['product'],
      () => this.history.load(companyId, this.productId()),
      this.destroyRef,
    );
    await this.history.load(companyId, this.productId());
  }
}

// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, type OnInit } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe, DayPipe, MeasurePipe } from '../shared/i18n/format-pipes';
import { PURCHASE_HISTORY_TEXTS } from '../shared/i18n/purchase-history-texts';
import { TextsParts } from '../shared/i18n/texts-part';
import { VendorPurchases } from './vendor-purchases-facade';

/**
 * A vendor's « Achats »: every line the company bought from it, as its past purchases were imported, the newest day
 * first and those of no known day last, each opening its product. It only reads; what a line cost is a cost, so the
 * vendor's screen shows it only to whoever may read costs.
 */
@Component({
  selector: 'app-vendor-purchases',
  imports: [TranslatePipe, RouterLink, MatButtonModule, AmountPipe, DayPipe, MeasurePipe],
  providers: [VendorPurchases],
  templateUrl: './vendor-purchases.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VendorPurchasesSection implements OnInit {
  private readonly purchases = inject(VendorPurchases);
  private readonly texts = inject(TextsParts);

  readonly companyId = input.required<string>();
  readonly vendorId = input.required<string>();

  protected readonly rows = this.purchases.rows;
  protected readonly total = this.purchases.total;
  protected readonly busy = this.purchases.busy;
  protected readonly error = this.purchases.error;
  protected readonly more = this.purchases.more;

  async ngOnInit(): Promise<void> {
    void this.texts.open(PURCHASE_HISTORY_TEXTS);
    await this.purchases.load(this.companyId(), this.vendorId());
  }

  protected async showMore(): Promise<void> {
    await this.purchases.loadMore(this.companyId(), this.vendorId());
  }
}

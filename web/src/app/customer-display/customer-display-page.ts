// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, DestroyRef, inject } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { CustomerDisplay } from '../shared/customer-display/customer-display';
import { FormatFacade } from '../shared/i18n/format-facade';

/**
 * The customer display (docs/SPEC.md § 7, 2026-09-23 slice 6): a window turned towards the customer, with nothing to
 * press and nothing of the company's own. It shows the line the counter's last scan went onto, one's price taxes
 * included, and, once the sale is saved, what it comes to. Outside the shell, so no menu and no scan card reaches it.
 */
@Component({
  selector: 'app-customer-display-page',
  imports: [TranslatePipe],
  templateUrl: './customer-display-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomerDisplayPage {
  private readonly format = inject(FormatFacade);
  protected readonly shown = inject(CustomerDisplay).watch(inject(DestroyRef));

  protected price(value: string, currency: string): string {
    return `${this.format.amount(value, null)} ${currency}`.trim();
  }
}

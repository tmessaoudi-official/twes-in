// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, DestroyRef, inject, signal } from '@angular/core';
import { CompanyBrand } from '../company/company-brand';
import { TranslatePipe } from '@ngx-translate/core';
import { CustomerDisplay } from '../shared/customer-display/customer-display';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { SettingsApi } from '../shared/settings/settings-api';

/**
 * The customer display (docs/SPEC.md § 7, 2026-09-23 slice 6): a window turned towards the customer, with nothing to
 * press and nothing of the company's own. It shows the line the counter's last scan went onto, one's price taxes
 * included, and, once the sale is saved, what it comes to. Outside the shell, so no menu and no scan card reaches it.
 */
@Component({
  selector: 'app-customer-display-page',
  imports: [CompanyBrand, TranslatePipe],
  templateUrl: './customer-display-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomerDisplayPage {
  private readonly format = inject(FormatFacade);
  private readonly settings = inject(SettingsApi);
  private readonly session = inject(Session);
  protected readonly shown = inject(CustomerDisplay).watch(inject(DestroyRef));
  /** The company's « Afficher aussi le prix HT »: the price without tax beside the one with it, never instead. */
  protected readonly showsNet = signal(false);

  constructor() {
    const companyId = this.session.me()?.company?.id;
    if (companyId === undefined) return;
    void this.settings
      .chain(companyId, 'articles')
      .then((rows) =>
        this.showsNet.set(
          rows.find((row) => row.key === 'article.show_price_excl_tax')?.value === true,
        ),
      )
      // A display that cannot read it keeps to the price with tax alone, which is what the law asks for.
      .catch(() => undefined);
  }

  /** A quantity as the customer counts: the screen's decimal sign, without the zeros the unit's scale pads it with. */
  protected count(value: string): string {
    const trimmed = value.includes('.') ? value.replace(/\.?0+$/, '') : value;
    return this.format.amount(trimmed, null);
  }

  protected price(value: string, currency: string): string {
    return `${this.format.amount(value, null)} ${currency}`.trim();
  }
}

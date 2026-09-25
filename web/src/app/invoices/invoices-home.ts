// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  untracked,
} from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { formatMonth } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { agingBars, chaseDue, collectedBars, initials } from './invoice-summary-view';
import { InvoicesFacade } from './invoices-facade';
import type { AgingBucket } from './invoices-types';

/** Each aging bucket's colour, from the theme's status tokens: the later, the warmer. */
const AGING_COLOURS: Readonly<Record<AgingBucket, string>> = {
  not_due: 'var(--twes-status-info-dot)',
  days_1_15: 'var(--twes-status-warning-dot)',
  days_16_30: 'var(--twes-status-purple-dot)',
  days_31_45: 'var(--twes-status-danger-dot)',
  days_over_45: 'var(--twes-status-danger-fg)',
};

/**
 * The invoices module's panel on the home page (docs/SPEC.md § 8 row 35): what is still to collect and how much of it
 * is late, what came in this month, the VAT its invoices charged this month (« TVA collectée »), the invoices to chase
 * and six months of payments.
 * Every figure is the API's; the page only lays them out.
 */
@Component({
  selector: 'app-invoices-home',
  imports: [MatCardModule, RouterLink, TranslatePipe, AmountPipe],
  templateUrl: './invoices-home.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InvoicesHome {
  private readonly facade = inject(InvoicesFacade);
  private readonly auth = inject(AuthFacade);
  private readonly format = inject(FormatFacade);

  protected readonly summary = this.facade.summary;
  protected readonly error = this.facade.error;
  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);

  protected readonly month = computed(() => {
    const summary = this.summary();
    return summary === null ? '' : formatMonth(summary.today, this.format.locale());
  });
  protected readonly thisMonth = computed(() => this.summary()?.collected.at(-1)?.amount ?? '0');
  protected readonly bars = computed(() =>
    collectedBars(this.summary()?.collected ?? [], this.format.locale()),
  );
  protected readonly aging = computed(() =>
    agingBars(this.summary()?.aging ?? []).map((bar) => ({
      ...bar,
      colour: AGING_COLOURS[bar.bucket],
    })),
  );
  protected readonly chase = computed(() =>
    (this.summary()?.toChase ?? []).map((row) => ({
      ...row,
      due: chaseDue(row.daysLate),
      initials: initials(row.customerName),
    })),
  );

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      if (companyId !== null) {
        untracked(() => void this.facade.loadSummary(companyId));
      }
    });
  }
}

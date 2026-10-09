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
import { versus } from '../shared/ui/versus';
import { agingBars, chaseDue, collectedBars, initials } from './invoice-summary-view';
import { InvoicesFacade } from './invoices-facade';
import type { AgingBucket } from './invoices-types';

/** Each aging bucket's colour, from the theme's status tokens: the later, the warmer. */
const AGING_COLOURS: Readonly<Record<AgingBucket, string>> = {
  not_due: 'var(--twes-status-info-dot)',
  days_1_15: 'var(--twes-status-warning-dot)',
  days_16_30: 'var(--twes-status-purple-dot)',
  days_31_45: 'var(--twes-status-danger-dot)',
  days_over_45: 'var(--twes-aging-worst)',
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
  protected readonly thisMonth = computed(() => this.summary()?.collectedMonth ?? '0');
  protected readonly collectedVersus = computed(() => {
    const s = this.summary();
    return s === null ? null : versus(s.collectedMonth, s.collectedLastMonth);
  });
  protected readonly invoicedVersus = computed(() => {
    const s = this.summary();
    return s === null ? null : versus(s.invoicedMonth, s.invoicedLastMonth);
  });
  /** A company that has never had a withholding is shown no card for it: a figure with nothing behind it is left out. */
  protected readonly withholds = computed(() => {
    const s = this.summary();
    return s !== null && /[1-9]/.test(s.withheldMonth + s.withheldLastMonth);
  });
  protected readonly withheldVersus = computed(() => {
    const s = this.summary();
    return s === null ? null : versus(s.withheldMonth, s.withheldLastMonth);
  });
  protected readonly topRow = computed(() => {
    const s = this.summary();
    const cards = 1 + (s?.costsVisible ? 1 : 0) + (this.withholds() ? 1 : 0);
    // Three cards make a row of three from the large breakpoint; between small and large the third takes the whole row
    // rather than sitting alone at half width.
    return cards === 3
      ? 'sm:grid-cols-2 lg:grid-cols-3 sm:[&>:last-child]:col-span-2 lg:[&>:last-child]:col-span-1'
      : cards === 2
        ? 'sm:grid-cols-2'
        : '';
  });
  protected readonly marginVersus = computed(() => {
    const s = this.summary();
    return s?.margin == null || s.marginLastMonth == null
      ? null
      : versus(s.margin, s.marginLastMonth);
  });
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

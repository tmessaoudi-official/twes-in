// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
} from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { PickAsked } from '../shared/form/pick-api';
import type { ListPickSource, ListQuery } from '../shared/list/list-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import type { StatusTone } from '../shared/theme/accent-theme';
import { StatusBadge } from '../shared/ui/status-badge';
import { INSTRUMENT_STATUS_TONES, type InstrumentStatus } from './instruments-types';
import { InvoicesFacade } from './invoices-facade';
import { PORTFOLIO_LIST, portfolioSearch } from './portfolio-forms';
import { PortfolioFacade } from './portfolio-facade';
import type { PortfolioRow, PortfolioSearch } from './portfolio-types';

/**
 * The company's cheques and traites in one place (docs/SPEC.md § 7, 2026-09-21 18:40): what is held or at the bank, the
 * nearest due day first, each leading to its invoice, where it is deposited and cashed.
 */
@Component({
  selector: 'app-portfolio-page',
  imports: [AmountPipe, DataList, DataListCell, DayPipe, StatusBadge, TranslatePipe],
  templateUrl: './portfolio-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PortfolioPage implements OnInit {
  private readonly facade = inject(PortfolioFacade);
  private readonly invoices = inject(InvoicesFacade);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly list = PORTFOLIO_LIST;
  protected readonly rows = this.facade.rows;
  protected readonly total = this.facade.total;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  /** A list cell's row is untyped, so the tone is looked up through a typed function. */
  protected readonly toneOf = (status: InstrumentStatus): StatusTone =>
    INSTRUMENT_STATUS_TONES[status];
  protected readonly rowTestId = (row: PortfolioRow): string => `instrument-row-${row.id}`;

  /** Where the « Filtres » panel finds a customer to narrow by, and names the ones an address holds. */
  protected readonly pickSources: Readonly<Record<string, ListPickSource>> = {
    customer: {
      search: (words) => this.pickCustomers({ words }),
      byIds: (ids) => this.pickCustomers({ ids }),
    },
  };
  private async pickCustomers(asked: PickAsked) {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.invoices.pickCustomers(companyId, asked);
    return found.map((customer) => ({
      id: customer.id,
      code: customer.number,
      name: customer.name,
    }));
  }

  /** What the list last asked the API for; nothing is read until the list has said what it wants. */
  private search: PortfolioSearch | null = null;

  ngOnInit(): void {
    const companyId = this.company()?.id;
    // A step taken on an invoice is audited as the invoice's, so the invoice's kind is what says the portfolio moved.
    if (companyId)
      this.live.reloadOn(['invoice', 'payment'], () => this.reload(companyId), this.destroyRef);
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = portfolioSearch(query);
    void this.facade.loadPage(companyId, this.search);
  }

  private async reload(companyId: string): Promise<void> {
    if (this.search !== null) await this.facade.loadPage(companyId, this.search);
  }
}

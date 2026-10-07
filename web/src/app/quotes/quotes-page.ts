// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { keyName } from '../shared/actions/shortcuts-sheet';
import type { PickAsked } from '../shared/form/pick-api';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListFacetCounts, ListPickSource, ListQuery } from '../shared/list/list-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { SettingsFacade } from '../shared/settings/settings-facade';
import { PRESENTATION } from '../shared/settings/settings-registry';
import type { StatusTone } from '../shared/theme/accent-theme';
import { withdrawn } from '../shared/theme/lifecycle-tones';
import { StatusBadge } from '../shared/ui/status-badge';
import { QUOTES_LIST, type QuoteListRow, quoteListRows, quoteSearch } from './quote-forms';
import { QuotesFacade } from './quotes-facade';
import {
  QUOTE_STATUS_STAGES,
  QUOTE_STATUS_TONES,
  type QuoteSearch,
  type QuoteShownStatus,
} from './quotes-types';

/** The quotes of the company being worked in. */
@Component({
  selector: 'app-quotes-page',
  imports: [
    MatButtonModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DataList,
    DataListCell,
    StatusBadge,
  ],
  templateUrl: './quotes-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class QuotesPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(QuotesFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = QUOTES_LIST;
  /** A list cell's row is untyped, so the tone is looked up through a typed function. */
  protected readonly struckOf = (status: QuoteShownStatus): boolean =>
    withdrawn(QUOTE_STATUS_STAGES[status]);
  protected readonly toneOf = (status: QuoteShownStatus): StatusTone => QUOTE_STATUS_TONES[status];
  protected readonly rows = computed(() => quoteListRows(this.facade.quotes()));
  protected readonly scale = computed(() => this.facade.options()?.currencyScale ?? null);
  protected readonly total = this.facade.total;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('quote.write'));
  protected readonly rowTestId = (row: QuoteListRow): string => `quote-${row.id}`;
  /** What each status chip would list, as the API counted it. */
  protected readonly facetCounts = computed((): ListFacetCounts | null => {
    const counts = this.facade.statusCounts();
    return counts === null ? null : { status: { total: counts.all, options: counts.statuses } };
  });
  /** Where the « Filtres » panel finds a customer to narrow by, and names the ones an address holds. */
  protected readonly pickSources: Readonly<Record<string, ListPickSource>> = {
    customer: {
      search: (words) => this.pickCustomers({ words }),
      byIds: (ids) => this.pickCustomers({ ids }),
    },
  };
  /** The person's key for a new quote, named on the button. */
  protected readonly newKey = computed(() => this.keys().new);
  protected readonly keyName = keyName;
  private readonly keys = inject(SettingsFacade).value(PRESENTATION.shortcuts);

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private search: QuoteSearch | null = null;

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(['quote', 'customer'], () => this.reload(companyId), this.destroyRef);
      await this.facade.loadListContext(companyId);
    }
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = quoteSearch(query);
    void this.facade.loadPage(companyId, this.search);
    void this.facade.loadStatusCounts(companyId, this.search);
  }

  private async pickCustomers(asked: PickAsked) {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickCustomers(companyId, asked);
    return found.map((customer) => ({
      id: customer.id,
      code: customer.number,
      name: customer.name,
    }));
  }

  private async reload(companyId: string): Promise<void> {
    if (this.search === null) return;
    await Promise.all([
      this.facade.loadPage(companyId, this.search),
      this.facade.loadStatusCounts(companyId, this.search),
    ]);
  }
}

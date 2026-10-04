// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
} from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { formatMonth } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { LiveChanges } from '../shared/realtime/live-changes';
import { versus } from '../shared/ui/versus';
import { ExpensesFacade } from './expenses-facade';

/**
 * The expenses module's panel on the home page (docs/SPEC.md § 7, 2026-10-04, row 113): what was recorded in expenses
 * so far this month, taxes included, against the same days of last month. It is spending and is never called a
 * profit; the API adds it up and this only lays it out.
 */
@Component({
  selector: 'app-expenses-home',
  imports: [MatCardModule, RouterLink, TranslatePipe, AmountPipe],
  templateUrl: './expenses-home.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ExpensesHome implements OnInit {
  private readonly facade = inject(ExpensesFacade);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly format = inject(FormatFacade);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly summary = this.facade.summary;
  protected readonly month = computed(() => {
    const summary = this.summary();
    return summary === null ? '' : formatMonth(summary.today, this.format.locale());
  });
  protected readonly versus = computed(() => {
    const summary = this.summary();
    return summary === null ? null : versus(summary.month, summary.lastMonth);
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.live.reloadOn(['expense'], () => this.facade.loadSummary(companyId), this.destroyRef);
    await this.facade.loadSummary(companyId);
  }
}

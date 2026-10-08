// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListDescriptor } from '../shared/list/list-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import type { StatusTone } from '../shared/theme/accent-theme';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import { RecurringApi, RecurringRefused } from './recurring-api';
import { RECURRING_TABS } from './recurring-nav';
import {
  RECURRING_FREQUENCIES,
  recurringState,
  type RecurringError,
  type RecurringInvoiceRow,
} from './recurring-types';

const STATE_TONES: Record<ReturnType<typeof recurringState>, StatusTone> = {
  active: 'success',
  paused: 'warning',
  ended: 'neutral',
};

/**
 * « Récurrentes », a tab of Factures: each recurring invoice, the invoice it copies, how often and when the next draft
 * is made, paused or taken up again, and deleted, which keeps the drafts it made. One is made from an invoice, with
 * « Rendre récurrente ».
 */
@Component({
  selector: 'app-recurring-page',
  imports: [PageTabs, TranslatePipe, DayPipe, DataList, DataListCell, StatusBadge],
  templateUrl: './recurring-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecurringPage implements OnInit {
  private readonly api = inject(RecurringApi);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly tabs = RECURRING_TABS;
  protected readonly rows = signal<readonly RecurringInvoiceRow[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<RecurringError | null>(null);
  protected readonly busy = signal(false);
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('invoice.write'));
  protected readonly state = recurringState;
  protected readonly tone = (row: RecurringInvoiceRow): StatusTone =>
    STATE_TONES[recurringState(row)];
  protected readonly rowTestId = (row: RecurringInvoiceRow): string => `recurring-${row.id}`;

  protected readonly list = computed<ListDescriptor<RecurringInvoiceRow>>(() => ({
    id: 'recurring-invoices',
    rowId: (row) => row.id,
    pageSizes: [25, 50, 100],
    defaultSort: { column: 'nextOn', direction: 'asc' },
    link: (row) => ['/invoices', row.modelInvoiceId],
    linkColumn: 'model',
    columns: [
      {
        id: 'customer',
        label: 'recurring.columns.customer',
        value: (row) => row.customerName,
        sortable: true,
        filterable: true,
        hideable: false,
      },
      {
        id: 'model',
        label: 'recurring.columns.model',
        value: (row) => row.modelNumber,
        sortable: true,
        filterable: true,
      },
      {
        id: 'frequency',
        label: 'recurring.columns.frequency',
        value: (row) => RECURRING_FREQUENCIES.indexOf(row.frequency),
        sortable: true,
        width: 150,
      },
      {
        id: 'nextOn',
        label: 'recurring.columns.next_on',
        value: (row) => row.nextOn,
        sortable: true,
        width: 150,
      },
      {
        id: 'drafted',
        label: 'recurring.columns.drafted',
        value: (row) => row.drafted,
        sortable: true,
        align: 'end',
        width: 120,
      },
      {
        id: 'state',
        label: 'recurring.columns.state',
        value: (row) => recurringState(row),
        sortable: true,
        width: 140,
      },
    ],
    filters: [
      {
        id: 'state',
        label: 'recurring.columns.state',
        value: (row) => recurringState(row),
        options: (['active', 'paused', 'ended'] as const).map((value) => ({
          value,
          label: `recurring.states.${value}`,
          tone: STATE_TONES[value],
        })),
      },
    ],
    actions: [
      {
        id: 'last-draft',
        label: 'recurring.last_draft',
        icon: 'receipt_long',
        link: (row) => ['/invoices', row.lastInvoiceId],
        shown: (row) => row.lastInvoiceId !== null,
      },
      {
        id: 'pause',
        label: 'recurring.pause',
        labelParams: (row) => ({ customer: row.customerName }),
        icon: 'pause',
        run: (row) => void this.setPaused(row, true),
        disabled: () => this.busy(),
        shown: (row) => this.mayWrite() && recurringState(row) === 'active',
      },
      {
        id: 'resume',
        label: 'recurring.resume',
        labelParams: (row) => ({ customer: row.customerName }),
        icon: 'play_arrow',
        run: (row) => void this.setPaused(row, false),
        disabled: () => this.busy(),
        shown: (row) => this.mayWrite() && recurringState(row) === 'paused',
      },
      {
        id: 'delete',
        label: 'recurring.delete',
        labelParams: (row) => ({ customer: row.customerName }),
        icon: 'delete',
        destructive: true,
        run: (row) => void this.remove(row),
        disabled: () => this.busy(),
        shown: () => this.mayWrite(),
        confirm: (row) => ({
          kind: 'definitif',
          title: 'recurring.delete_title',
          message: 'recurring.delete_message',
          messageParams: { customer: row.customerName },
          confirmLabel: 'recurring.delete_confirm',
          keepLabel: 'recurring.keep',
        }),
      },
    ],
  }));

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) {
      this.loading.set(false);
      return;
    }
    this.live.reloadOn(['recurring_invoice'], () => this.refresh(companyId), this.destroyRef);
    await this.refresh(companyId);
  }

  private async refresh(companyId: string): Promise<void> {
    try {
      this.rows.set(await this.api.list(companyId));
      this.error.set(null);
    } catch (error) {
      this.error.set(error instanceof RecurringRefused ? error.code : 'network');
    } finally {
      this.loading.set(false);
    }
  }

  private async setPaused(row: RecurringInvoiceRow, paused: boolean): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.busy.set(true);
    try {
      const saved = await this.api.revise(companyId, row.id, {
        frequency: row.frequency,
        endsOn: row.endsOn,
        paused,
      });
      this.rows.update((rows) => rows.map((one) => (one.id === saved.id ? saved : one)));
      this.feedback.success(paused ? 'recurring.paused' : 'recurring.resumed', {
        customer: row.customerName,
      });
    } catch (error) {
      this.error.set(error instanceof RecurringRefused ? error.code : 'network');
    } finally {
      this.busy.set(false);
    }
  }

  private async remove(row: RecurringInvoiceRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.busy.set(true);
    try {
      await this.api.delete(companyId, row.id);
      this.rows.update((rows) => rows.filter((one) => one.id !== row.id));
      this.feedback.effect('recurring.deleted', { customer: row.customerName }, 'definitif');
    } catch (error) {
      this.error.set(error instanceof RecurringRefused ? error.code : 'network');
    } finally {
      this.busy.set(false);
    }
  }
}

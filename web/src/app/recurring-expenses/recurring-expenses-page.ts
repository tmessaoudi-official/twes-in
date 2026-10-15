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
import { MatDialog } from '@angular/material/dialog';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { expensesTabs, RECURRING_EXPENSES_MODULE } from '../expenses/expenses-nav';
import { Feedback } from '../shared/feedback/feedback';
import { buildFormGroup } from '../shared/form/form-builder';
import { todayIn } from '../shared/i18n/format';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListDescriptor } from '../shared/list/list-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { scheduleSentence, scheduleValues, SCHEDULE_UNITS } from '../shared/schedule/schedule';
import type { StatusTone } from '../shared/theme/accent-theme';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  RecurringExpenseDialog,
  type RecurringExpenseDialogData,
  recurringExpenseFailure,
  recurringExpenseForm,
  recurringExpenseRevision,
} from './recurring-expense-form';
import { RecurringExpenseRefused, RecurringExpensesApi } from './recurring-expenses-api';
import {
  recurringExpenseState,
  type RecurringExpenseError,
  type RecurringExpenseRow,
} from './recurring-expenses-types';

const TEXTS = 'expenses.recurring';

const STATE_TONES: Record<ReturnType<typeof recurringExpenseState>, StatusTone> = {
  active: 'success',
  paused: 'warning',
  ended: 'neutral',
};

/**
 * « Récurrentes », a tab of Dépenses while the module is on: each recurring expense, the expense it copies, when the
 * next draft is made, revised, paused or taken up again, and deleted, which keeps the drafts it made. One is made from
 * an expense, with « Rendre récurrente ».
 */
@Component({
  selector: 'app-recurring-expenses-page',
  imports: [PageTabs, TranslatePipe, AmountPipe, DayPipe, DataList, DataListCell, StatusBadge],
  templateUrl: './recurring-expenses-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecurringExpensesPage implements OnInit {
  private readonly api = inject(RecurringExpensesApi);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly dialog = inject(MatDialog);
  private readonly translate = inject(TranslateService);
  private readonly language = inject(LanguageFacade);

  protected readonly tabs = computed(() =>
    expensesTabs(this.auth.hasModule(RECURRING_EXPENSES_MODULE)),
  );
  protected readonly rows = signal<readonly RecurringExpenseRow[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<RecurringExpenseError | null>(null);
  protected readonly busy = signal(false);
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('expense.write'));
  protected readonly state = recurringExpenseState;
  protected readonly tone = (row: RecurringExpenseRow): StatusTone =>
    STATE_TONES[recurringExpenseState(row)];
  protected readonly rowTestId = (row: RecurringExpenseRow): string =>
    `recurring-expense-${row.id}`;

  /** How often each one recurs, in a sentence of the screen's language, read again when the language changes. */
  private readonly sentences = computed(() => {
    const language = this.language.current();
    const say = (key: string, params?: Record<string, unknown>): string =>
      this.translate.instant(key, params) as string;
    return new Map(
      this.rows().map((row) => [
        row.id,
        scheduleSentence(row.schedule, row.startsOn, say, language),
      ]),
    );
  });
  protected readonly sentence = (row: RecurringExpenseRow): string =>
    this.sentences().get(row.id) ?? '';

  protected readonly list = computed<ListDescriptor<RecurringExpenseRow>>(() => ({
    id: 'recurring-expenses',
    rowId: (row) => row.id,
    pageSizes: [25, 50, 100],
    defaultSort: { column: 'nextOn', direction: 'asc' },
    link: (row) => ['/expenses', row.modelExpenseId],
    linkColumn: 'description',
    columns: [
      {
        id: 'description',
        label: `${TEXTS}.columns.description`,
        value: (row) => row.description,
        sortable: true,
        filterable: true,
        hideable: false,
      },
      // Read under the description; a column of its own only for whoever places it.
      {
        id: 'payee',
        label: `${TEXTS}.columns.payee`,
        value: (row) => row.payee,
        sortable: true,
        filterable: true,
        defaultHidden: true,
      },
      {
        id: 'schedule',
        label: `${TEXTS}.columns.schedule`,
        value: (row) => SCHEDULE_UNITS.indexOf(row.schedule.unit) * 100 + row.schedule.every,
        sortable: true,
        // The sentence is what the column is read for: room for « Tous les 3 mois, le dernier vendredi ».
        width: 300,
      },
      {
        id: 'amount',
        label: `${TEXTS}.columns.amount`,
        value: (row) => Number(row.amountGross ?? 0),
        sortable: true,
        align: 'end',
        width: 150,
      },
      {
        id: 'nextOn',
        label: `${TEXTS}.columns.next_on`,
        value: (row) => row.nextOn,
        sortable: true,
        width: 150,
      },
      {
        id: 'drafted',
        label: `${TEXTS}.columns.drafted`,
        value: (row) => row.drafted,
        sortable: true,
        align: 'end',
        width: 120,
        defaultHidden: true,
      },
      {
        id: 'state',
        label: `${TEXTS}.columns.state`,
        value: (row) => recurringExpenseState(row),
        sortable: true,
        width: 120,
      },
    ],
    filters: [
      {
        id: 'state',
        label: `${TEXTS}.columns.state`,
        value: (row) => recurringExpenseState(row),
        options: (['active', 'paused', 'ended'] as const).map((value) => ({
          value,
          label: `${TEXTS}.states.${value}`,
          tone: STATE_TONES[value],
        })),
      },
    ],
    actions: [
      {
        id: 'last-draft',
        label: `${TEXTS}.last_draft`,
        icon: 'receipt_long',
        link: (row) => ['/expenses', row.lastExpenseId],
        shown: (row) => row.lastExpenseId !== null,
      },
      {
        id: 'revise',
        label: `${TEXTS}.revise.action`,
        labelParams: (row) => ({ description: row.description }),
        icon: 'edit',
        run: (row) => void this.revise(row),
        disabled: () => this.busy(),
        shown: (row) => this.mayWrite() && recurringExpenseState(row) !== 'ended',
      },
      {
        id: 'pause',
        label: `${TEXTS}.pause`,
        labelParams: (row) => ({ description: row.description }),
        icon: 'pause',
        run: (row) => void this.setPaused(row, true),
        disabled: () => this.busy(),
        shown: (row) => this.mayWrite() && recurringExpenseState(row) === 'active',
      },
      {
        id: 'resume',
        label: `${TEXTS}.resume`,
        labelParams: (row) => ({ description: row.description }),
        icon: 'play_arrow',
        run: (row) => void this.setPaused(row, false),
        disabled: () => this.busy(),
        shown: (row) => this.mayWrite() && recurringExpenseState(row) === 'paused',
      },
      {
        id: 'delete',
        label: `${TEXTS}.delete`,
        labelParams: (row) => ({ description: row.description }),
        icon: 'delete',
        destructive: true,
        run: (row) => void this.remove(row),
        disabled: () => this.busy(),
        shown: () => this.mayWrite(),
        confirm: (row) => ({
          kind: 'definitif',
          title: `${TEXTS}.delete_title`,
          message: `${TEXTS}.delete_message`,
          messageParams: { description: row.description },
          confirmLabel: `${TEXTS}.delete_confirm`,
          keepLabel: `${TEXTS}.keep`,
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
    // A draft it made, or its model revised elsewhere, changes what a row says as well.
    this.live.reloadOn(
      ['recurring_expense', 'expense'],
      () => this.refresh(companyId),
      this.destroyRef,
    );
    await this.refresh(companyId);
  }

  private async refresh(companyId: string): Promise<void> {
    try {
      this.rows.set(await this.api.list(companyId));
      this.error.set(null);
    } catch (error) {
      this.error.set(error instanceof RecurringExpenseRefused ? error.code : 'network');
    } finally {
      this.loading.set(false);
    }
  }

  private replace(saved: RecurringExpenseRow): void {
    this.rows.update((rows) => rows.map((one) => (one.id === saved.id ? saved : one)));
  }

  /** Asks the schedule again, its first day kept, and saves what is answered. */
  private async revise(row: RecurringExpenseRow): Promise<void> {
    const company = this.company();
    if (!company || this.busy()) return;
    const descriptor = recurringExpenseForm(
      this.language.current(),
      todayIn(company.timezone),
      row,
    );
    const data: RecurringExpenseDialogData = {
      descriptor,
      group: buildFormGroup(descriptor, {
        ...scheduleValues(row.schedule),
        endsOn: row.endsOn ?? '',
        variableAmount: row.variableAmount,
      }),
      title: `${TEXTS}.revise.title`,
      confirm: `${TEXTS}.revise.confirm`,
    };
    const values = await firstValueFrom(
      this.dialog
        .open<RecurringExpenseDialog, RecurringExpenseDialogData>(RecurringExpenseDialog, {
          data,
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (!values) return;
    this.busy.set(true);
    try {
      this.replace(
        await this.api.revise(company.id, row.id, recurringExpenseRevision(row, values)),
      );
      this.feedback.success(`${TEXTS}.revised`, { description: row.description });
    } catch (error) {
      const field = error instanceof RecurringExpenseRefused ? error.field : null;
      this.feedback.failure(recurringExpenseFailure(field));
    } finally {
      this.busy.set(false);
    }
  }

  private async setPaused(row: RecurringExpenseRow, paused: boolean): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.busy.set(true);
    try {
      this.replace(
        await this.api.revise(companyId, row.id, {
          schedule: row.schedule,
          endsOn: row.endsOn,
          variableAmount: row.variableAmount,
          paused,
        }),
      );
      this.feedback.success(paused ? `${TEXTS}.paused` : `${TEXTS}.resumed`, {
        description: row.description,
      });
    } catch (error) {
      this.error.set(error instanceof RecurringExpenseRefused ? error.code : 'network');
    } finally {
      this.busy.set(false);
    }
  }

  private async remove(row: RecurringExpenseRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.busy.set(true);
    try {
      await this.api.delete(companyId, row.id);
      this.rows.update((rows) => rows.filter((one) => one.id !== row.id));
      this.feedback.effect(`${TEXTS}.deleted`, { description: row.description }, 'definitif');
    } catch (error) {
      this.error.set(error instanceof RecurringExpenseRefused ? error.code : 'network');
    } finally {
      this.busy.set(false);
    }
  }
}

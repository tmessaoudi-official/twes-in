// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog } from '@angular/material/dialog';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { runAction } from '../shared/actions/run-action';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import { FormStep } from '../shared/form/form-step';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { unsavedChanges } from '../shared/form/dirty-count';
import { Feedback } from '../shared/feedback/feedback';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { LiveChanges } from '../shared/realtime/live-changes';
import { RecordDeletes } from '../shared/records/record-deletes';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import type { ActionConfirm } from '../shared/actions/screen-action';
import { StatusBadge } from '../shared/ui/status-badge';
import { instrumentForm, instrumentInput, instrumentValues } from './instruments-forms';
import { InstrumentsFacade } from './instruments-facade';
import {
  INSTRUMENT_STATUS_TONES,
  type InstrumentRow,
  type InstrumentStep,
} from './instruments-types';

/**
 * The cheques and traites received against one issued invoice (docs/SPEC.md § 7, 2026-09-21 18:40). An instrument is
 * not money: the invoice stays due while one is held or deposited, and only cashing it records the payment, which the
 * page is told of through `changed` so it reads the invoice again.
 */
@Component({
  selector: 'app-invoice-instruments',
  imports: [
    MatButtonModule,
    MatCardModule,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DescriptorForm,
    StatusBadge,
  ],
  providers: [InstrumentsFacade],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './invoice-instruments.html',
})
export class InvoiceInstruments {
  readonly companyId = input.required<string>();
  readonly invoiceId = input.required<string>();
  /** What the invoice still has due, as the API's decimal string. */
  readonly amountDue = input.required<string>();
  /** The decimals of the company's currency. */
  readonly scale = input.required<number>();
  /** The company's today (ISO), the day a new instrument starts due on. */
  readonly today = input.required<string>();
  /** Whether the member may receive, move and delete them (`payment.write`). */
  readonly mayPay = input(false);
  /** Said after cashing: the invoice's payments and what is due have changed. */
  readonly changed = output<void>();

  private readonly facade = inject(InstrumentsFacade);
  private readonly feedback = inject(Feedback);
  private readonly dialog = inject(MatDialog);
  private readonly deletes = inject(RecordDeletes);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly items = this.facade.items;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly tones = INSTRUMENT_STATUS_TONES;
  protected readonly group = signal<DescriptorFormGroup | null>(null);
  protected readonly descriptor = instrumentForm();
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  private readonly form = viewChild(DescriptorForm);

  /**
   * What a new instrument can still cover: what is due less what the open ones already promise, in the currency's
   * smallest units. The API refuses more; this only says what to offer.
   */
  protected readonly free = computed(() => {
    const scale = this.scale();
    const open = this.items()
      .filter((row) => row.status === 'held' || row.status === 'deposited')
      .reduce((sum, row) => sum + units(row.amount, scale), 0n);
    const free = units(this.amountDue(), scale) - open;
    return free > 0n ? free : 0n;
  });
  protected readonly mayReceive = computed(() => this.mayPay() && this.free() > 0n);

  constructor() {
    // An instrument typed and not received, against what its form opened with.
    unsavedChanges(this.group, signal(null));
    effect(() => {
      const companyId = this.companyId();
      const invoiceId = this.invoiceId();
      untracked(() => void this.facade.load(companyId, invoiceId));
    });
    // One deleted or put back is the records' change, a step taken on one the invoice's: either is read again.
    this.live.reloadOn(
      ['payment_instrument', 'invoice'],
      () => this.facade.load(this.companyId(), this.invoiceId()),
      this.destroyRef,
    );
  }

  protected open(): void {
    this.facade.clearError();
    this.step.unplaced.set(null);
    this.group.set(
      buildFormGroup(
        this.descriptor,
        instrumentValues(this.today(), written(this.free(), this.scale())),
      ),
    );
  }

  protected close(): void {
    this.group.set(null);
  }

  protected async receive(): Promise<void> {
    const group = this.group();
    if (group === null || this.busy()) return;
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    const answer = await this.step.run(
      () =>
        this.facade.receive(
          this.companyId(),
          this.invoiceId(),
          instrumentInput(group.getRawValue()),
        ),
      (refusals) => this.form()?.showRefused(refusals) ?? refusals,
    );
    if (answer !== null && answer !== 'refused') {
      this.group.set(null);
      this.feedback.success('invoices.instruments.done.received');
    }
  }

  /**
   * Every step on an instrument is one the bank or the paper makes final: a deposit is not taken back from the bank,
   * and none of them has an undo. So each asks through the shared confirmation, which names it « définitif », and the
   * toast after it says the same word (audit 2026-10-06, C-8). Taking a held one out of the portfolio is undone instead.
   */
  protected ask(row: InstrumentRow, step: InstrumentStep): void {
    if (this.busy()) return;
    runAction(
      {
        run: () => void this.advance(row, step),
        confirm: {
          kind: 'definitif',
          title: `invoices.instruments.ask.${step}.title`,
          message: `invoices.instruments.ask.${step}.message`,
          confirmLabel: `invoices.instruments.confirm_${step}`,
          keepLabel: 'invoices.instruments.keep',
        },
      },
      (confirm) =>
        this.dialog
          .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
            data: confirm,
            autoFocus: 'dialog',
          })
          .afterClosed(),
    );
  }

  private async advance(row: InstrumentRow, step: InstrumentStep): Promise<void> {
    if (await this.facade.advance(this.companyId(), this.invoiceId(), row.id, step)) {
      this.feedback.effect(`invoices.instruments.done.${step}`, {}, 'definitif');
      if (step === 'cash') this.changed.emit();
    }
  }

  /**
   * A held one entered by mistake goes without a question, as a record does: its toast offers « Annuler », and
   * « Récemment supprimés » keeps it a day. It is named by its number and bank, else by its amount.
   */
  protected async remove(row: InstrumentRow): Promise<void> {
    if (this.busy()) return;
    const label = [row.number, row.bank].filter((part) => part !== null && part !== '').join(' ');
    const gone = await this.deletes.remove(this.companyId(), 'payment_instrument', [
      { id: row.id, label: label === '' ? row.amount : label },
    ]);
    if (gone) await this.facade.load(this.companyId(), this.invoiceId());
  }
}

const DECIMAL = /^(-?)(\d+)(?:\.(\d+))?$/;

/** The API's decimal string in the currency's smallest units, exactly; an amount is never finer than its currency. */
function units(amount: string, scale: number): bigint {
  const [, sign, whole, decimals = ''] = DECIMAL.exec(amount) ?? [];
  if (whole === undefined) return 0n;
  const value = BigInt(whole + decimals.padEnd(scale, '0').slice(0, scale));
  return sign === '-' ? -value : value;
}

/** Smallest units written back as a decimal string at the currency's scale, as the API takes it. */
function written(amount: bigint, scale: number): string {
  const digits = amount.toString().padStart(scale + 1, '0');
  return scale === 0 ? digits : `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}

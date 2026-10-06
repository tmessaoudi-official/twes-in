// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import { Feedback } from '../shared/feedback/feedback';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { StatusBadge } from '../shared/ui/status-badge';
import { instrumentForm, instrumentInput, instrumentValues } from './instruments-forms';
import { InstrumentsFacade } from './instruments-facade';
import {
  INSTRUMENT_STATUS_TONES,
  type InstrumentRow,
  type InstrumentStep,
} from './instruments-types';

/** A step that cannot be taken back asks once more, on the row, before it is taken. */
interface Confirming {
  readonly id: string;
  readonly step: InstrumentStep | 'delete';
}

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

  protected readonly items = this.facade.items;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly tones = INSTRUMENT_STATUS_TONES;
  protected readonly confirming = signal<Confirming | null>(null);
  protected readonly group = signal<DescriptorFormGroup | null>(null);
  protected readonly descriptor = instrumentForm();

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
    effect(() => {
      const companyId = this.companyId();
      const invoiceId = this.invoiceId();
      untracked(() => void this.facade.load(companyId, invoiceId));
    });
  }

  protected open(): void {
    this.facade.clearError();
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
    if (
      await this.facade.receive(
        this.companyId(),
        this.invoiceId(),
        instrumentInput(group.getRawValue()),
      )
    ) {
      this.group.set(null);
      this.feedback.success('invoices.instruments.done.received');
    }
  }

  protected async advance(row: InstrumentRow, step: InstrumentStep): Promise<void> {
    this.confirming.set(null);
    if (this.busy()) return;
    if (await this.facade.advance(this.companyId(), this.invoiceId(), row.id, step)) {
      this.feedback.success(`invoices.instruments.done.${step}`);
      if (step === 'cash') this.changed.emit();
    }
  }

  protected async remove(row: InstrumentRow): Promise<void> {
    this.confirming.set(null);
    if (this.busy()) return;
    if (await this.facade.remove(this.companyId(), this.invoiceId(), row.id)) {
      this.feedback.success('invoices.instruments.done.deleted');
    }
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

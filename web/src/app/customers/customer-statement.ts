// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { DayCalendarButton } from '../shared/form/day-calendar-button';
import { DayInput } from '../shared/form/day-input';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { CustomerStatementFacade } from './customer-statement-facade';

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

/** A decimal string with nothing in it: "0", "0.000", "-0.00". */
const isZero = (amount: string): boolean => /^-?0*(\.0*)?$/.test(amount);

/**
 * A customer's statement of account (docs/SPEC.md § 7): what they owed at the start of a period, each invoice, credit
 * note and payment of it in the order it happened with what they owed after it, and what they owe at its end. With no
 * day chosen the API answers for the company's year to date, and the fields show the period it resolved. Every figure
 * is the API's; the page lays them out.
 */
@Component({
  selector: 'app-customer-statement',
  imports: [
    FormsModule,
    DayInput,
    DayCalendarButton,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
  ],
  templateUrl: './customer-statement.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomerStatementView {
  private readonly facade = inject(CustomerStatementFacade);

  readonly companyId = input.required<string>();
  readonly customerId = input.required<string>();

  protected readonly statement = this.facade.statement;
  protected readonly error = this.facade.error;
  protected readonly busy = this.facade.busy;
  /** The lines, each saying which of its two columns it fills: a zero is left blank rather than printed. */
  protected readonly rows = computed(() =>
    (this.statement()?.lines ?? []).map((line) => ({
      ...line,
      hasDebit: !isZero(line.debit),
      hasCredit: !isZero(line.credit),
    })),
  );
  /** A limit of zero is none, and says nothing. */
  protected readonly hasLimit = computed(() => !isZero(this.statement()?.creditLimit ?? '0'));
  /** What the customer has to their credit; zero says nothing. */
  protected readonly hasCredit = computed(() => !isZero(this.statement()?.creditBalance ?? '0'));
  /** The days the person chose; empty leaves the period to the API. */
  protected readonly from = signal('');
  protected readonly to = signal('');

  /** The PDF of the period on screen: the days the person chose, else the API's own default period. */
  protected readonly pdfUrl = computed(() =>
    this.facade.pdfUrl(this.companyId(), this.customerId(), { from: this.from(), to: this.to() }),
  );

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      const customerId = this.customerId();
      const period = { from: this.from(), to: this.to() };
      untracked(() => void this.facade.load(companyId, customerId, period));
    });
  }

  /** A day once it is one (or nothing, to clear the limit); what is still being typed waits. */
  protected onFrom(day: string): void {
    if (day === '' || ISO_DAY.test(day)) this.from.set(day);
  }

  protected onTo(day: string): void {
    if (day === '' || ISO_DAY.test(day)) this.to.set(day);
  }
}

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
import { MatDialog } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { firstValueFrom } from 'rxjs';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { buildFormGroup } from '../shared/form/form-builder';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { todayIn } from '../shared/i18n/format';
import { CreditDepositDialog } from './credit-deposit-dialog';
import { creditDepositForm, creditDepositInput, creditDepositValues } from './customer-forms';
import { CustomerStatementFacade } from './customer-statement-facade';

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
  private readonly auth = inject(AuthFacade);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);

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
  protected readonly mayDeposit = computed(() => this.auth.hasPermission('payment.write'));
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

  /** Asked in a dialog; what is recorded is kept to the customer's credit and the statement is read again. */
  protected async deposit(): Promise<void> {
    const timezone = this.auth.me()?.company?.timezone;
    const descriptor = creditDepositForm();
    const group = buildFormGroup(descriptor, creditDepositValues(todayIn(timezone)));
    const values = await firstValueFrom(
      this.dialog
        .open(CreditDepositDialog, { data: { descriptor, group }, autoFocus: 'first-tabbable' })
        .afterClosed(),
    );
    if (!values) return;
    if (
      await this.facade.deposit(this.companyId(), this.customerId(), creditDepositInput(values))
    ) {
      this.feedback.success('customers.credit.recorded');
      await this.facade.load(this.companyId(), this.customerId(), {
        from: this.from(),
        to: this.to(),
      });
    }
  }

  protected onFrom(event: Event): void {
    this.from.set((event.target as HTMLInputElement).value);
  }

  protected onTo(event: Event): void {
    this.to.set((event.target as HTMLInputElement).value);
  }
}

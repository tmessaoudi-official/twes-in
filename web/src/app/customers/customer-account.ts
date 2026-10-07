// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  untracked,
} from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { CustomerAccountFacade } from './customer-account-facade';

/** A decimal string with nothing in it: "0", "0.000", "-0.00". */
const isZero = (amount: string): boolean => /^-?0*(\.0*)?$/.test(amount);

/**
 * The customer's running account as it stands today: what their invoices have due, how much of it is late, what they
 * left on account and where that leaves them against their limit. It is the reading the statement ends on and a
 * delivery's warning weighs, so the figures here and there are the same ones; every figure is the API's.
 */
@Component({
  selector: 'app-customer-account',
  imports: [TranslatePipe, AmountPipe],
  templateUrl: './customer-account.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomerAccountView {
  private readonly facade = inject(CustomerAccountFacade);

  readonly companyId = input.required<string>();
  readonly customerId = input.required<string>();

  protected readonly account = this.facade.account;
  protected readonly error = this.facade.error;
  /** A limit of zero is none, and says nothing. */
  protected readonly hasLimit = computed(() => !isZero(this.account()?.creditLimit ?? '0'));
  /** Money on account is worth a line only when there is some. */
  protected readonly hasOnAccount = computed(() => !isZero(this.account()?.onAccount ?? '0'));

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      const customerId = this.customerId();
      untracked(() => void this.facade.load(companyId, customerId));
    });
  }
}

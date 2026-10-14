// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, effect, input, signal } from '@angular/core';
import { ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { DecimalInput } from '../shared/form/decimal-input';
import { Select } from '../shared/form/select';
import { AmountPipe } from '../shared/i18n/format-pipes';
import {
  BILL_LINES_MAX,
  type BillGroup,
  type BillLineGroup,
  billChargeOptions,
  billLineGroup,
  billTaxName,
  billTaxOptions,
  type SavedBill,
} from './expense-forms';
import type { ExpenseOptions } from './expenses-types';

/**
 * A supplier's bill as it is printed: a line for each VAT rate, its net and the rates on it (a levy such as FODEC beside
 * the VAT), the fixed charges it carries such as the stamp, and, once the API has worked the taxes out, the amount the
 * bill prints for a rate where the supplier rounded otherwise. A bill no longer a draft is read, not edited.
 */
@Component({
  selector: 'app-expense-bill',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    TranslatePipe,
    DecimalInput,
    Select,
    AmountPipe,
  ],
  templateUrl: './expense-bill.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ExpenseBill {
  readonly bill = input.required<BillGroup>();
  readonly options = input.required<ExpenseOptions>();
  /** The draft as the API answered it; null for a new expense, which has no worked-out taxes yet. */
  readonly saved = input<SavedBill | null>(null);
  readonly readOnly = input(false);

  /** Bumped on every value, status or touched change, so an OnPush template re-reads the bill. */
  private readonly revision = signal(0);

  protected readonly taxOptions = computed(() => billTaxOptions(this.options(), this.saved()));
  protected readonly charges = computed(() => billChargeOptions(this.options(), this.saved()));
  protected readonly lines = computed(() => {
    this.revision();
    return this.bill().controls.lines.controls;
  });
  protected readonly full = computed(() => this.lines().length >= BILL_LINES_MAX);
  /** The rates whose printed amount may be given, each with what twes-in works it out to. */
  protected readonly printed = computed(() => {
    const options = this.options();
    const controls = this.bill().controls.printed.controls;
    return (this.saved()?.taxes ?? [])
      .filter((tax) => tax.rate !== null && tax.code in controls)
      .map((tax) => ({
        code: tax.code,
        name: billTaxName(options, tax),
        workedOut: tax.workedOut,
      }));
  });
  /** Whether a line carries a rate whose printed amount can be given only once the expense is saved. */
  protected readonly printedLater = computed(() => {
    this.revision();
    return (
      this.saved() === null &&
      this.bill().controls.lines.controls.some(
        (line) => line.controls.taxComponentIds.value.length > 0,
      )
    );
  });
  /** The lines as a recorded bill reads them: a net, and its rates by name. */
  protected readonly readLines = computed(() => {
    this.revision();
    const names = new Map(this.taxOptions().map((option) => [option.value, option.label]));
    return this.bill()
      .getRawValue()
      .lines.map((line) => ({
        amountNet: line.amountNet,
        taxes: line.taxComponentIds.map((id) => names.get(id) ?? id).join(', '),
      }));
  });

  constructor() {
    effect((onCleanup) => {
      const subscription = this.bill().events.subscribe(() =>
        this.revision.update((revision) => revision + 1),
      );
      onCleanup(() => subscription.unsubscribe());
    });
  }

  protected netError(line: BillLineGroup): string | null {
    this.revision();
    const control = line.controls.amountNet;
    if (!control.touched || control.valid) return null;
    if (control.hasError('required')) return 'expenses.bill.net_required';
    return control.hasError('positive')
      ? 'expenses.bill.net_positive'
      : 'expenses.bill.net_pattern';
  }

  protected taxesError(line: BillLineGroup): string | null {
    this.revision();
    const control = line.controls.taxComponentIds;
    return control.touched && control.hasError('oneVat') ? 'expenses.bill.one_vat' : null;
  }

  protected printedError(code: string): boolean {
    this.revision();
    const control = this.bill().controls.printed.controls[code];
    return control !== undefined && control.touched && control.invalid;
  }

  protected ticked(id: string): boolean {
    this.revision();
    return this.bill().controls.charges.value.includes(id);
  }

  protected tick(id: string, checked: boolean): void {
    const charges = this.bill().controls.charges;
    const others = charges.value.filter((each) => each !== id);
    charges.setValue(checked ? [...others, id] : others);
    charges.markAsDirty();
  }

  protected add(): void {
    if (this.full()) return;
    this.bill().controls.lines.push(billLineGroup(null, this.options()));
  }

  /** The last line stays: a bill always has one. */
  protected remove(index: number): void {
    const lines = this.bill().controls.lines;
    if (lines.length > 1) lines.removeAt(index);
  }
}

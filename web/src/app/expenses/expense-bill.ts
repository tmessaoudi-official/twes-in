// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  linkedSignal,
  signal,
  untracked,
} from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatRadioModule } from '@angular/material/radio';
import { TranslatePipe } from '@ngx-translate/core';
import { DecimalInput } from '../shared/form/decimal-input';
import { Select } from '../shared/form/select';
import { AmountPipe } from '../shared/i18n/format-pipes';
import {
  amountPattern,
  BILL_LINES_MAX,
  type BillGroup,
  type BillLineGroup,
  billChargeOptions,
  billLineGroup,
  billTaxName,
  billTaxOptions,
  grossOfLine,
  type LineRate,
  netOfLine,
  type SavedBill,
} from './expense-forms';
import type { ExpenseOptions } from './expenses-types';

/**
 * A supplier's bill as it is printed: a line for each VAT rate, its net and the rates on it (a levy such as FODEC beside
 * the VAT), the fixed charges it carries such as the stamp, and, once the API has worked the taxes out, the amount the
 * bill prints for a rate where the supplier rounded otherwise. A bill no longer a draft is read, not edited.
 *
 * A receipt shows what was paid, so a new expense is typed taxes included and each net is worked out from it, exactly
 * as the API rounds; a saved draft opens on the nets it holds. What is typed taxes included stays beside the bill,
 * never in it: the nets are what is saved, compared and merged.
 */
@Component({
  selector: 'app-expense-bill',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    MatRadioModule,
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

  /** Kept across a bill rebuilt by a save or another person's change; chosen once, by whether the draft is saved. */
  readonly mode = linkedSignal<BillGroup, BillMode>({
    source: () => this.bill(),
    computation: (_, previous) =>
      previous?.value ?? (untracked(this.saved) === null ? 'gross' : 'net'),
  });

  /** Each line's amount taxes included, as typed or as its net comes to. */
  private readonly grosses = new WeakMap<BillLineGroup, FormControl<string>>();
  /** The lines whose typed total no net reaches exactly, so the nearest was taken. */
  private readonly near = new WeakMap<BillLineGroup, boolean>();

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
      const bill = this.bill();
      let seen = shape(bill);
      const subscription = bill.events.subscribe(() => {
        this.revision.update((revision) => revision + 1);
        const was = seen;
        seen = shape(bill);
        if (this.mode() !== 'gross') return;
        // A line added or removed moves the charges between the receipt's total and the rates' amounts: the nets
        // stay, and what each line comes to is said again.
        if (seen.count !== was.count) {
          this.refill();
          return;
        }
        const charged = seen.charges !== was.charges && seen.count === 1;
        bill.controls.lines.controls.forEach((line, at) => {
          if (charged || seen.rates[at] !== was.rates[at]) this.derive(line);
        });
      });
      onCleanup(() => subscription.unsubscribe());
    });
  }

  protected choose(mode: BillMode): void {
    this.mode.set(mode);
    if (mode === 'gross') this.refill();
  }

  /** The line's amount taxes included, filled from its net the first time it is asked for. */
  protected grossOf(line: BillLineGroup): FormControl<string> {
    let control = this.grosses.get(line);
    if (control === undefined) {
      control = new FormControl(this.grossFor(line) ?? '', { nonNullable: true });
      control.valueChanges.subscribe(() => this.derive(line));
      this.grosses.set(line, control);
    }
    return control;
  }

  protected grossLabel(): string {
    return this.lines().length === 1 ? 'expenses.bill.gross_total' : 'expenses.bill.gross';
  }

  /** The net worked out, said under what was typed; null while there is none. */
  protected grossHint(line: BillLineGroup): { key: string; net: string } | null {
    this.revision();
    const net = line.controls.amountNet;
    if (net.invalid) return null;
    return {
      key: this.near.get(line) ? 'expenses.bill.gross_hint_near' : 'expenses.bill.gross_hint',
      net: net.value,
    };
  }

  protected grossError(line: BillLineGroup): string | null {
    this.revision();
    const control = this.grosses.get(line);
    const net = line.controls.amountNet;
    if (control === undefined || net.valid || !(control.touched || net.touched)) return null;
    const typed = control.value.trim();
    if (typed === '') return 'expenses.bill.gross_required';
    if (!new RegExp(`^${amountPattern(this.options().currencyScale)}$`).test(typed)) {
      return 'expenses.bill.net_pattern';
    }
    if (/^[0.]+$/.test(typed)) return 'expenses.bill.net_positive';
    return this.ratesOf(line) === null || this.chargesFor() === null
      ? 'expenses.bill.gross_unknown'
      : 'expenses.bill.gross_charges';
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

  /** The net of what was typed taxes included; none while it is not an amount the bill can come to. */
  private derive(line: BillLineGroup): void {
    const control = this.grosses.get(line);
    if (this.mode() !== 'gross' || control === undefined) return;
    const rates = this.ratesOf(line);
    const charges = this.chargesFor();
    const found =
      rates === null || charges === null
        ? null
        : netOfLine(control.value.trim(), rates, charges, this.options().currencyScale);
    this.near.set(line, found !== null && !found.exact);
    const net = line.controls.amountNet;
    if (net.value !== (found?.net ?? '')) {
      net.setValue(found?.net ?? '');
      net.markAsDirty();
    }
    this.revision.update((revision) => revision + 1);
  }

  /** Says again what each line comes to from its net, leaving the nets as they are and a line with none as typed. */
  private refill(): void {
    for (const line of this.bill().controls.lines.controls) {
      const control = this.grosses.get(line);
      const gross = this.grossFor(line);
      if (control === undefined || gross === null) continue;
      control.setValue(gross, { emitEvent: false });
      this.near.set(line, false);
    }
    this.revision.update((revision) => revision + 1);
  }

  private grossFor(line: BillLineGroup): string | null {
    const net = line.controls.amountNet;
    const rates = this.ratesOf(line);
    const charges = this.chargesFor();
    if (net.invalid || rates === null || charges === null) return null;
    return grossOfLine(net.value.trim(), rates, charges, this.options().currencyScale);
  }

  /** The rates on a line; null when one is no longer offered, whose rate is not known here. */
  private ratesOf(line: BillLineGroup): LineRate[] | null {
    const taxes = this.options().taxes;
    const rates: LineRate[] = [];
    for (const id of line.controls.taxComponentIds.value) {
      const tax = taxes.find((each) => each.id === id);
      if (tax === undefined) return null;
      rates.push({ rate: tax.rate, entersVatBase: tax.entersVatBase });
    }
    return rates;
  }

  /** The fixed charges a one-line bill's total carries; a bill at several rates is typed without them. */
  private chargesFor(): string[] | null {
    const bill = this.bill();
    if (bill.controls.lines.length !== 1) return [];
    const charges = this.options().charges;
    const amounts: string[] = [];
    for (const id of bill.controls.charges.value) {
      const charge = charges.find((each) => each.id === id);
      if (charge === undefined) return null;
      amounts.push(charge.amount);
    }
    return amounts;
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

/** Whether the bill's amounts are typed taxes included or before tax. */
type BillMode = 'gross' | 'net';

/** What, when it changes, moves a line's net worked out from its amount taxes included. */
function shape(bill: BillGroup): { count: number; charges: string; rates: string[] } {
  const lines = bill.controls.lines.controls;
  return {
    count: lines.length,
    charges: bill.controls.charges.value.join(),
    rates: lines.map((line) => line.controls.taxComponentIds.value.join()),
  };
}

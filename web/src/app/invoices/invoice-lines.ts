// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, effect, input, signal } from '@angular/core';
import { ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe } from '../shared/i18n/format-pipes';
import {
  applyProduct,
  type LineControls,
  type LineGroup,
  lineGroup,
  type LinesArray,
  offeredLineTaxes,
} from './invoice-forms';
import type { CustomerOption, InvoiceOptions, TaxFamily, TaxOption } from './invoices-types';

type CheckedField = keyof Omit<
  LineControls,
  'productId' | 'taxComponentIds' | 'sourceDeliveryNoteLineId'
>;

/**
 * A document's lines, edited in place: a product fills a line's description, unit, price and default taxes, a new
 * line takes the customer's discount, and every value stays editable. Each line tax the customer may be charged is a
 * box; a tax already on a line stays offered, so it can be taken off. A line drafted from a delivery note says so.
 * The API works the figures out when the document is saved.
 *
 * Kept apart from the delivery notes' lines on purpose: an invoice line adds a discount and where it came from, and a
 * shared document lines component is recorded for the architecture pass (docs/SPEC.md § 8 row 43).
 */
@Component({
  selector: 'app-invoice-lines',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    TranslatePipe,
    AmountPipe,
  ],
  templateUrl: './invoice-lines.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InvoiceLines {
  readonly lines = input.required<LinesArray>();
  readonly options = input.required<InvoiceOptions>();
  readonly customer = input<CustomerOption | null>(null);
  readonly readOnly = input(false);
  /** Each line's net as last saved, by position; shown while the line is unchanged. */
  readonly nets = input<readonly string[]>([]);

  /** Bumped on every value, status or touched change, so an OnPush template re-reads the lines. */
  private readonly revision = signal(0);
  private readonly excluded = computed<readonly TaxFamily[]>(
    () => this.customer()?.excludedFamilies ?? [],
  );
  private readonly offered = computed(
    () => new Set(offeredLineTaxes(this.options(), this.excluded()).map((tax) => tax.id)),
  );

  constructor() {
    effect((onCleanup) => {
      const subscription = this.lines().events.subscribe(() =>
        this.revision.update((revision) => revision + 1),
      );
      onCleanup(() => subscription.unsubscribe());
    });
  }

  protected groups(): LineGroup[] {
    this.revision();
    return this.lines().controls;
  }

  protected taxesOf(line: LineGroup): TaxOption[] {
    this.revision();
    const offered = this.offered();
    const charged = line.controls.taxComponentIds.value;
    return this.options().taxes.filter(
      (tax) => tax.kind === 'percentage_line' && (offered.has(tax.id) || charged.includes(tax.id)),
    );
  }

  protected charges(line: LineGroup, taxId: string): boolean {
    this.revision();
    return line.controls.taxComponentIds.value.includes(taxId);
  }

  protected fromDeliveryNote(line: LineGroup): boolean {
    this.revision();
    return line.controls.sourceDeliveryNoteLineId.value !== '';
  }

  protected netOf(index: number, line: LineGroup): string | null {
    this.revision();
    return line.pristine ? (this.nets()[index] ?? null) : null;
  }

  protected errorKey(line: LineGroup, field: CheckedField): string | null {
    this.revision();
    const control = line.controls[field];
    if (!control.touched || control.disabled) return null;
    const invalid = control.invalid || (field === 'quantity' && line.hasError('quantityDecimals'));
    return invalid ? `invoices.lines.errors.${field}` : null;
  }

  protected add(): void {
    this.lines().push(lineGroup(null, this.options(), this.customer()));
  }

  protected remove(index: number): void {
    this.lines().removeAt(index);
  }

  protected chooseProduct(line: LineGroup, productId: string): void {
    applyProduct(line, productId, this.options(), this.excluded());
  }

  protected toggleTax(line: LineGroup, taxId: string, checked: boolean): void {
    const others = line.controls.taxComponentIds.value.filter((id) => id !== taxId);
    line.controls.taxComponentIds.setValue(checked ? [...others, taxId] : others);
    line.controls.taxComponentIds.markAsDirty();
  }
}

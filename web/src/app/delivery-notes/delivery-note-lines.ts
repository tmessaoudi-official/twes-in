// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, effect, input, signal } from '@angular/core';
import { ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import {
  applyProduct,
  type LineControls,
  type LineGroup,
  lineGroup,
  type LinesArray,
  offeredTaxes,
} from './delivery-note-forms';
import type { DeliveryNoteOptions, LineTaxOption, TaxFamily } from './delivery-notes-types';

type CheckedField = keyof Omit<LineControls, 'productId' | 'taxComponentIds'>;

/**
 * A note's lines, edited in place: a product fills a line's description, unit, price and default taxes, and every
 * value stays editable. Each tax the customer may be charged is a box; a tax already on a line stays offered, so it
 * can be taken off. The API computes the figures when the note is saved.
 */
@Component({
  selector: 'app-delivery-note-lines',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    TranslatePipe,
  ],
  templateUrl: './delivery-note-lines.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DeliveryNoteLines {
  readonly lines = input.required<LinesArray>();
  readonly options = input.required<DeliveryNoteOptions>();
  readonly excludedFamilies = input<readonly TaxFamily[]>([]);
  readonly readOnly = input(false);

  /** Bumped on every value, status or touched change, so an OnPush template re-reads the lines. */
  private readonly revision = signal(0);
  private readonly offered = computed(
    () => new Set(offeredTaxes(this.options(), this.excludedFamilies()).map((tax) => tax.id)),
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

  protected taxesOf(line: LineGroup): LineTaxOption[] {
    this.revision();
    const offered = this.offered();
    const charged = line.controls.taxComponentIds.value;
    return this.options().taxes.filter((tax) => offered.has(tax.id) || charged.includes(tax.id));
  }

  protected charges(line: LineGroup, taxId: string): boolean {
    this.revision();
    return line.controls.taxComponentIds.value.includes(taxId);
  }

  protected errorKey(line: LineGroup, field: CheckedField): string | null {
    this.revision();
    const control = line.controls[field];
    if (!control.touched || control.disabled) return null;
    const invalid = control.invalid || (field === 'quantity' && line.hasError('quantityDecimals'));
    return invalid ? `delivery_notes.lines.errors.${field}` : null;
  }

  protected add(): void {
    this.lines().push(lineGroup(null, this.options()));
  }

  protected remove(index: number): void {
    this.lines().removeAt(index);
  }

  protected chooseProduct(line: LineGroup, productId: string): void {
    applyProduct(line, productId, this.options(), this.excludedFamilies());
  }

  protected toggleTax(line: LineGroup, taxId: string, checked: boolean): void {
    const others = line.controls.taxComponentIds.value.filter((id) => id !== taxId);
    line.controls.taxComponentIds.setValue(checked ? [...others, taxId] : others);
    line.controls.taxComponentIds.markAsDirty();
  }
}

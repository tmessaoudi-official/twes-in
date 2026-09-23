// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';
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
  pickedProduct,
} from './invoice-forms';
import type {
  CustomerOption,
  InvoiceOptions,
  ProductOption,
  TaxFamily,
  TaxOption,
} from './invoices-types';
import { DecimalInput } from '../shared/form/decimal-input';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { ProductScans } from '../products/product-scans';
import { InvoicesFacade } from './invoices-facade';

type CheckedField = keyof Omit<
  LineControls,
  'productId' | 'productReference' | 'productName' | 'taxComponentIds' | 'sourceDeliveryNoteLineId'
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
    DecimalInput,
    PickField,
    AmountPipe,
  ],
  templateUrl: './invoice-lines.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InvoiceLines {
  private readonly facade = inject(InvoicesFacade);
  private readonly scans = inject(ProductScans);

  readonly lines = input.required<LinesArray>();
  /** Whose company's catalogue the pickers ask; a line is never offered another company's products. */
  readonly companyId = input.required<string>();
  readonly options = input.required<InvoiceOptions>();
  readonly customer = input<CustomerOption | null>(null);
  readonly readOnly = input(false);
  /** Each line's net as last saved, by position; shown while the line is unchanged. */
  readonly nets = input<readonly string[]>([]);

  /** Bumped on every value, status or touched change, so an OnPush template re-reads the lines. */
  private readonly revision = signal(0);
  /** Every product the pickers have answered, so what is chosen on a line can be found again from its id. */
  private readonly known = new Map<string, ProductOption>();
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

  /** What the picker shows on a line: the product it names, in the words the line itself carries. */
  protected productOf(line: LineGroup): PickOption | null {
    this.revision();
    return pickedProduct(line);
  }

  protected readonly searchProducts = async (words: string): Promise<readonly PickOption[]> => {
    const found = await this.facade.pickProducts(this.companyId(), { words });
    for (const product of found) this.known.set(product.id, product);
    return found.map((product) => ({
      id: product.id,
      code: product.reference,
      name: product.name,
    }));
  };

  /** A pack scanned into the line enters the pieces it holds; a single piece leaves the quantity as it is. */
  protected async scannedInto(line: LineGroup, code: string): Promise<void> {
    const productId = line.controls.productId.value;
    if (productId === '') return;
    const pieces = await this.scans.piecesPerScan(code, productId);
    if (pieces === null || line.controls.productId.value !== productId) return;
    line.controls.quantity.setValue(String(pieces));
    line.markAsDirty();
  }

  protected chooseProduct(line: LineGroup, option: PickOption | null): void {
    const product = option === null ? null : (this.known.get(option.id) ?? null);
    applyProduct(line, product, this.options(), this.excluded());
    line.markAsDirty();
  }

  protected toggleTax(line: LineGroup, taxId: string, checked: boolean): void {
    const others = line.controls.taxComponentIds.value.filter((id) => id !== taxId);
    line.controls.taxComponentIds.setValue(checked ? [...others, taxId] : others);
    line.controls.taxComponentIds.markAsDirty();
  }
}

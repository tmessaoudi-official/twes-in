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
import { ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { atScale } from '../shared/i18n/format';
import { AmountPipe } from '../shared/i18n/format-pipes';
import {
  applyProduct,
  dropRefusedLineTaxes,
  type LineControls,
  type LineGroup,
  lineGroup,
  type LinesArray,
  namesALot,
  offeredLineTaxes,
  pickedProduct,
  sourceLeftOf,
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
import { Select, type SelectOption } from '../shared/form/select';
import { ProductScans } from '../products/product-scans';
import { InvoicesFacade } from './invoices-facade';

type CheckedField = keyof Omit<
  LineControls,
  | 'productId'
  | 'productReference'
  | 'productName'
  | 'productTracking'
  | 'taxComponentIds'
  | 'sourceDeliveryNoteLineId'
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
    Select,
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
  protected readonly unitOptions = computed(() =>
    this.options().units.map((unit) => ({ value: unit.id, label: `${unit.code} · ${unit.name}` })),
  );
  readonly options = input.required<InvoiceOptions>();
  readonly customer = input<CustomerOption | null>(null);
  /** Whether the company has price lists on: a line then starts from the price of the customer's list. */
  readonly priceLists = input(false);
  readonly readOnly = input(false);
  private readonly taxChoices = new Map<string, SelectOption[]>();
  /** Whether the document is a credit note, whose lines may say their goods came back to stock. */
  readonly returnable = input(false);
  /** Each line's net as last saved, by position; shown while the line is unchanged. */
  readonly nets = input<readonly string[]>([]);

  /** Bumped on every value, status or touched change, so an OnPush template re-reads the lines. */
  private readonly revision = signal(0);
  /** Every product the pickers have answered, so what is chosen on a line can be found again from its id. */
  private readonly known = new Map<string, ProductOption>();
  /** The price list that set each line's price, with the price it set. */
  private readonly listed = new WeakMap<LineGroup, { name: string; price: string }>();
  private readonly excluded = computed<readonly TaxFamily[]>(
    () => this.customer()?.excludedFamilies ?? [],
  );
  private readonly offered = computed(
    () => new Set(offeredLineTaxes(this.options(), this.excluded()).map((tax) => tax.id)),
  );

  constructor() {
    // Another customer may pay another price: the lines picked on this screen start again from their lists.
    let seen: string | null | undefined;
    effect(() => {
      const id = this.customer()?.id ?? null;
      const changed = seen !== undefined && seen !== id;
      seen = id;
      if (changed) {
        untracked(() =>
          this.lines().controls.forEach((line) => {
            dropRefusedLineTaxes(line, this.options(), this.excluded());
            void this.reprice(line);
          }),
        );
      }
    });
    effect((onCleanup) => {
      const subscription = this.lines().events.subscribe(() =>
        this.revision.update((revision) => revision + 1),
      );
      onCleanup(() => subscription.unsubscribe());
    });
  }

  /** A line of a product tracked by lot or serial asks which one it sells. */
  protected namesALot(line: LineGroup): boolean {
    this.revision();
    return namesALot(line);
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

  /**
   * What a line's taxes Select offers. The array is the same one while the offer is, so the Select is not handed a
   * new input at every check.
   */
  protected taxOptionsOf(line: LineGroup, index: number): SelectOption[] {
    const taxes = this.taxesOf(line);
    const key = `${index}|${taxes.map((tax) => `${tax.id}:${tax.name}`).join(',')}`;
    let options = this.taxChoices.get(key);
    if (options === undefined) {
      options = taxes.map((tax) => ({
        value: tax.id,
        label: tax.name,
        testId: `line-${index}-tax-${tax.code}`,
      }));
      this.taxChoices.set(key, options);
    }
    return options;
  }

  protected fromDeliveryNote(line: LineGroup): boolean {
    this.revision();
    return line.controls.sourceDeliveryNoteLineId.value !== '';
  }

  /**
   * What a line taken from a delivery note may still invoice, shown under its quantity, which it caps; the quantity's
   * error takes its place. One condition, since a hint nested in a second block is not projected under the field.
   */
  protected leftOf(line: LineGroup): string | null {
    this.revision();
    return this.readOnly() || this.errorKey(line, 'quantity') !== null ? null : sourceLeftOf(line);
  }

  protected netOf(index: number, line: LineGroup): string | null {
    this.revision();
    return line.pristine ? (this.nets()[index] ?? null) : null;
  }

  protected errorKey(line: LineGroup, field: CheckedField): string | null {
    this.revision();
    const control = line.controls[field];
    if (!control.touched || control.disabled) return null;
    if (field === 'quantity' && control.valid && line.hasError('aboveSource')) {
      return 'invoices.lines.errors.quantity_above_source';
    }
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
    this.listed.delete(line);
    void this.reprice(line);
  }

  /** The price list that set a line's price, while the price on the line is still the one it set. */
  protected listOf(line: LineGroup): string | null {
    this.revision();
    const set = this.listed.get(line);
    return set !== undefined && set.price === line.controls.unitPriceNet.value ? set.name : null;
  }

  /**
   * Starts the line at the price the customer's list gives for its quantity. A price the person typed is theirs and
   * stays: only the shelf price, or the price a list set a moment ago, is replaced.
   */
  protected async reprice(line: LineGroup): Promise<void> {
    const productId = line.controls.productId.value;
    const product = this.known.get(productId);
    if (!this.priceLists() || product === undefined) return;
    const quantity = line.controls.quantity.value;
    const resolved = await this.facade.productPrice(
      this.companyId(),
      productId,
      this.customer()?.id ?? null,
      quantity === '' ? '1' : quantity,
    );
    if (resolved === null || line.controls.productId.value !== productId) return;
    const scale = this.options().currencyScale;
    const current = line.controls.unitPriceNet.value;
    if (
      current !== atScale(product.unitPriceNet, scale) &&
      current !== this.listed.get(line)?.price
    )
      return;
    const next = atScale(resolved.unitPriceNet, scale);
    if (next !== current) {
      line.controls.unitPriceNet.setValue(next);
      line.markAsDirty();
    }
    if (resolved.priceListName === null) this.listed.delete(line);
    else this.listed.set(line, { name: resolved.priceListName, price: next });
    this.revision.update((revision) => revision + 1);
  }

  protected toggleReturned(line: LineGroup, checked: boolean): void {
    line.controls.returned.setValue(checked);
    line.controls.returned.markAsDirty();
  }
}

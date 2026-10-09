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
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import {
  applyProduct,
  type LineControls,
  type LineGroup,
  lineGroup,
  type LinesArray,
  namesALot,
  offeredTaxes,
  pickedProduct,
} from './delivery-note-forms';
import { atScale } from '../shared/i18n/format';
import type {
  CustomerOption,
  DeliveryNoteOptions,
  LineTaxOption,
  ProductOption,
  TaxFamily,
} from './delivery-notes-types';
import { DecimalInput } from '../shared/form/decimal-input';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { Select, type SelectOption } from '../shared/form/select';
import { type IssuedLine, IssuedLines } from '../shared/ui/issued-lines';
import { LineSubstitutes } from './delivery-note-line-substitutes';
import { ProductScans } from '../products/product-scans';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import { productPhotoUrl } from '../products/product-photo-url';
import type { LineFigures } from '../shared/documents/document-figures';
import { LineFiguresView } from '../shared/documents/line-figures';

type CheckedField = keyof Omit<
  LineControls,
  'productId' | 'productReference' | 'productName' | 'productTracking' | 'taxComponentIds'
>;

/**
 * A note's lines, edited in place: a product fills a line's description, unit, price and default taxes, and every
 * value stays editable. Each tax the customer may be charged is a box; a tax already on a line stays offered, so it
 * can be taken off. Each line's figures are the API's, worked out as the note is typed (row 224), and shown folded.
 */
@Component({
  selector: 'app-delivery-note-lines',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    Select,
    TranslatePipe,
    DecimalInput,
    PickField,
    LineSubstitutes,
    IssuedLines,
    LineFiguresView,
  ],
  templateUrl: './delivery-note-lines.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DeliveryNoteLines {
  private readonly facade = inject(DeliveryNotesFacade);
  private readonly scans = inject(ProductScans);

  readonly lines = input.required<LinesArray>();
  /** Whose company's catalogue the pickers ask; a line is never offered another company's products. */
  readonly companyId = input.required<string>();
  protected readonly unitOptions = computed(() =>
    this.options().units.map((unit) => ({ value: unit.id, label: unit.name })),
  );
  readonly options = input.required<DeliveryNoteOptions>();
  readonly excludedFamilies = input<readonly TaxFamily[]>([]);
  readonly readOnly = input(false);
  private readonly taxChoices = new Map<string, SelectOption[]>();
  /** Whose note it is: their price lists decide what a line starts at. */
  readonly customer = input<CustomerOption | null>(null);
  readonly priceLists = input(false);
  /** Each line's figures as the note stands typed, by position, or null where none could be worked out yet. */
  readonly figures = input<readonly (LineFigures | null)[] | null>(null);
  /** A tax's name by its code, for the taxes a line charges, kept while they stay the same so a fold stays open. */
  private readonly taxNamers = new Map<string, (code: string) => string | null>();

  /** Bumped on every value, status or touched change, so an OnPush template re-reads the lines. */
  private readonly revision = signal(0);
  /** Every product the pickers have answered, so what is chosen on a line can be found again from its id. */
  private readonly known = new Map<string, ProductOption>();
  /** The price list that set each line's price, with the price it set. */
  private readonly listed = new WeakMap<LineGroup, { name: string; price: string }>();
  /** The price a line held when its list could not be asked, while it still holds it. */
  private readonly unchecked = new WeakMap<LineGroup, string>();
  /** The latest question asked for each line: an earlier quantity's answer arriving late is dropped. */
  private readonly asked = new WeakMap<LineGroup, number>();
  private asking = 0;
  private readonly offered = computed(
    () => new Set(offeredTaxes(this.options(), this.excludedFamilies()).map((tax) => tax.id)),
  );
  /** The lines as a validated note reads them: values, never fields drawn disabled. */
  protected readonly issued = computed<IssuedLine[]>(() => {
    this.revision();
    const units = new Map(this.options().units.map((unit) => [unit.id, unit]));
    const taxes = new Map(this.options().taxes.map((tax) => [tax.id, tax.name]));
    return this.lines().controls.map((line) => {
      const value = line.getRawValue();
      const unit = units.get(value.unitId);
      return {
        description: value.description,
        reference: value.productId === '' ? null : value.productReference,
        lot: value.lotCode === '' ? null : value.lotCode,
        notes: [],
        quantity: value.quantity,
        quantityScale: unit?.decimals ?? null,
        unit: unit?.name ?? '',
        unitPrice: value.unitPriceNet,
        discountRate: null,
        discountAmount: null,
        taxes: value.taxComponentIds
          .map((id) => taxes.get(id) ?? '')
          .filter((name) => name !== '')
          .join(', '),
        net: null,
      };
    });
  });

  constructor() {
    // Another customer may pay another price: the lines picked on this screen start again from their lists.
    let seen: string | null | undefined;
    effect(() => {
      const id = this.customer()?.id ?? null;
      const changed = seen !== undefined && seen !== id;
      seen = id;
      if (changed)
        untracked(() => this.lines().controls.forEach((line) => void this.reprice(line)));
    });
    effect((onCleanup) => {
      const subscription = this.lines().events.subscribe(() =>
        this.revision.update((revision) => revision + 1),
      );
      onCleanup(() => subscription.unsubscribe());
    });
  }

  /** A line of a product tracked by lot or serial asks which one it hands over. */
  protected namesALot(line: LineGroup): boolean {
    this.revision();
    return namesALot(line);
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
      picture:
        product.mainPhotoId === null
          ? null
          : productPhotoUrl(this.companyId(), product.id, product.mainPhotoId, 'small'),
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
    applyProduct(line, product, this.options(), this.excludedFamilies());
    line.markAsDirty();
    this.listed.delete(line);
    void this.reprice(line);
  }

  /** Whether the line's price is one its list could not be asked about, while it is still on the line. */
  protected priceUnchecked(line: LineGroup): boolean {
    this.revision();
    const held = this.unchecked.get(line);
    return held !== undefined && held === line.controls.unitPriceNet.value;
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
    const ask = ++this.asking;
    this.asked.set(line, ask);
    const resolved = await this.facade.productPrice(
      this.companyId(),
      productId,
      this.customer()?.id ?? null,
      quantity === '' ? '1' : quantity,
    );
    if (this.asked.get(line) !== ask || line.controls.productId.value !== productId) return;
    if (resolved === null) {
      // A wrong price on a fiscal document is the expensive failure: the line says its price was not checked.
      this.unchecked.set(line, line.controls.unitPriceNet.value);
      this.listed.delete(line);
      this.revision.update((revision) => revision + 1);
      return;
    }
    this.unchecked.delete(line);
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

  /** Puts a substitute on the line in place of its product, the quantity asked staying as it is. */
  protected async swapTo(line: LineGroup, productId: string): Promise<void> {
    const [product] = await this.facade.pickProducts(this.companyId(), { ids: [productId] });
    if (product === undefined) return;
    this.known.set(product.id, product);
    this.chooseProduct(line, { id: product.id, code: product.reference, name: product.name });
  }

  protected figuresOf(index: number): LineFigures | null {
    return this.figures()?.[index] ?? null;
  }

  /** How a line's figures name its taxes: by the names of the taxes the line charges. */
  protected taxNamesFor(line: LineGroup): (code: string) => string | null {
    this.revision();
    const charged = new Set(line.controls.taxComponentIds.value);
    const pairs = this.options()
      .taxes.filter((tax) => charged.has(tax.id))
      .map((tax): [string, string] => [tax.code, tax.name]);
    const key = JSON.stringify(pairs);
    let named = this.taxNamers.get(key);
    if (named === undefined) {
      const names = new Map(pairs);
      named = (code: string) => names.get(code) ?? null;
      this.taxNamers.set(key, named);
    }
    return named;
  }
}

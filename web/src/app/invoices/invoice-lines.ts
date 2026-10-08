// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  Injector,
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
import { type IssuedLine, IssuedLines } from '../shared/ui/issued-lines';
import {
  applyProduct,
  dropRefusedLineTaxes,
  type LineControls,
  type LineGroup,
  lineDiscount,
  lineGroup,
  type LinesArray,
  lineStock,
  type OnHand,
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
import { Label } from '../shared/a11y/label';
import { DecimalInput } from '../shared/form/decimal-input';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { Select, type SelectOption } from '../shared/form/select';
import { ProductScans } from '../products/product-scans';
import { InventoryFacade } from '../inventory/inventory-facade';
import { LineCatalogue } from './line-catalogue';
import type { LineFigures } from '../shared/documents/document-figures';
import { LineFiguresView } from '../shared/documents/line-figures';

type CheckedField = keyof Omit<
  LineControls,
  | 'productId'
  | 'productReference'
  | 'productName'
  | 'productTracking'
  | 'taxComponentIds'
  | 'sourceDeliveryNoteLineId'
  | 'discountKind'
>;

/**
 * A document's lines, edited in place: a product fills a line's description, unit, price and default taxes, a new
 * line takes the customer's discount, and every value stays editable. Each line tax the customer may be charged is a
 * box; a tax already on a line stays offered, so it can be taken off. A line drafted from a delivery note says so.
 * The API works the figures out when the document is saved. A quote's screen uses it too, providing its own
 * `LineCatalogue` and asking no lot.
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
    IssuedLines,
    LineFiguresView,
    Label,
  ],
  templateUrl: './invoice-lines.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InvoiceLines {
  private readonly facade = inject(LineCatalogue);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private readonly injector = inject(Injector);
  private readonly scans = inject(ProductScans);
  private readonly inventory = inject(InventoryFacade);

  readonly lines = input.required<LinesArray>();
  /** Whose company's catalogue the pickers ask; a line is never offered another company's products. */
  readonly companyId = input.required<string>();
  protected readonly unitOptions = computed(() =>
    this.options().units.map((unit) => ({ value: unit.id, label: unit.name })),
  );
  readonly options = input.required<InvoiceOptions>();
  readonly customer = input<CustomerOption | null>(null);
  /** Whether the company has price lists on: a line then starts from the price of the customer's list. */
  readonly priceLists = input(false);
  readonly readOnly = input(false);
  private readonly taxChoices = new Map<string, SelectOption[]>();
  /** The same function while a line charges the same taxes, so the figures are not handed a new input at every check. */
  private readonly taxNamers = new Map<string, (code: string) => string | null>();
  /** Whether the document is a credit note, whose lines may say their goods came back to stock. */
  readonly returnable = input(false);
  /** Whether a line names the lot or serial it sells: a document that hands goods over does, a quote does not. */
  readonly lots = input(true);
  /**
   * Whether each line says what is on hand of its product and what the document leaves: for a document that takes goods
   * out or will, read by somebody who may read stock, while the company keeps stock.
   */
  readonly stock = input(false);
  /** The establishment the document is made at, whose shelves the stock is read from; null for the main one. */
  readonly establishmentId = input<string | null>(null);
  /** Each line's net as last saved, by position; shown while the line is unchanged. */
  readonly nets = input<readonly string[]>([]);
  /**
   * Each line's figures as the document stands typed, by position, or null where none are known yet; they take the
   * saved net's place.
   */
  readonly figures = input<readonly (LineFigures | null)[] | null>(null);

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
  private readonly excluded = computed<readonly TaxFamily[]>(
    () => this.customer()?.excludedFamilies ?? [],
  );
  private readonly offered = computed(
    () => new Set(offeredLineTaxes(this.options(), this.excluded()).map((tax) => tax.id)),
  );
  /** What the establishment holds of the lines' products whose stock is kept, by product. */
  private readonly onHand = signal<ReadonlyMap<string, OnHand>>(new Map());
  private stockRequest = 0;
  /** What to ask of stock: the company, the establishment and the products named, each once, in a stable order. */
  private readonly stockAsked = computed(
    () => {
      this.revision();
      if (!this.stock() || this.readOnly() || this.companyId() === '') return null;
      const products = [
        ...new Set(this.lines().controls.map((line) => line.controls.productId.value)),
      ]
        .filter((id) => id !== '')
        .sort();
      return { companyId: this.companyId(), establishmentId: this.establishmentId(), products };
    },
    { equal: (a, b) => JSON.stringify(a) === JSON.stringify(b) },
  );
  protected readonly stocks = computed(() => {
    this.revision();
    return lineStock(this.lines(), this.onHand());
  });

  /** The lines as an issued invoice reads them: values, never fields drawn disabled. */
  protected readonly issued = computed<IssuedLine[]>(() => {
    this.revision();
    const units = new Map(this.options().units.map((unit) => [unit.id, unit]));
    const taxes = new Map(this.options().taxes.map((tax) => [tax.id, tax.name]));
    return this.lines().controls.map((line, index) => {
      const value = line.getRawValue();
      const unit = units.get(value.unitId);
      const notes: string[] = [];
      if (value.sourceDeliveryNoteLineId !== '') notes.push('invoices.lines.from_delivery_note');
      if (this.returnable() && value.returned) notes.push('invoices.lines.returned');
      const givesBack = value.deductsInvoiceId !== '';
      if (givesBack) notes.push('invoices.lines.gives_back');
      return {
        description: value.description,
        reference: value.productId === '' ? null : value.productReference,
        lot: value.lotCode === '' ? null : value.lotCode,
        notes,
        // Billed back: minus one at the deposit's price, as Factur-X writes it.
        quantity: givesBack ? `-${value.quantity}` : value.quantity,
        quantityScale: unit?.decimals ?? null,
        unit: unit?.name ?? '',
        unitPrice: value.unitPriceNet,
        ...lineDiscount(value),
        taxes: value.taxComponentIds
          .map((id) => taxes.get(id) ?? '')
          .filter((name) => name !== '')
          .join(', '),
        net: this.nets()[index] ?? null,
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
      if (changed) {
        untracked(() =>
          this.lines().controls.forEach((line) => {
            dropRefusedLineTaxes(line, this.options(), this.excluded());
            void this.reprice(line);
          }),
        );
      }
    });
    effect(() => {
      const asked = this.stockAsked();
      untracked(() => void this.readStock(asked));
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
    return this.lots() && namesALot(line);
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

  /** A line giving a deposit invoice back: the API writes it from the deposit, so it is shown, never edited. */
  protected givesBack(line: LineGroup): boolean {
    this.revision();
    return line.controls.deductsInvoiceId.value !== '';
  }

  /** A line's taxes, named, for a line shown rather than edited. */
  protected taxNamesOf(line: LineGroup): string {
    this.revision();
    const names = new Map(this.options().taxes.map((tax) => [tax.id, tax.name]));
    return line.controls.taxComponentIds.value
      .map((id) => names.get(id) ?? '')
      .filter((name) => name !== '')
      .join(', ');
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

  protected figuresOf(index: number): LineFigures | null {
    return this.figures()?.[index] ?? null;
  }

  /** How a line's figures name its taxes: by the names of the taxes the line charges, found by their code. */
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
    if (field === 'discountAmount' && control.valid && line.hasError('discountAboveLine')) {
      return 'invoices.lines.errors.discount_above_line';
    }
    const invalid = control.invalid || (field === 'quantity' && line.hasError('quantityDecimals'));
    return invalid ? `invoices.lines.errors.${field}` : null;
  }

  /** How a quantity of stock counted in `unitId` is shown: at its unit's decimals, with its name. */
  protected stockUnit(unitId: string): { scale: number | null; name: string } {
    const unit = this.options().units.find((each) => each.id === unitId);
    return { scale: unit?.decimals ?? null, name: unit?.name ?? '' };
  }

  protected short(left: string | null): boolean {
    return left !== null && left.startsWith('-');
  }

  /** Reads the stock again for what is asked; an answer to an earlier question is dropped. */
  private async readStock(
    asked: { companyId: string; establishmentId: string | null; products: string[] } | null,
  ): Promise<void> {
    const request = ++this.stockRequest;
    const rows =
      asked === null || asked.products.length === 0
        ? []
        : await this.inventory.onHand(asked.companyId, asked.establishmentId, asked.products);
    if (request !== this.stockRequest) return;
    this.onHand.set(
      new Map(rows.map((row) => [row.productId, { unitId: row.unitId, onHand: row.onHand }])),
    );
  }

  /** Whether the line is discounted by an amount rather than a rate. */
  protected byAmount(line: LineGroup): boolean {
    this.revision();
    return line.controls.discountKind.value === 'amount';
  }

  /**
   * Discounts the line the other way, by a rate or by an amount; what was typed the first way goes, since 10 % is not
   * 10 dinars. The pressed button belongs to the field it replaces, so the new field takes the focus, named by its unit.
   */
  protected switchDiscount(line: LineGroup, index: number): void {
    const kind = line.controls.discountKind;
    kind.setValue(kind.value === 'rate' ? 'amount' : 'rate');
    kind.markAsDirty();
    afterNextRender(
      () =>
        this.host
          .querySelector<HTMLInputElement>(`[data-testid="line-${index}-discount"]`)
          ?.focus(),
      { injector: this.injector },
    );
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

  protected toggleReturned(line: LineGroup, checked: boolean): void {
    line.controls.returned.setValue(checked);
    line.controls.returned.markAsDirty();
  }
}

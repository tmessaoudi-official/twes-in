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
import { ReactiveFormsModule, type FormArray } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FieldRefusal } from '../shared/form/field-refusals';
import type { FieldError } from '../shared/form/form-builder';
import { LineRefusals, refusedOn } from '../shared/documents/line-refusals';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { DecimalInput } from '../shared/form/decimal-input';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { type LineGroup, lineGroup, plainDecimal } from './purchase-order-forms';
import { PurchasesFacade } from './purchases-facade';
import type { ProductOption } from './purchases-types';

/** The fields of a line a person fills in, each with its own message when it is wrong. */
type LineField = 'productId' | 'description' | 'quantity' | 'unitPriceNet';

/**
 * The lines of a draft order: a product, what it is called on the order, how many, and — for whoever may read costs —
 * what one should cost. A product chosen fills an empty description with its name and an empty price with its cost
 * today, so the usual order is a product and a quantity.
 */
@Component({
  selector: 'app-purchase-order-lines',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    TranslatePipe,
    DecimalInput,
    PickField,
  ],
  templateUrl: './purchase-order-lines.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PurchaseOrderLines {
  private readonly facade = inject(PurchasesFacade);
  private readonly translate = inject(TranslateService);

  readonly lines = input.required<FormArray<LineGroup>>();
  /** Whose company's catalogue the picker asks; a line is never offered another company's products. */
  readonly companyId = input.required<string>();
  /** Whether the price is shown and sent: a price on an order is a cost. */
  readonly seesCost = input(false);
  /** The products the saved order names, read by id, so a line shows its product before anything is searched. */
  readonly named = input<ReadonlyMap<string, ProductOption>>(new Map());
  /** The vendor's own code for each product the saved order names, which the API reads again on every save. */
  readonly vendorCodes = input<ReadonlyMap<string, string>>(new Map());
  /** Where the API refused the last save, by line from 0 and field. */
  readonly refused = input<{ line: number | null; field: string } | null>(null);

  /** Bumped on every value, status or touched change, so an OnPush template re-reads the lines. */
  private readonly revision = signal(0);
  /** Every product the picker has answered, so what is chosen on a line can be found again from its id. */
  private readonly found = new Map<string, ProductOption>();

  protected readonly groups = computed(() => {
    this.revision();
    return this.lines().controls;
  });

  constructor() {
    effect((onCleanup) => {
      const lines = this.lines();
      const subscription = lines.events.subscribe(() => this.revision.update((at) => at + 1));
      onCleanup(() => subscription.unsubscribe());
    });
  }

  protected readonly searchProducts = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.companyId();
    if (companyId === '') return [];
    const products = await this.facade.pickProducts(companyId, { words });
    for (const product of products) this.found.set(product.id, product);
    return products.map(option);
  };

  protected productOf(line: LineGroup): PickOption | null {
    this.revision();
    const product = this.product(line.controls.productId.value);
    return product === null ? null : option(product);
  }

  protected vendorCodeOf(line: LineGroup): string | null {
    this.revision();
    return this.vendorCodes().get(line.controls.productId.value) ?? null;
  }

  /** The unit a line's quantity counts, once its product is known. */
  protected unitOf(line: LineGroup): string {
    this.revision();
    return this.product(line.controls.productId.value)?.unitName ?? '';
  }

  protected chooseProduct(line: LineGroup, picked: PickOption | null): void {
    const before = this.product(line.controls.productId.value);
    const product = picked === null ? null : (this.found.get(picked.id) ?? null);
    line.controls.productId.setValue(product?.id ?? '');
    line.controls.productId.markAsTouched();
    const description = line.controls.description;
    // A description still saying the product chosen before is that product's name, not words a person wrote.
    if (product !== null && (description.value === '' || description.value === before?.name)) {
      description.setValue(product.name);
    }
    const price = line.controls.unitPriceNet;
    if (this.seesCost() && product?.costPrice != null && price.value === '') {
      price.setValue(plainDecimal(product.costPrice));
    }
    line.markAsDirty();
  }

  /**
   * What is said under a line's field: what the API refused it for, first, since it is the latest word on the line,
   * else what the form itself finds wrong once the field was left.
   */
  protected errorOf(line: LineGroup, field: LineField): FieldError | null {
    this.revision();
    const control = line.controls[field];
    const refused = refusedOn(control);
    if (refused !== null) return refused;
    return control.touched && control.invalid
      ? { key: `purchases.lines.errors.${field}`, params: {} }
      : null;
  }

  /**
   * Says each refusal of a line under its field and hands back the rest, the order's own; held until the next change
   * to the lines.
   */
  showRefused(refusals: readonly FieldRefusal[]): readonly FieldRefusal[] {
    return this.refusals.show(refusals);
  }

  private readonly refusals = new LineRefusals(
    () => this.lines(),
    this.translate,
    inject(RefusalFigures),
    () => this.revision.update((revision) => revision + 1),
  );

  /** The API's refusal on this line, named by its field's label, when no field of the line says it already. */
  protected refusedHere(index: number): string | null {
    this.revision();
    const refused = this.refused();
    if (refused === null || refused.line !== index) return null;
    const line = this.lines().at(index) as LineGroup | undefined;
    if (
      line !== undefined &&
      Object.values(line.controls).some((each) => refusedOn(each) !== null)
    ) {
      return null;
    }
    return LINE_FIELDS.includes(refused.field as LineField)
      ? `purchases.lines.${refused.field}`
      : 'purchases.lines.line';
  }

  protected add(): void {
    this.lines().push(lineGroup(null));
  }

  protected remove(index: number): void {
    this.lines().removeAt(index);
    this.lines().markAsDirty();
  }

  private product(id: string): ProductOption | null {
    if (id === '') return null;
    return this.found.get(id) ?? this.named().get(id) ?? null;
  }
}

const LINE_FIELDS: readonly LineField[] = ['productId', 'description', 'quantity', 'unitPriceNet'];

function option(product: ProductOption): PickOption {
  return { id: product.id, code: product.reference, name: product.name };
}

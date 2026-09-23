// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DOCUMENT,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { BarcodeSvg } from '../shared/barcode/barcode-svg';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { ProductsApi } from './products-api';
import type { BarcodeRole, ProductBarcode, ProductRow } from './products-types';

/** Which of a product's codes a label carries unless one is asked for: never a supplier's, which is theirs to print. */
const PRINTED_FIRST: readonly BarcodeRole[] = ['unit', 'internal', 'pack'];
const MOST_COPIES = 100;

/**
 * Printed product labels (docs/SPEC.md § 7, 2026-09-23 slice 8): the product's name and reference, what a customer
 * pays for what the code enters, taxes included (the price check's count), and the code as a barcode — EAN when it
 * is a GTIN, Code 128 otherwise — with the code written under it. The unit code unless another is asked for, as
 * many copies as asked. The browser prints; the controls stay off the paper.
 */
@Component({
  selector: 'app-product-labels-page',
  imports: [
    BarcodeSvg,
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    TranslatePipe,
  ],
  templateUrl: './product-labels-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductLabelsPage {
  private readonly api = inject(ProductsApi);
  private readonly session = inject(Session);
  private readonly format = inject(FormatFacade);
  private readonly document = inject(DOCUMENT);

  /** From the route, `/print/product-labels/:productId`. */
  readonly productId = input.required<string>();
  /** From the query, `?code=`: one of the product's codes to print instead of its unit code. */
  readonly code = input<string>();

  protected readonly product = signal<ProductRow | null>(null);
  protected readonly price = signal<string | null>(null);
  protected readonly copies = signal(1);

  protected readonly printed = computed((): ProductBarcode | null => {
    const barcodes = this.product()?.barcodes ?? [];
    const asked = barcodes.find((barcode) => barcode.code === this.code());
    if (asked) return asked;
    for (const role of PRINTED_FIRST) {
      const found = barcodes.find((barcode) => barcode.role === role);
      if (found) return found;
    }
    return null;
  });
  protected readonly labels = computed(() => Array.from({ length: this.copies() }, (_, i) => i));

  constructor() {
    effect(() => {
      const productId = this.productId();
      untracked(() => void this.load(productId));
    });
  }

  protected setCopies(value: unknown): void {
    const copies = Math.trunc(Number(value));
    this.copies.set(Number.isFinite(copies) ? Math.min(Math.max(copies, 1), MOST_COPIES) : 1);
  }

  protected print(): void {
    this.document.defaultView?.print();
  }

  private async load(productId: string): Promise<void> {
    const company = this.session.me()?.company;
    if (!company) return;
    this.product.set(await this.api.product(company.id, productId));
    const printed = this.printed();
    if (printed === null) return;
    const scan = await this.api.scan(company.id, printed.code);
    this.price.set(
      scan === null
        ? null
        : `${this.format.amount(scan.priceGross, null)} ${company.currency}`.trim(),
    );
  }
}

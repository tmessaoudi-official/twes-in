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
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { labelCode, MOST_LABEL_COPIES, ProductLabel } from './product-label';
import { ProductsApi } from './products-api';
import type { ProductBarcode, ProductRow } from './products-types';

/**
 * Printed product labels: the product's name and reference, what a customer
 * pays for what the code enters, taxes included (the price check's count), and the code as a barcode — EAN when it
 * is a GTIN, Code 128 otherwise — with the code written under it. The unit code unless another is asked for, as
 * many copies as asked; every code of the product can be ticked, each printing at its own price and with the pieces it
 * stands for. The browser prints; the controls stay off the paper.
 */
@Component({
  selector: 'app-product-labels-page',
  imports: [
    FormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    ProductLabel,
    RouterLink,
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
  /** What a customer pays for what each code enters, by code; a code the shelf does not answer to has none. */
  protected readonly prices = signal<Readonly<Record<string, string>>>({});
  /** The codes the person ticked; until they tick one, the default code alone. */
  private readonly ticked = signal<readonly string[] | null>(null);
  protected readonly copies = signal(1);

  protected readonly printed = computed((): ProductBarcode | null => {
    const barcodes = this.product()?.barcodes ?? [];
    const asked = barcodes.find((barcode) => barcode.code === this.code());
    return asked ?? labelCode(barcodes);
  });
  protected readonly codes = computed(() => this.product()?.barcodes ?? []);
  /** What is printed: the ticked codes in the order the product holds them, else the default one. */
  protected readonly chosen = computed((): readonly ProductBarcode[] => {
    const ticked = this.ticked();
    if (ticked === null) {
      const first = this.printed();
      return first === null ? [] : [first];
    }
    return this.codes().filter((barcode) => ticked.includes(barcode.code));
  });
  protected readonly labels = computed(() => Array.from({ length: this.copies() }, (_, i) => i));

  protected isChosen(barcode: ProductBarcode): boolean {
    return this.chosen().some((held) => held.code === barcode.code);
  }

  protected tick(barcode: ProductBarcode, on: boolean): void {
    const now = this.chosen().map((held) => held.code);
    this.ticked.set(
      on
        ? [...now.filter((code) => code !== barcode.code), barcode.code]
        : now.filter((code) => code !== barcode.code),
    );
  }

  constructor() {
    effect(() => {
      const productId = this.productId();
      untracked(() => void this.load(productId));
    });
  }

  protected setCopies(value: unknown): void {
    const copies = Math.trunc(Number(value));
    this.copies.set(Number.isFinite(copies) ? Math.min(Math.max(copies, 1), MOST_LABEL_COPIES) : 1);
  }

  protected print(): void {
    this.document.defaultView?.print();
  }

  private async load(productId: string): Promise<void> {
    const company = this.session.me()?.company;
    if (!company) return;
    this.product.set(await this.api.product(company.id, productId));
    const prices: Record<string, string> = {};
    for (const barcode of this.codes()) {
      const scan = await this.api.scan(company.id, barcode.code);
      if (scan !== null) {
        prices[barcode.code] =
          `${this.format.amount(scan.priceGross, null)} ${company.currency}`.trim();
      }
    }
    this.prices.set(prices);
  }
}
